# syntax=docker/dockerfile:1.4
FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN --mount=type=cache,target=/root/.cache/pip \
    pip install \
    --default-timeout=180 \
    --retries=15 \
    -r requirements.txt

COPY predict_api.py .
COPY modelo_gradient_boosting.joblib .

EXPOSE 5000

CMD ["gunicorn", "--bind", "0.0.0.0:5000", "--workers", "2", "--timeout", "60", "predict_api:app"]
