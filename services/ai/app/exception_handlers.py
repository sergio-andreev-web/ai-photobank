"""Central mapping of every non-2xx response onto schemas/ai-error.json.

FastAPI's default validation error body ({"detail": [...]}) is explicitly
disallowed by the contract, so RequestValidationError is remapped here too.
"""
from __future__ import annotations

import logging

from fastapi import Request
from fastapi.exceptions import RequestValidationError
from starlette.responses import JSONResponse

from .schemas.errors import AiErrorCode, AiServiceError, InternalError

logger = logging.getLogger("ai_service.errors")


def _request_id_from_request(request: Request) -> str | None:
    return getattr(request.state, "request_id", None) or request.headers.get("X-Request-ID")


def _json_error_response(error: AiServiceError) -> JSONResponse:
    headers = {}
    if error.retry_after is not None:
        headers["Retry-After"] = str(error.retry_after)
    return JSONResponse(
        status_code=error.http_status,
        content=error.to_body().model_dump(mode="json"),
        headers=headers,
    )


async def ai_service_error_handler(request: Request, exc: AiServiceError) -> JSONResponse:
    if exc.code in (AiErrorCode.INFERENCE_FAILED, AiErrorCode.INTERNAL_ERROR):
        logger.error("ai_service.error", extra={"error_code": exc.code.value, "error_message": exc.message})
    return _json_error_response(exc)


async def validation_error_handler(request: Request, exc: RequestValidationError) -> JSONResponse:
    # /v1/embeddings/text body validation -> 422 INVALID_REQUEST (contract).
    fields: dict[str, list[str]] = {}
    for err in exc.errors():
        loc = ".".join(str(part) for part in err.get("loc", ()) if part not in ("body",))
        fields.setdefault(loc or "body", []).append(err.get("type", "invalid"))

    error = AiServiceError(
        AiErrorCode.INVALID_REQUEST,
        "Request validation failed",
        details={"fields": fields},
        request_id=_request_id_from_request(request),
        http_status=422,
    )
    return _json_error_response(error)


async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
    logger.exception("ai_service.unhandled_exception")
    error = InternalError(request_id=_request_id_from_request(request))
    return _json_error_response(error)
