"""Model registry: startup validation, device selection, self-check (§61)."""
from __future__ import annotations

import logging
import threading
from pathlib import Path

from PIL import Image

from ..config import Settings
from ..device import cuda_available, ensure_device_available, mps_available, resolve_device
from ..schemas.common import AiDevice
from .base import EmbeddingModel, VisionModel
from .providers import build_models, close_model

logger = logging.getLogger("ai_service.registry")

PROMPTS_DIR = Path(__file__).resolve().parent.parent.parent / "prompts"
EXPECTED_DIMENSIONS = 1152


class ModelRegistry:
    """Owns the active VisionModel/EmbeddingModel instances and readiness state.

    Startup sequence mirrors §61: validate configuration, resolve device, load
    models, verify embedding dimensions, run a lightweight self-check, then
    (and only then) report ready.

    Real models take minutes to download/load, so they load on a background
    thread: /health answers immediately and /ready stays 503 until done
    (ADR-0010). Mock models load inline, keeping tests deterministic.
    """

    def __init__(
        self,
        settings: Settings,
        *,
        model_builder=build_models,
        cuda_available_fn=cuda_available,
        mps_available_fn=mps_available,
    ):
        self.settings = settings
        self.device: AiDevice | None = None
        self.vision_model: VisionModel | None = None
        self.embedding_model: EmbeddingModel | None = None
        self.ready: bool = False
        self._model_builder = model_builder
        self._cuda_available_fn = cuda_available_fn
        self._mps_available_fn = mps_available_fn
        self._startup_thread: threading.Thread | None = None
        self._stopped = threading.Event()

    @property
    def loads_in_background(self) -> bool:
        return self.settings.ai_model_provider != "mock"

    def _prompt_path(self) -> Path:
        return PROMPTS_DIR / f"{self.settings.prompt_version}.txt"

    def _resolve_device(self) -> AiDevice:
        return resolve_device(
            self.settings.device,
            cuda_available_fn=self._cuda_available_fn,
            mps_available_fn=self._mps_available_fn,
        )

    def startup(self) -> None:
        self.ready = False

        # 1. validate configuration (Settings field validators already ran;
        #    additionally require the versioned prompt file to exist, §34).
        prompt_path = self._prompt_path()
        if not prompt_path.is_file():
            raise RuntimeError(f"Prompt file not found for version {self.settings.prompt_version!r}: {prompt_path}")
        prompt = prompt_path.read_text(encoding="utf-8").strip()

        # 2. resolve and verify device
        self.device = self._resolve_device()
        if self.settings.ai_model_provider != "mock":
            ensure_device_available(
                self.device,
                cuda_available_fn=self._cuda_available_fn,
                mps_available_fn=self._mps_available_fn,
            )
            if self.device is AiDevice.CPU:
                logger.warning("ai_service.cpu_inference", extra={"note": "CPU is for development/smoke tests only (§58)"})

        # 3. load models
        vision_model, embedding_model = self._model_builder(self.settings, self.device, prompt)
        try:
            # 4. verify embedding dimensions
            if embedding_model.dimensions != EXPECTED_DIMENSIONS:
                raise RuntimeError(
                    f"Embedding model {embedding_model.name} reports "
                    f"{embedding_model.dimensions} dimensions, expected {EXPECTED_DIMENSIONS}"
                )

            # 5. lightweight self-check
            self._self_check(vision_model, embedding_model)

            # Shutdown arrived while a background load was in progress.
            if self._stopped.is_set():
                raise RuntimeError("registry was shut down during startup")
        except BaseException:
            close_model(vision_model)
            close_model(embedding_model)
            raise

        # 6. only now ready
        self.vision_model, self.embedding_model = vision_model, embedding_model
        self.ready = True
        logger.info(
            "ai_service.ready",
            extra={
                "device": self.device.value,
                "vision": vision_model.name,
                "vision_revision": vision_model.revision,
                "embedding_revision": embedding_model.revision,
            },
        )

    def _run_startup(self) -> None:
        try:
            self.startup()
        except Exception:
            logger.exception("ai_service.startup_failed")

    def start(self) -> None:
        """Lifespan entry point: inline for mock, background thread otherwise."""
        self._stopped.clear()
        if not self.loads_in_background:
            self._run_startup()
            return
        thread = threading.Thread(target=self._run_startup, name="ai-model-startup", daemon=True)
        self._startup_thread = thread
        thread.start()

    def shutdown(self) -> None:
        self._stopped.set()
        self.ready = False
        vision_model, embedding_model = self.vision_model, self.embedding_model
        self.vision_model = self.embedding_model = None
        close_model(vision_model)
        close_model(embedding_model)

    @staticmethod
    def _self_check(vision_model: VisionModel, embedding_model: EmbeddingModel) -> None:
        # Also warms up accelerator kernels before the first real request.
        probe_image = Image.new("RGB", (64, 64), color=(128, 128, 128))
        vision_result = vision_model.analyze(probe_image)
        if not isinstance(vision_result.caption, str):
            raise RuntimeError("Vision model self-check failed: caption is not a string")

        image_vector = embedding_model.image_embedding(probe_image)
        if len(image_vector) != EXPECTED_DIMENSIONS:
            raise RuntimeError("Embedding model self-check failed: image embedding has wrong dimensions")

        text_vectors = embedding_model.text_embeddings(["self-check"])
        if len(text_vectors) != 1 or len(text_vectors[0]) != EXPECTED_DIMENSIONS:
            raise RuntimeError("Embedding model self-check failed: text embedding has wrong shape")

    def health(self) -> dict:
        device = self.device or self._resolve_device()
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
            "embedding_dimensions": EXPECTED_DIMENSIONS,
            "prompt_version": self.settings.prompt_version,
        }
