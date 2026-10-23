import base64
import binascii
import json
import logging
import threading
from contextlib import asynccontextmanager
from functools import lru_cache
from io import BytesIO
from typing import Any

import numpy as np
from fastapi import FastAPI, HTTPException
from PIL import Image, UnidentifiedImageError
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

MAX_IMAGE_BYTES = 5 * 1024 * 1024
MAX_IMAGE_PIXELS = 40_000_000
Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS

_inference_lock = threading.Lock()


@lru_cache(maxsize=1)
def get_ocr_engine() -> Any:
    from paddleocr import PaddleOCR

    return PaddleOCR(
        use_doc_orientation_classify=False,
        use_doc_unwarping=False,
        use_textline_orientation=False,
        engine="paddle",
    )


@asynccontextmanager
async def lifespan(_: FastAPI):
    await run_in_threadpool(get_ocr_engine)
    yield


app = FastAPI(title="FactNode Local PaddleOCR", version="1.0.0", lifespan=lifespan)


class OCRRequest(BaseModel):
    image_base64: str = Field(min_length=1, max_length=7_000_000)


class OCRLine(BaseModel):
    text: str
    box: list[int] | None
    score: float | None


class OCRResponse(BaseModel):
    text: str
    lines: list[OCRLine]
    width: int
    height: int
    model: str = "PaddleOCR"


def decode_image(encoded_image: str) -> bytes:
    try:
        image_bytes = base64.b64decode(encoded_image, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise HTTPException(status_code=422, detail="Image payload is not valid base64.") from exc

    if not image_bytes:
        raise HTTPException(status_code=422, detail="Image payload is empty.")
    if len(image_bytes) > MAX_IMAGE_BYTES:
        raise HTTPException(status_code=413, detail="Image exceeds the 5 MB limit.")

    return image_bytes


def extract_lines_from_results(results: Any) -> list[OCRLine]:
    lines: list[OCRLine] = []

    for result in results:
        payload = getattr(result, "json", result)
        if callable(payload):
            payload = payload()
        if isinstance(payload, str):
            payload = json.loads(payload)
        if not isinstance(payload, dict):
            raise ValueError("PaddleOCR returned an unsupported result.")

        data = payload.get("res", payload)
        if not isinstance(data, dict):
            raise ValueError("PaddleOCR returned an unsupported result.")

        recognized_texts = data.get("rec_texts", [])
        if not isinstance(recognized_texts, list):
            raise ValueError("PaddleOCR returned malformed recognized text.")

        boxes = data.get("rec_boxes", [])
        scores = data.get("rec_scores", [])
        if not isinstance(boxes, list) or not isinstance(scores, list):
            raise ValueError("PaddleOCR returned malformed line geometry.")

        for index, raw_text in enumerate(recognized_texts):
            if not isinstance(raw_text, str) or not raw_text.strip():
                continue

            box = None
            if index < len(boxes):
                candidate = boxes[index]
                if hasattr(candidate, "tolist"):
                    candidate = candidate.tolist()
                if isinstance(candidate, (list, tuple)) and len(candidate) == 4:
                    try:
                        box = [int(coordinate) for coordinate in candidate]
                    except (TypeError, ValueError):
                        box = None

            score = None
            if index < len(scores):
                try:
                    score = float(scores[index])
                except (TypeError, ValueError):
                    score = None

            text = raw_text.strip()
            lines.append(
                OCRLine(
                    text=text,
                    box=box,
                    score=score,
                )
            )

    return sorted(
        lines,
        key=lambda line: (line.box[1], line.box[0]) if line.box else (0, 0),
    )


def extract_text_from_results(results: Any) -> str:
    return "\n".join(line.text for line in extract_lines_from_results(results))


def recognize_image(image_bytes: bytes) -> OCRResponse:
    try:
        with Image.open(BytesIO(image_bytes)) as image:
            if image.width * image.height > MAX_IMAGE_PIXELS:
                raise HTTPException(
                    status_code=413, detail="Image resolution exceeds the supported limit."
                )
            image.load()
            image_array = np.asarray(image.convert("RGB"))
    except HTTPException:
        raise
    except (UnidentifiedImageError, OSError, Image.DecompressionBombError) as exc:
        raise HTTPException(
            status_code=422, detail="Uploaded file is not a supported image."
        ) from exc

    try:
        with _inference_lock:
            results = get_ocr_engine().predict(image_array)
        lines = extract_lines_from_results(results)
        return OCRResponse(
            text="\n".join(line.text for line in lines),
            lines=lines,
            width=image_array.shape[1],
            height=image_array.shape[0],
        )
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("PaddleOCR inference failed")
        raise HTTPException(status_code=500, detail="Local OCR inference failed.") from exc


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "model": "PaddleOCR"}


@app.post("/ocr", response_model=OCRResponse)
def ocr(request: OCRRequest) -> OCRResponse:
    image_bytes = decode_image(request.image_base64)

    return recognize_image(image_bytes)
