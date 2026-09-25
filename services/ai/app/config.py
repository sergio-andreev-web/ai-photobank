"""Runtime configuration for the AI service.

Environment variables follow docs/technical-specification.md §58, §60, §69.
"""
from __future__ import annotations

from functools import lru_cache

from pydantic import Field, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

VALID_DEVICES = {"auto", "cuda", "mps", "cpu"}
VALID_DTYPES = {"auto", "float32", "float16", "bfloat16"}
VALID_PROVIDERS = {"mock", "transformers"}


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="", extra="ignore")

    # §60 model registry / §69 AI env vars
    device: str = Field(default="auto", alias="DEVICE")
    dtype: str = Field(default="auto", alias="DTYPE")
    vision_model: str = Field(default="Qwen/Qwen3-VL-4B-Instruct", alias="VISION_MODEL")
    embedding_model: str = Field(
        default="google/siglip2-so400m-patch14-384", alias="EMBEDDING_MODEL"
    )
    # Local detail: pin HuggingFace weights to a branch/tag/commit. The resolved
    # commit sha is what the API reports as *_revision (ADR-0009).
    vision_model_revision: str = Field(default="main", alias="VISION_MODEL_REVISION")
    embedding_model_revision: str = Field(default="main", alias="EMBEDDING_MODEL_REVISION")
    hf_home: str | None = Field(default=None, alias="HF_HOME")
    prompt_version: str = Field(default="image-analysis-v1", alias="PROMPT_VERSION")

    # §63 security
    ai_service_api_key: str = Field(default="", alias="AI_SERVICE_API_KEY")

    # §58 mac profile default; RunPod/infra may override.
    ai_concurrency: int = Field(default=1, alias="AI_CONCURRENCY")

    # §62 image limits. 20 MiB default matches contracts/ai-api.openapi.yaml.
    ai_max_image_bytes: int = Field(default=20 * 1024 * 1024, alias="AI_MAX_IMAGE_BYTES")
    # Local implementation detail (not contract-facing): cap the longest edge sent to
    # the VLM so a huge original is never forwarded raw (§62).
    ai_max_image_dimension: int = Field(default=2048, alias="AI_MAX_IMAGE_DIMENSION")

    # Local detail: which model implementation to boot. "mock" — deterministic
    # models for CI/contract tests (§72); "transformers" — SigLIP2 + Qwen3-VL via
    # PyTorch/Transformers (requires requirements-model.txt).
    ai_model_provider: str = Field(default="mock", alias="AI_MODEL_PROVIDER")

    # Local inference guards (memory/latency control, not contract-facing).
    # Wall-clock budget for one image analysis including internal retries; the
    # VLM stops generating cooperatively once it is exceeded.
    ai_inference_timeout_seconds: float = Field(default=120.0, gt=0, alias="AI_INFERENCE_TIMEOUT_SECONDS")
    # Upper bound for generated tokens per VLM attempt.
    ai_vlm_max_new_tokens: int = Field(default=1024, ge=64, alias="AI_VLM_MAX_NEW_TOKENS")
    # Image area (pixels) passed to the VLM; bounds visual tokens and memory.
    ai_vlm_max_pixels: int = Field(default=1024 * 1024, ge=64 * 64, alias="AI_VLM_MAX_PIXELS")
    # Generation attempts before MODEL_OUTPUT_INVALID (contract: "после внутренних попыток").
    ai_vlm_output_attempts: int = Field(default=2, ge=1, le=5, alias="AI_VLM_OUTPUT_ATTEMPTS")

    # §63 "/docs FastAPI запрещать в production либо защищать".
    ai_environment: str = Field(default="development", alias="AI_ENVIRONMENT")

    @field_validator("device")
    @classmethod
    def _validate_device(cls, value: str) -> str:
        if value not in VALID_DEVICES:
            raise ValueError(f"DEVICE must be one of {sorted(VALID_DEVICES)}, got {value!r}")
        return value

    @field_validator("dtype")
    @classmethod
    def _validate_dtype(cls, value: str) -> str:
        if value not in VALID_DTYPES:
            raise ValueError(f"DTYPE must be one of {sorted(VALID_DTYPES)}, got {value!r}")
        return value

    @field_validator("ai_model_provider")
    @classmethod
    def _validate_provider(cls, value: str) -> str:
        if value not in VALID_PROVIDERS:
            raise ValueError(f"AI_MODEL_PROVIDER must be one of {sorted(VALID_PROVIDERS)}, got {value!r}")
        return value

    @field_validator(
        "vision_model",
        "embedding_model",
        "prompt_version",
        "vision_model_revision",
        "embedding_model_revision",
    )
    @classmethod
    def _non_empty(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("must not be empty")
        return value

    @property
    def is_production(self) -> bool:
        return self.ai_environment.lower() == "production"

    @model_validator(mode="after")
    def _require_api_key_in_production(self) -> "Settings":
        # ADR-0010: empty AI_SERVICE_API_KEY disables the check (local dev only);
        # production must fail fast at startup instead of silently running unauthenticated.
        if self.is_production and not self.ai_service_api_key:
            raise ValueError(
                "AI_SERVICE_API_KEY must not be empty when AI_ENVIRONMENT=production"
            )
        return self


@lru_cache
def get_settings() -> Settings:
    return Settings()
