"""Model registry: startup validation, device selection, self-check (§61)."""
from __future__ import annotations

import logging
from pathlib import Path

from PIL import Image

from ..config import Settings
from ..device import resolve_device
from ..schemas.common import AiDevice
from .base import EmbeddingModel, VisionModel
from .mock import MockEmbeddingModel, MockVisionModel

logger = logging.getLogger("ai_service.registry")

PROMPTS_DIR = Path(__file__).resolve().parent.parent.parent / "prompts"


class ModelRegistry:
    """Owns the active VisionModel/EmbeddingModel instances and readiness state.

    Startup sequence mirrors §61: validate configuration, resolve device, load
    models, verify embedding dimensions, run a lightweight self-check, then
    (and only then) report ready.
    """

    def __init__(self, settings: Settings):
        self.settings = settings
        self.device: AiDevice | None = None
        self.vision_model: VisionModel | None = None
        self.embedding_model: EmbeddingModel | None = None
        self.ready: bool = False

    def _build_models(self) -> tuple[VisionModel, EmbeddingModel]:
        if self.settings.ai_model_provider != "mock":
            # Real SigLIP2/Qwen3-VL integration is out of scope for the
            # "ai-mock" task; keep the failure explicit instead of silently
            # falling back so a misconfiguration is never mistaken for a
            # working real-model deployment.
            raise NotImplementedError(
                f"AI_MODEL_PROVIDER={self.settings.ai_model_provider!r} is not implemented; "
                "only 'mock' is available in this build."
            )
        return MockVisionModel(), MockEmbeddingModel()

    def _prompt_path(self) -> Path:
        return PROMPTS_DIR / f"{self.settings.prompt_version}.txt"

    def startup(self) -> None:
        self.ready = False

        # 1. validate configuration (Settings field validators already ran;
        #    additionally require the versioned prompt file to exist, §34).
        prompt_path = self._prompt_path()
        if not prompt_path.is_file():
            raise RuntimeError(f"Prompt file not found for version {self.settings.prompt_version!r}: {prompt_path}")

        # 2. resolve device
        self.device = resolve_device(self.settings.device)

        # 3. load models
        self.vision_model, self.embedding_model = self._build_models()

        # 4. verify embedding dimensions
        if self.embedding_model.dimensions != 1152:
            raise RuntimeError(
                f"Embedding model {self.embedding_model.name} reports "
                f"{self.embedding_model.dimensions} dimensions, expected 1152"
            )

        # 5. lightweight self-check
        self._self_check()

        # 6. only now ready
        self.ready = True
        logger.info(
            "ai_service.ready",
            extra={"device": self.device.value, "vision": self.vision_model.name},
        )

    def _self_check(self) -> None:
        probe_image = Image.new("RGB", (8, 8), color=(128, 128, 128))
        vision_result = self.vision_model.analyze(probe_image)
        if not isinstance(vision_result.caption, str):
            raise RuntimeError("Vision model self-check failed: caption is not a string")

        image_vector = self.embedding_model.image_embedding(probe_image)
        if len(image_vector) != 1152:
            raise RuntimeError("Embedding model self-check failed: image embedding has wrong dimensions")

        text_vectors = self.embedding_model.text_embeddings(["self-check"])
        if len(text_vectors) != 1 or len(text_vectors[0]) != 1152:
            raise RuntimeError("Embedding model self-check failed: text embedding has wrong shape")

    def health(self) -> dict:
        device = self.device or resolve_device(self.settings.device)
        return {
            "status": "ok",
            "device": device.value,
            "models": {
                "vision": self.settings.vision_model,
                "embedding": self.settings.embedding_model,
            },
        }

    def ready_payload(self) -> dict:
        assert self.device is not None
        return {
            "status": "ready",
            "device": self.device.value,
            "models": {
                "vision": self.settings.vision_model,
                "embedding": self.settings.embedding_model,
            },
            "embedding_dimensions": 1152,
            "prompt_version": self.settings.prompt_version,
        }
