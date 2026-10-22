import base64
import unittest
from io import BytesIO

from fastapi import HTTPException
from PIL import Image

from app.main import decode_image, extract_lines_from_results, extract_text_from_results, recognize_image


class OCRResult:
    def __init__(self, payload: dict):
        self.json = {"res": payload}


class OCRServiceTests(unittest.TestCase):
    def test_extract_text_from_paddle_result_preserves_reading_order(self) -> None:
        result = OCRResult({"rec_texts": [" Titular ", "", "Texto de la noticia"]})

        self.assertEqual(
            extract_text_from_results([result]),
            "Titular\nTexto de la noticia",
        )

    def test_extract_text_accepts_json_result_payload(self) -> None:
        self.assertEqual(
            extract_text_from_results([{"rec_texts": ["Línea uno", "Línea dos"]}]),
            "Línea uno\nLínea dos",
        )

    def test_extract_lines_preserves_boxes_and_scores(self) -> None:
        lines = extract_lines_from_results(
            [
                {
                    "rec_texts": ["@cuenta", "Titular de la noticia"],
                    "rec_boxes": [[12, 5, 80, 28], [10, 60, 240, 96]],
                    "rec_scores": [0.99, 0.95],
                }
            ]
        )

        self.assertEqual(
            [(line.text, line.box) for line in lines],
            [
                ("@cuenta", [12, 5, 80, 28]),
                ("Titular de la noticia", [10, 60, 240, 96]),
            ],
        )
        self.assertEqual(lines[0].score, 0.99)

    def test_extract_lines_sorts_by_position_and_handles_missing_boxes(self) -> None:
        lines = extract_lines_from_results(
            [
                {
                    "rec_texts": ["Abajo", "Arriba"],
                    "rec_boxes": [[20, 80, 100, 100], [20, 10, 100, 30]],
                }
            ]
        )

        self.assertEqual([line.text for line in lines], ["Arriba", "Abajo"])
        self.assertEqual(extract_lines_from_results([{"rec_texts": ["Sin caja"]}])[0].box, None)

    def test_decode_image_rejects_invalid_base64(self) -> None:
        with self.assertRaises(HTTPException) as raised:
            decode_image("not base64!")

        self.assertEqual(raised.exception.status_code, 422)

    def test_recognize_image_rejects_unsupported_image_data(self) -> None:
        with self.assertRaises(HTTPException) as raised:
            recognize_image(b"not an image")

        self.assertEqual(raised.exception.status_code, 422)

    def test_decode_image_accepts_jpeg_bytes(self) -> None:
        buffer = BytesIO()
        Image.new("RGB", (32, 32), color="white").save(buffer, format="JPEG")
        encoded = base64.b64encode(buffer.getvalue()).decode("ascii")

        self.assertEqual(decode_image(encoded), buffer.getvalue())


if __name__ == "__main__":
    unittest.main()
