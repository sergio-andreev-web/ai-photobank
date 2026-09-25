"""Model-layer failures, independent of HTTP.

Model implementations raise these; app/inference.py maps them onto the
contract error codes (schemas/ai-error.json) so routers never see torch or
transformers exceptions.
"""
from __future__ import annotations


class ModelError(Exception):
    """Base class for inference failures raised by VisionModel/EmbeddingModel."""


class VisionOutputInvalid(ModelError):
    """The VLM output could not be parsed into VisionResult after all attempts."""


class InferenceTimeout(ModelError):
    """Inference exceeded AI_INFERENCE_TIMEOUT_SECONDS."""


class InferenceOutOfMemory(ModelError):
    """The accelerator (or host) ran out of memory; caches were released."""


class InferenceFailed(ModelError):
    """Inference ran but produced unusable numbers (e.g. NaN embeddings)."""
