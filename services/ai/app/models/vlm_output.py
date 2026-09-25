"""Parse untrusted VLM text into VisionResult (§31, §34, §63, ADR-0010).

The model output is data, not instructions: we only extract a JSON object and
keep fields that fit contracts/schemas/image-analysis.json and concepts.json.
Rules:
- no JSON object / wrong top-level shape -> VisionOutputInvalid (caller retries);
- concept.type outside the TagType enum -> "other" (ADR-0010);
- individual OCR items / concepts that cannot satisfy the schema are dropped;
- caption and OCR text are truncated to the contract maxLength;
- concepts capped at 50, OCR items at 100; exact duplicates removed.
"""
from __future__ import annotations

import json
import logging
import math
import re

from ..schemas.analysis import Concept, OcrItem
from ..schemas.common import TagType
from .base import VisionResult
from .errors import VisionOutputInvalid

logger = logging.getLogger("ai_service.vlm_output")

MAX_CAPTION_LENGTH = 2000
MAX_OCR_TEXT_LENGTH = 2000
MAX_OCR_ITEMS = 100
MAX_LABEL_LENGTH = 255
MAX_CONCEPTS = 50

_THINK_BLOCK = re.compile(r"<think>.*?</think>", re.DOTALL | re.IGNORECASE)
_CODE_FENCE = re.compile(r"```(?:json)?\s*(.*?)```", re.DOTALL | re.IGNORECASE)
_TAG_TYPES = {tag_type.value for tag_type in TagType}


def _extract_json_object(raw: str) -> dict:
    text = _THINK_BLOCK.sub("", raw).strip()
    candidates = [text]
    candidates.extend(match.group(1).strip() for match in _CODE_FENCE.finditer(text))

    decoder = json.JSONDecoder()
    for candidate in candidates:
        start = candidate.find("{")
        while start != -1:
            try:
                value, _ = decoder.raw_decode(candidate, start)
            except json.JSONDecodeError:
                start = candidate.find("{", start + 1)
                continue
            if isinstance(value, dict):
                return value
            start = candidate.find("{", start + 1)
    raise VisionOutputInvalid("no JSON object found in model output")


def _as_confidence(value) -> float | None:
    # bool is an int subclass; "true" is not a confidence.
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    value = float(value)
    if not math.isfinite(value) or value < 0 or value > 1:
        return None
    return value


def _parse_caption(data: dict) -> str:
    caption = data.get("caption", "")
    if caption is None:
        return ""
    if not isinstance(caption, str):
        raise VisionOutputInvalid("caption is not a string")
    return caption.strip()[:MAX_CAPTION_LENGTH]


def _parse_ocr(data: dict) -> list[OcrItem]:
    raw_items = data.get("ocr", [])
    if raw_items is None:
        return []
    if not isinstance(raw_items, list):
        raise VisionOutputInvalid("ocr is not a list")

    items: list[OcrItem] = []
    seen: set[str] = set()
    for raw in raw_items:
        if not isinstance(raw, dict):
            continue
        text = raw.get("text")
        confidence = _as_confidence(raw.get("confidence"))
        if not isinstance(text, str) or confidence is None:
            continue
        text = text.strip()[:MAX_OCR_TEXT_LENGTH]
        if not text or text in seen:
            continue
        seen.add(text)
        items.append(OcrItem(text=text, confidence=confidence))
        if len(items) == MAX_OCR_ITEMS:
            break
    return items


def _parse_concepts(data: dict) -> list[Concept]:
    raw_items = data.get("concepts", [])
    if raw_items is None:
        return []
    if not isinstance(raw_items, list):
        raise VisionOutputInvalid("concepts is not a list")

    concepts: list[Concept] = []
    seen: set[tuple[str, str]] = set()
    for raw in raw_items:
        if not isinstance(raw, dict):
            continue
        label = raw.get("label")
        confidence = _as_confidence(raw.get("confidence"))
        if not isinstance(label, str) or confidence is None:
            continue
        label = label.strip()
        if not label or len(label) > MAX_LABEL_LENGTH:
            continue
        raw_type = raw.get("type")
        tag_type = raw_type.strip().lower() if isinstance(raw_type, str) else ""
        if tag_type not in _TAG_TYPES:
            tag_type = TagType.OTHER.value
        key = (tag_type, label)
        if key in seen:
            continue
        seen.add(key)
        concepts.append(Concept(type=TagType(tag_type), label=label, confidence=confidence))
        if len(concepts) == MAX_CONCEPTS:
            break

    if len(concepts) < len(raw_items):
        logger.info("ai_service.vlm_concepts_dropped", extra={"dropped": len(raw_items) - len(concepts)})
    return concepts


def parse_vision_output(raw: str) -> VisionResult:
    data = _extract_json_object(raw)
    return VisionResult(
        caption=_parse_caption(data),
        ocr=_parse_ocr(data),
        concepts=_parse_concepts(data),
    )
