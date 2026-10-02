# Keelwatch worker: claims jobs from MySQL and runs analysis, digests and
# notifications. No ports. Scale with: docker compose up -d --scale worker=N
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN groupadd --system --gid 10001 keelwatch \
 && useradd --system --uid 10001 --gid 10001 --no-create-home keelwatch
WORKDIR /app/worker
COPY worker/requirements.txt .
RUN pip install --no-cache-dir --upgrade pip \
 && pip install --no-cache-dir -r requirements.txt
COPY worker/keelwatch_worker ./keelwatch_worker

USER keelwatch
HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
    CMD ["python", "-m", "keelwatch_worker", "--check"]
CMD ["python", "-m", "keelwatch_worker"]
