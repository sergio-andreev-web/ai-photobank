# AI Photobank — AI service (FastAPI + mock models).
# Model weights are never baked into the image (§68): HF_HOME must point at a
# mounted cache volume once real SigLIP2/Qwen3-VL providers are added.
FROM python:3.12-slim

WORKDIR /srv/ai

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    AI_ENVIRONMENT=production

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY prompts ./prompts

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
