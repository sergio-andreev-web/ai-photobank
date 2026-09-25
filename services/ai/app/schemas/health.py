"""components/schemas/HealthResponse, ReadyResponse."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from .common import AiDevice, Models, StrictModel


class HealthResponse(StrictModel):
    status: Literal["ok"] = "ok"
    device: AiDevice
    models: Models


class ReadyResponse(StrictModel):
    status: Literal["ready"] = "ready"
    device: AiDevice
    models: Models
    embedding_dimensions: Literal[1152] = 1152
    prompt_version: str = Field(min_length=1)
