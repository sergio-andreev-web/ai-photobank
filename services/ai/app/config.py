"""Runtime configuration for the AI service.

Environment variables follow docs/technical-specification.md §58, §60, §69.
"""
from __future__ import annotations

from functools import lru_cache

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

VALID_DEVICES = {"auto", "cuda", "mps", "cpu"}


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="", extra="ignore")

    # §60 model registry / §69 AI env vars
    device: str = Field(default="auto", alias="DEVICE")
    vision_model: str = Field(default="Qwen/Qwen3-VL-4B-Instruct", alias="VISION_MODEL")
    embedding_model: str = Field(
        default="google/siglip2-so400m-patch14-384", alias="EMBEDDING_MODEL"
    )
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

    # Local detail: which ModelRegistry implementation to boot. This task's scope is
    # mock models only (see task brief); "real" is a placeholder for the follow-up task
    # that wires up SigLIP2/Qwen3-VL and is intentionally not implemented here.
    ai_model_provider: str = Field(default="mock", alias="AI_MODEL_PROVIDER")

    # §63 "/docs FastAPI запрещать в production либо защищать".
    ai_environment: str = Field(default="development", alias="AI_ENVIRONMENT")

    @field_validator("device")
    @classmethod
    def _validate_device(cls, value: str) -> str:
        if value not in VALID_DEVICES:
            raise ValueError(f"DEVICE must be one of {sorted(VALID_DEVICES)}, got {value!r}")
        return value

    @field_validator("vision_model", "embedding_model", "prompt_version")
    @classmethod
    def _non_empty(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("must not be empty")
        return value

    @property
    def is_production(self) -> bool:
        return self.ai_environment.lower() == "production"


@lru_cache
def get_settings() -> Settings:
    return Settings()
