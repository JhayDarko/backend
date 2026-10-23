import base64
from io import BytesIO
from typing import Any

import app.main as ocr_main
import pytest
from fastapi.testclient import TestClient
from PIL import Image


class FakeOcrEngine:
    def predict(self, image: Any) -> list[dict[str, Any]]:
        assert image.shape == (32, 48, 3)
        return [
            {
                "res": {
                    "rec_texts": ["Titular", "Texto de la noticia"],
                    "rec_boxes": [[2, 3, 20, 10], [2, 15, 40, 25]],
                    "rec_scores": [0.99, 0.95],
                }
            }
        ]


def encode_image(width: int = 48, height: int = 32) -> str:
    buffer = BytesIO()
    Image.new("RGB", (width, height), color="white").save(buffer, format="PNG")
    return base64.b64encode(buffer.getvalue()).decode("ascii")


@pytest.fixture
def client(monkeypatch: pytest.MonkeyPatch) -> TestClient:
    monkeypatch.setattr(ocr_main, "get_ocr_engine", lambda: FakeOcrEngine())
    return TestClient(ocr_main.app, raise_server_exceptions=False)


def test_health_contract(client: TestClient) -> None:
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "model": "PaddleOCR"}


def test_ocr_endpoint_returns_recognized_text_and_geometry(client: TestClient) -> None:
    response = client.post("/ocr", json={"image_base64": encode_image()})

    assert response.status_code == 200
    assert response.json() == {
        "text": "Titular\nTexto de la noticia",
        "lines": [
            {"text": "Titular", "box": [2, 3, 20, 10], "score": 0.99},
            {"text": "Texto de la noticia", "box": [2, 15, 40, 25], "score": 0.95},
        ],
        "width": 48,
        "height": 32,
        "model": "PaddleOCR",
    }


@pytest.mark.parametrize("encoded", ["", "not base64!"])
def test_ocr_endpoint_rejects_empty_or_invalid_base64(
    client: TestClient,
    encoded: str,
) -> None:
    response = client.post("/ocr", json={"image_base64": encoded})

    assert response.status_code == 422


def test_ocr_endpoint_rejects_invalid_image_data(client: TestClient) -> None:
    encoded = base64.b64encode(b"not an image").decode("ascii")

    response = client.post("/ocr", json={"image_base64": encoded})

    assert response.status_code == 422
    assert response.json()["detail"] == "Uploaded file is not a supported image."


def test_ocr_endpoint_enforces_the_five_megabyte_payload_limit(client: TestClient) -> None:
    oversized_image = b"x" * (ocr_main.MAX_IMAGE_BYTES + 1)
    encoded = base64.b64encode(oversized_image).decode("ascii")

    response = client.post("/ocr", json={"image_base64": encoded})

    assert response.status_code == 413
    assert response.json()["detail"] == "Image exceeds the 5 MB limit."
