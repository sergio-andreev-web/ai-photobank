# AI Photobank — AI service (FastAPI).
#
# Default build = CPU/mock profile for CI, contract and smoke tests (§8, §73).
# CUDA/RunPod build (§67): --build-arg INSTALL_MODEL_DEPS=true and run with
# AI_MODEL_PROVIDER=transformers DEVICE=cuda.
#
# Model weights are never baked into the image (§68): they are downloaded on
# first start into HF_HOME, which must be a mounted persistent volume.
FROM python:3.12-slim

WORKDIR /srv/ai

ARG INSTALL_MODEL_DEPS=false

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    AI_ENVIRONMENT=production \
    HF_HOME=/models/huggingface

COPY requirements.txt requirements-model.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && if [ "$INSTALL_MODEL_DEPS" = "true" ]; then pip install --no-cache-dir -r requirements-model.txt; fi

COPY app ./app
COPY prompts ./prompts

VOLUME ["/models"]
EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
