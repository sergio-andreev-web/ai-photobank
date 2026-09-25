"""Shared primitives mirroring contracts/ai-api.openapi.yaml components.schemas."""
from __future__ import annotations

import re
from enum import Enum

from pydantic import BaseModel, ConfigDict, Field

REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9._:-]+$")


class StrictModel(BaseModel):
    """Base model matching the contract's `additionalProperties: false`."""

    model_config = ConfigDict(extra="forbid")


class AiDevice(str, Enum):
    """contracts/schemas/enums.json#/$defs/AiDevice"""

    CUDA = "cuda"
    MPS = "mps"
    CPU = "cpu"


class TagType(str, Enum):
    """contracts/schemas/enums.json#/$defs/TagType"""

    OBJECT = "object"
    PERSON = "person"
    ACTIVITY = "activity"
    SCENE = "scene"
    LOCATION = "location"
    SEASON = "season"
    WEATHER = "weather"
    BRAND = "brand"
    EVENT = "event"
    COLOR = "color"
    CONCEPT = "concept"
    OTHER = "other"


def is_valid_request_id(value: str) -> bool:
    return 1 <= len(value) <= 128 and bool(REQUEST_ID_PATTERN.match(value))


class Models(StrictModel):
    """components/schemas/Models — used by /health and /ready."""

    vision: str = Field(min_length=1)
    embedding: str = Field(min_length=1)
