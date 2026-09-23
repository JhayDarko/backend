import os
from typing import Any, Optional

import torch
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from transformers import pipeline

MODEL_NAME = os.getenv("BERT_MODEL_NAME", "Juanillaberia/spanish-fake-news-classifier")
MODEL_DEVICE = 0 if torch.cuda.is_available() else -1

app = FastAPI(title="BERT Fake News Classifier", version="1.0.0")

classifier = pipeline(
    "text-classification",
    model=MODEL_NAME,
    tokenizer=MODEL_NAME,
    device=MODEL_DEVICE,
    top_k=None,
)


class ClassificationRequest(BaseModel):
    title: str = ""
    content: str = ""
    description: str = ""
    model: Optional[str] = None


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


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "ok", "model": MODEL_NAME}


@app.post("/classify", response_model=ClassificationResponse)
def classify(request: ClassificationRequest) -> ClassificationResponse:
    text = " ".join(part for part in [request.title, request.description, request.content] if part).strip()

    if not text:
        raise HTTPException(status_code=400, detail="Text payload is empty.")

    try:
        result = classifier(text)
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
    except Exception as exc:  # pragma: no cover
        raise HTTPException(status_code=500, detail=f"Classification failed: {exc}") from exc


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("app.main:app", host="0.0.0.0", port=8000, reload=False)
