FROM python:3.11-slim

WORKDIR /app

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PADDLE_PDX_CACHE_HOME=/home/appuser/.paddlex \
    OMP_NUM_THREADS=2

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    libgomp1 \
    libgl1 \
    libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./requirements.txt
RUN pip install --no-cache-dir --require-hashes -r requirements.txt

ENV PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT=false

RUN useradd --create-home --uid 10001 appuser \
    && mkdir -p /home/appuser/.paddlex \
    && chown -R appuser:appuser /home/appuser

COPY --chown=appuser:appuser app ./app
COPY --chown=appuser:appuser tests ./tests

EXPOSE 8000

USER appuser

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
