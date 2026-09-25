"""contracts/schemas/ai-error.json"""
from __future__ import annotations

from enum import Enum

from pydantic import Field

from .common import StrictModel


class AiErrorCode(str, Enum):
    """x-known-codes from contracts/schemas/ai-error.json."""

    INVALID_REQUEST = "INVALID_REQUEST"
    UNAUTHORIZED = "UNAUTHORIZED"
    IMAGE_TOO_LARGE = "IMAGE_TOO_LARGE"
    UNSUPPORTED_MEDIA_TYPE = "UNSUPPORTED_MEDIA_TYPE"
    IMAGE_DECODE_FAILED = "IMAGE_DECODE_FAILED"
    SERVICE_BUSY = "SERVICE_BUSY"
    INFERENCE_FAILED = "INFERENCE_FAILED"
    INTERNAL_ERROR = "INTERNAL_ERROR"
    MODEL_OUTPUT_INVALID = "MODEL_OUTPUT_INVALID"
    MODEL_NOT_READY = "MODEL_NOT_READY"


# HTTP status used for each code when the AI service itself raises it.
# (contracts/ai-api.openapi.yaml responses; ai-error.json x-known-codes lists the
# full allowed set per code where more than one status applies.)
ERROR_HTTP_STATUS: dict[AiErrorCode, int] = {
    AiErrorCode.INVALID_REQUEST: 400,
    AiErrorCode.UNAUTHORIZED: 401,
    AiErrorCode.IMAGE_TOO_LARGE: 413,
    AiErrorCode.UNSUPPORTED_MEDIA_TYPE: 415,
    AiErrorCode.IMAGE_DECODE_FAILED: 422,
    AiErrorCode.SERVICE_BUSY: 429,
    AiErrorCode.INFERENCE_FAILED: 500,
    AiErrorCode.INTERNAL_ERROR: 500,
    AiErrorCode.MODEL_OUTPUT_INVALID: 502,
    AiErrorCode.MODEL_NOT_READY: 503,
}

RETRYABLE_CODES = {
    AiErrorCode.SERVICE_BUSY,
    AiErrorCode.INFERENCE_FAILED,
    AiErrorCode.INTERNAL_ERROR,
    AiErrorCode.MODEL_OUTPUT_INVALID,
    AiErrorCode.MODEL_NOT_READY,
}


class AiErrorDetail(StrictModel):
    code: AiErrorCode
    message: str
    details: dict = Field(default_factory=dict)
    request_id: str | None = None


class AiErrorBody(StrictModel):
    error: AiErrorDetail


class AiServiceError(Exception):
    """Base class for errors that map directly to an AiErrorBody response."""

    def __init__(
        self,
        code: AiErrorCode,
        message: str,
        *,
        details: dict | None = None,
        request_id: str | None = None,
        retry_after: int | None = None,
        http_status: int | None = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.details = details or {}
        self.request_id = request_id
        self.retry_after = retry_after
        self.http_status = http_status or ERROR_HTTP_STATUS[code]

    def to_body(self) -> AiErrorBody:
        return AiErrorBody(
            error=AiErrorDetail(
                code=self.code,
                message=self.message,
                details=self.details,
                request_id=self.request_id,
            )
        )


class InvalidRequestError(AiServiceError):
    def __init__(self, message: str, *, details: dict | None = None, request_id: str | None = None):
        super().__init__(AiErrorCode.INVALID_REQUEST, message, details=details, request_id=request_id)


class InvalidRequestError422(AiServiceError):
    """INVALID_REQUEST reported with HTTP 422 (used by /v1/embeddings/text)."""

    def __init__(self, message: str, *, details: dict | None = None, request_id: str | None = None):
        super().__init__(
            AiErrorCode.INVALID_REQUEST,
            message,
            details=details,
            request_id=request_id,
            http_status=422,
        )


class UnauthorizedError(AiServiceError):
    def __init__(self, message: str = "Invalid service API key"):
        super().__init__(AiErrorCode.UNAUTHORIZED, message, request_id=None)


class ImageTooLargeError(AiServiceError):
    def __init__(self, max_bytes: int, *, request_id: str | None = None):
        super().__init__(
            AiErrorCode.IMAGE_TOO_LARGE,
            "Image exceeds maximum size",
            details={"max_bytes": max_bytes},
            request_id=request_id,
        )


class UnsupportedMediaTypeError(AiServiceError):
    def __init__(self, detected_mime: str, *, request_id: str | None = None):
        super().__init__(
            AiErrorCode.UNSUPPORTED_MEDIA_TYPE,
            "Unsupported image type",
            details={"detected_mime": detected_mime},
            request_id=request_id,
        )


class ImageDecodeFailedError(AiServiceError):
    def __init__(self, *, request_id: str | None = None):
        super().__init__(
            AiErrorCode.IMAGE_DECODE_FAILED,
            "Cannot decode image",
            request_id=request_id,
        )


class ServiceBusyError(AiServiceError):
    def __init__(self, retry_after: int = 1, *, request_id: str | None = None):
        super().__init__(
            AiErrorCode.SERVICE_BUSY,
            "Inference queue is full",
            request_id=request_id,
            retry_after=retry_after,
        )


class InferenceFailedError(AiServiceError):
    def __init__(self, message: str = "Inference failed", *, request_id: str | None = None):
        super().__init__(AiErrorCode.INFERENCE_FAILED, message, request_id=request_id)


class InternalError(AiServiceError):
    def __init__(self, message: str = "Internal error", *, request_id: str | None = None):
        super().__init__(AiErrorCode.INTERNAL_ERROR, message, request_id=request_id)


class ModelOutputInvalidError(AiServiceError):
    def __init__(self, prompt_version: str, *, request_id: str | None = None):
        super().__init__(
            AiErrorCode.MODEL_OUTPUT_INVALID,
            "Vision model returned invalid structured output",
            details={"prompt_version": prompt_version},
            request_id=request_id,
        )


class ModelNotReadyError(AiServiceError):
    def __init__(self, stage: str = "loading_models", *, retry_after: int = 2, request_id: str | None = None):
        super().__init__(
            AiErrorCode.MODEL_NOT_READY,
            "Models are loading",
            details={"stage": stage},
            request_id=request_id,
            retry_after=retry_after,
        )
