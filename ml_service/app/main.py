import logging
import os
from collections.abc import Callable
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, HTTPException, Request
from pydantic import BaseModel, Field

MODEL_NAME = os.getenv("BERT_MODEL_NAME", "Juanillaberia/spanish-fake-news-classifier")
logger = logging.getLogger(__name__)


class ClassificationRequest(BaseModel):
    title: str = Field(default="", max_length=255)
    content: str = Field(default="", max_length=65_000)
    description: str = Field(default="", max_length=65_000)
    model: str | None = Field(default=None, max_length=255)


class ClassificationResponse(BaseModel):
    label: str
    confidence: float
    probability: float
    model: str


def normalize_label(raw_label: Any) -> str:
    label = str(raw_label).strip()
    upper = label.upper()

    if upper in {"REAL", "TRUE"}:
        return "REAL"
    if upper in {"FAKE", "FALSE"}:
        return "FAKE"
    if upper in {"LABEL_0", "LABEL_0_FAKE", "FAKE_NEWS"}:
        return "FAKE"
    if upper in {"LABEL_1", "LABEL_1_REAL", "REAL_NEWS"}:
        return "REAL"
    if label in {"0", "0.0"}:
        return "FAKE"
    if label in {"1", "1.0"}:
        return "REAL"

    if "FAKE" in upper:
        return "FAKE"
    if "REAL" in upper:
        return "REAL"

    raise ValueError(f"Unsupported label: {raw_label}")


def extract_prediction(payload: Any) -> tuple[str, float]:
    if isinstance(payload, list):
        if payload and isinstance(payload[0], list):
            payload = payload[0]
        if payload and isinstance(payload[0], dict):
            item = payload[0]
            return normalize_label(item.get("label", "")), float(item.get("score", 0.0))
        if payload and isinstance(payload[0], (str, int, float)):
            label = payload[0]
            return normalize_label(label), 0.0

    if isinstance(payload, dict):
        return normalize_label(payload.get("label", "")), float(payload.get("score", 0.0))

    raise ValueError("Unexpected model response format")


def load_classifier() -> Callable[..., Any]:
    import torch
    from transformers import pipeline

    model_device = 0 if torch.cuda.is_available() else -1

    return pipeline(
        "text-classification",
        model=MODEL_NAME,
        tokenizer=MODEL_NAME,
        device=model_device,
        top_k=None,
    )


def create_app(classifier_loader: Callable[[], Callable[..., Any]] = load_classifier) -> FastAPI:
    @asynccontextmanager
    async def lifespan(application: FastAPI):
        application.state.classifier = classifier_loader()
        yield

    application = FastAPI(
        title="BERT Fake News Classifier",
        version="1.0.0",
        lifespan=lifespan,
    )

    @application.get("/health")
    def health() -> dict[str, Any]:
        return {"status": "ok", "model": MODEL_NAME}

    @application.post("/classify", response_model=ClassificationResponse)
    def classify(
        request: ClassificationRequest,
        app_request: Request,
    ) -> ClassificationResponse:
        text = " ".join(
            part for part in [request.title, request.description, request.content] if part
        )
        if not text:
            raise HTTPException(status_code=400, detail="Text payload is empty.")

        try:
            result = app_request.app.state.classifier(text, truncation=True, max_length=512)
            label, confidence = extract_prediction(result)
            model_name = request.model or MODEL_NAME
            return ClassificationResponse(
                label=label,
                confidence=float(confidence),
                probability=float(confidence),
                model=model_name,
            )
        except ValueError as exc:
            raise HTTPException(status_code=422, detail=str(exc)) from exc
        except Exception as exc:
            logger.exception("Classification failed")
            raise HTTPException(status_code=500, detail="Classification failed.") from exc

    return application


app = create_app()


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("app.main:app", host="0.0.0.0", port=8000, reload=False)
