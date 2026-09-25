from __future__ import annotations

from fastapi import APIRouter, Depends, Request
from pydantic import ValidationError

from ..concurrency import InferenceLimiter
from ..config import Settings
from ..models.registry import ModelRegistry
from ..schemas.embeddings import TextEmbeddingsRequest, TextEmbeddingsResponse
from ..schemas.errors import InternalError, ModelNotReadyError
from ..security import require_api_key

router = APIRouter(tags=["inference"])


@router.post("/v1/embeddings/text", response_model=TextEmbeddingsResponse)
def create_text_embeddings(
    request: Request,
    body: TextEmbeddingsRequest,
    _auth: None = Depends(require_api_key),
) -> TextEmbeddingsResponse:
    settings: Settings = request.app.state.settings
    registry: ModelRegistry = request.app.state.registry
    limiter: InferenceLimiter = request.app.state.limiter
    request_id = getattr(request.state, "request_id", None)

    if not registry.ready:
        raise ModelNotReadyError(request_id=request_id)

    with limiter.slot(request_id=request_id):
        embeddings = registry.embedding_model.text_embeddings(body.texts)

    payload = {
        "model": settings.embedding_model,
        "revision": registry.embedding_model.revision,
        "dimensions": len(embeddings[0]) if embeddings else 1152,
        "embeddings": embeddings,
    }

    try:
        return TextEmbeddingsResponse(**payload)
    except ValidationError:
        raise InternalError("Embedding model returned invalid output", request_id=request_id) from None
