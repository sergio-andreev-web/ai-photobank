from __future__ import annotations

import time

from fastapi import APIRouter, Depends, File, Form, Request, UploadFile
from pydantic import ValidationError

from ..concurrency import InferenceLimiter
from ..config import Settings
from ..inference import translate_model_errors
from ..models.registry import ModelRegistry
from ..preprocessing import preprocess_image
from ..schemas.analysis import AnalysisModels, ImageAnalysisResponse
from ..schemas.common import is_valid_request_id
from ..schemas.errors import InvalidRequestError, ModelNotReadyError, ModelOutputInvalidError
from ..security import require_api_key

router = APIRouter(tags=["inference"])


@router.post("/v1/images/analyze", response_model=ImageAnalysisResponse)
def analyze_image(
    request: Request,
    image: UploadFile | None = File(None),
    request_id: str | None = Form(None),
    _auth: None = Depends(require_api_key),
) -> ImageAnalysisResponse:
    settings: Settings = request.app.state.settings
    registry: ModelRegistry = request.app.state.registry
    limiter: InferenceLimiter = request.app.state.limiter

    missing_fields = {}
    if image is None:
        missing_fields["image"] = ["required"]
    if not request_id:
        missing_fields["request_id"] = ["required"]
    if missing_fields:
        raise InvalidRequestError(
            'Field "image" is required' if "image" in missing_fields else 'Field "request_id" is required',
            details={"fields": missing_fields},
        )

    if not is_valid_request_id(request_id):
        raise InvalidRequestError(
            "Field \"request_id\" has invalid format",
            details={"fields": {"request_id": ["format"]}},
            request_id=None,
        )

    if not registry.ready:
        raise ModelNotReadyError(request_id=request_id)

    data = image.file.read()

    processed = preprocess_image(
        data,
        max_bytes=settings.ai_max_image_bytes,
        max_dimension=settings.ai_max_image_dimension,
        request_id=request_id,
    )

    with limiter.slot(request_id=request_id):
        started = time.monotonic()
        with translate_model_errors(request_id=request_id, prompt_version=settings.prompt_version):
            vision_result = registry.vision_model.analyze(processed)
            embedding = registry.embedding_model.image_embedding(processed)
        processing_ms = int((time.monotonic() - started) * 1000)

    payload = {
        "request_id": request_id,
        "caption": vision_result.caption,
        "ocr": vision_result.ocr,
        "concepts": vision_result.concepts,
        "embedding": embedding,
        "embedding_dimensions": len(embedding),
        "models": AnalysisModels(
            vision=registry.settings.vision_model,
            embedding=registry.settings.embedding_model,
            vision_revision=registry.vision_model.revision,
            embedding_revision=registry.embedding_model.revision,
        ),
        "prompt_version": settings.prompt_version,
        "processing_ms": processing_ms,
    }

    try:
        return ImageAnalysisResponse(**payload)
    except ValidationError:
        raise ModelOutputInvalidError(settings.prompt_version, request_id=request_id) from None
