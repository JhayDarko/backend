from collections.abc import Callable
from typing import Any

import pytest
from app.main import MODEL_NAME, create_app
from fastapi.testclient import TestClient


def client_for(
    classifier: Callable[..., Any] | None = None,
    *,
    loader_error: Exception | None = None,
) -> TestClient:
    def load_classifier() -> Callable[..., Any]:
        if loader_error:
            raise loader_error
        if classifier is None:
            raise AssertionError("A test classifier must be provided.")
        return classifier

    return TestClient(create_app(load_classifier), raise_server_exceptions=False)


def test_health_contract_does_not_load_real_model() -> None:
    with client_for(lambda *_args, **_kwargs: []) as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "model": MODEL_NAME}


def test_classify_returns_the_expected_contract_and_calls_classifier() -> None:
    calls: list[tuple[str, dict[str, Any]]] = []

    def classifier(text: str, **kwargs: Any) -> list[dict[str, Any]]:
        calls.append((text, kwargs))
        return [{"label": "REAL", "score": 0.91}]

    with client_for(classifier) as client:
        response = client.post(
            "/classify",
            json={
                "title": "Titular",
                "description": "Descripción",
                "content": "Contenido de la noticia",
                "model": "test-model",
            },
        )

    assert response.status_code == 200
    assert response.json() == {
        "label": "REAL",
        "confidence": 0.91,
        "probability": 0.91,
        "model": "test-model",
    }
    assert calls == [
        (
            "Titular Descripción Contenido de la noticia",
            {"truncation": True, "max_length": 512},
        )
    ]


def test_classify_rejects_empty_text_without_calling_classifier() -> None:
    def classifier(*_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        pytest.fail("The classifier must not run for empty text.")

    with client_for(classifier) as client:
        response = client.post("/classify", json={})

    assert response.status_code == 400
    assert response.json()["detail"] == "Text payload is empty."


@pytest.mark.parametrize(
    "payload",
    [
        {"content": "x" * 65_001},
        {"description": "x" * 65_001},
        {"title": 42},
        {"content": ["not", "text"]},
        {"model": 42},
    ],
)
def test_classify_rejects_invalid_types_and_oversized_fields(
    payload: dict[str, Any],
) -> None:
    with client_for(lambda *_args, **_kwargs: []) as client:
        response = client.post("/classify", json=payload)

    assert response.status_code == 422


def test_classify_returns_validation_error_for_an_unsupported_model_label() -> None:
    with client_for(lambda *_args, **_kwargs: [{"label": "UNKNOWN", "score": 0.5}]) as client:
        response = client.post("/classify", json={"content": "Noticia de prueba"})

    assert response.status_code == 422
    assert "Unsupported label" in response.json()["detail"]


def test_model_initialization_failure_fails_readiness_without_downloading_weights() -> None:
    with pytest.raises(RuntimeError, match="model storage detail"):
        with client_for(loader_error=RuntimeError("model storage detail")):
            pass


def test_classification_failure_returns_generic_server_error() -> None:
    def classifier(*_args: Any, **_kwargs: Any) -> list[dict[str, Any]]:
        raise RuntimeError("private model backend detail")

    with client_for(classifier) as client:
        response = client.post("/classify", json={"content": "Noticia de prueba"})

    assert response.status_code == 500
    assert response.json() == {"detail": "Classification failed."}
    assert "private model backend detail" not in response.text
