"""Qwen3-VL VisionModel: prompt, retries, deadline and pixel budget.

Framework-free orchestration; the actual Transformers call lives behind the
VisionGenerator protocol (app/models/transformers_backend.py), so this logic
is unit-tested without torch or weights (§73). The same class serves the 4B
(Mac/MPS) and 8B (CUDA) checkpoints — output shape does not depend on size.
"""
from __future__ import annotations

import logging
import math
import time
from typing import Callable, Protocol

from PIL import Image as PILImage
from PIL.Image import Image

from .base import VisionResult
from .errors import InferenceTimeout, VisionOutputInvalid
from .vlm_output import parse_vision_output

logger = logging.getLogger("ai_service.vision")


class VisionGenerator(Protocol):
    def generate(self, image: Image, prompt: str, *, sample: bool, deadline: float) -> str:
        """Return raw model text. Raise InferenceTimeout once `deadline`
        (time.monotonic() based) passes, InferenceOutOfMemory on OOM."""
        ...

    def close(self) -> None: ...


def fit_to_pixel_budget(image: Image, max_pixels: int) -> Image:
    """Downscale (never upscale) so width*height <= max_pixels, keeping aspect."""
    width, height = image.size
    if width * height <= max_pixels:
        return image
    scale = math.sqrt(max_pixels / (width * height))
    new_size = (max(1, int(width * scale)), max(1, int(height * scale)))
    return image.resize(new_size, PILImage.BICUBIC)


class QwenVisionModel:
    def __init__(
        self,
        *,
        name: str,
        revision: str,
        generator: VisionGenerator,
        prompt: str,
        max_pixels: int,
        timeout_seconds: float,
        attempts: int,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self.name = name
        self.revision = revision
        self._generator = generator
        self._prompt = prompt
        self._max_pixels = max_pixels
        self._timeout_seconds = timeout_seconds
        self._attempts = attempts
        self._clock = clock

    def analyze(self, image: Image) -> VisionResult:
        deadline = self._clock() + self._timeout_seconds
        prepared = fit_to_pixel_budget(image, self._max_pixels)

        last_error: VisionOutputInvalid | None = None
        for attempt in range(self._attempts):
            if self._clock() >= deadline:
                raise InferenceTimeout(f"deadline exceeded before attempt {attempt + 1}")
            # First attempt greedy (most reliable JSON); retries sample so a
            # degenerate greedy output is not reproduced verbatim.
            raw = self._generator.generate(prepared, self._prompt, sample=attempt > 0, deadline=deadline)
            try:
                return parse_vision_output(raw)
            except VisionOutputInvalid as exc:
                last_error = exc
                logger.warning(
                    "ai_service.vlm_output_invalid",
                    extra={"attempt": attempt + 1, "reason": str(exc), "output_chars": len(raw)},
                )
        assert last_error is not None
        raise last_error

    def close(self) -> None:
        self._generator.close()
