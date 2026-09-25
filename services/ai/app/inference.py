"""Map model-layer failures onto contract error codes (schemas/ai-error.json).

- VisionOutputInvalid -> 502 MODEL_OUTPUT_INVALID (retryable, contract);
- timeout / OOM / any other model failure -> 500 INFERENCE_FAILED (retryable).
Messages carry no paths, tensors or model text (ai-error.json `details` rule).
"""
from __future__ import annotations

import logging
from contextlib import contextmanager

from .models.errors import InferenceOutOfMemory, InferenceTimeout, VisionOutputInvalid
from .schemas.errors import AiServiceError, InferenceFailedError, ModelOutputInvalidError

logger = logging.getLogger("ai_service.inference")


@contextmanager
def translate_model_errors(*, request_id: str | None, prompt_version: str):
    try:
        yield
    except AiServiceError:
        raise
    except VisionOutputInvalid:
        raise ModelOutputInvalidError(prompt_version, request_id=request_id) from None
    except InferenceTimeout:
        raise InferenceFailedError("Inference timed out", request_id=request_id) from None
    except InferenceOutOfMemory:
        raise InferenceFailedError("Inference ran out of memory", request_id=request_id) from None
    except Exception:
        logger.exception("ai_service.inference_exception")
        raise InferenceFailedError(request_id=request_id) from None
