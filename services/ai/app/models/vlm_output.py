"""Parse untrusted VLM text into VisionResult (§31, §34, §63, ADR-0010).

The model output is data, not instructions: we only extract a JSON object and
keep fields that fit contracts/schemas/image-analysis.json and concepts.json.
Rules:
- only a root object carrying analysis fields (caption/ocr/concepts) counts;
  nested objects such as a single concept are never taken for the root;
- output cut off by AI_VLM_MAX_NEW_TOKENS keeps the caption and the fully
  formed OCR items / concepts that precede the cut;
- no such object, wrong field shapes, neither caption nor concepts, or a
  concepts list none of whose items fits the schema -> VisionOutputInvalid
  (caller retries, then 502 MODEL_OUTPUT_INVALID);
- concept.type outside the TagType enum -> "other" (ADR-0010);
- individual OCR items / concepts that cannot satisfy the schema are dropped
  (never completed with invented values, e.g. a missing confidence);
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
_ANALYSIS_FIELDS = frozenset({"caption", "ocr", "concepts"})
_LIST_FIELDS = frozenset({"ocr", "concepts"})
_WHITESPACE = re.compile(r"\s*")
_DECODER = json.JSONDecoder()


def _is_analysis_object(value) -> bool:
    return isinstance(value, dict) and not _ANALYSIS_FIELDS.isdisjoint(value)


def _skip_whitespace(text: str, pos: int) -> int:
    return _WHITESPACE.match(text, pos).end()


def _closes_before_end(text: str, start: int) -> bool:
    """True if the bracket opened at `start` is closed somewhere in `text`.

    String-aware bracket scan. An object that never closes was cut off
    mid-generation; one that closes but does not decode is merely malformed
    and is left to a retry rather than salvaged.
    """
    depth = 0
    in_string = escaped = False
    for char in text[start:]:
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
        elif char == '"':
            in_string = True
        elif char in "{[":
            depth += 1
        elif char in "}]":
            depth -= 1
            if depth == 0:
                return True
    return False


def _decode_array_prefix(text: str, pos: int) -> tuple[list, int, bool]:
    """Decode complete elements of the array starting at text[pos] == "[".

    Returns (elements, position, closed); stops at the first element that
    does not decode, so a half-written trailing element is dropped.
    """
    items: list = []
    pos += 1
    while True:
        pos = _skip_whitespace(text, pos)
        if text.startswith("]", pos):
            return items, pos + 1, True
        if items:
            if not text.startswith(",", pos):
                return items, pos, False
            pos = _skip_whitespace(text, pos + 1)
        try:
            item, pos = _DECODER.raw_decode(text, pos)
        except json.JSONDecodeError:
            return items, pos, False
        items.append(item)


def _decode_object_prefix(text: str, start: int) -> dict:
    """Decode the complete members of a truncated object at text[start] == "{".

    For list fields (ocr, concepts) the complete leading elements are kept.
    Decoding stops at the first member that is cut off.
    """
    data: dict = {}
    pos = start + 1
    while True:
        pos = _skip_whitespace(text, pos)
        if data:
            if not text.startswith(",", pos):
                return data
            pos = _skip_whitespace(text, pos + 1)
        try:
            key, pos = _DECODER.raw_decode(text, pos)
        except json.JSONDecodeError:
            return data
        pos = _skip_whitespace(text, pos)
        if not isinstance(key, str) or not text.startswith(":", pos):
            return data
        pos = _skip_whitespace(text, pos + 1)
        if key in _LIST_FIELDS and text.startswith("[", pos):
            data[key], pos, closed = _decode_array_prefix(text, pos)
            if not closed:
                return data
            continue
        try:
            data[key], pos = _DECODER.raw_decode(text, pos)
        except json.JSONDecodeError:
            return data


def _complete_root(candidate: str) -> dict | None:
    start = candidate.find("{")
    while start != -1:
        try:
            value, _ = _DECODER.raw_decode(candidate, start)
        except json.JSONDecodeError:
            value = None
        if _is_analysis_object(value):
            return value
        start = candidate.find("{", start + 1)
    return None


def _truncated_root(candidate: str) -> dict | None:
    # The first unclosed "{" that starts real JSON (decodes a member) encloses
    # everything after it, so it is the root; later braces are nested in it.
    # Unclosed braces in surrounding prose decode nothing and are skipped.
    start = candidate.find("{")
    while start != -1:
        if not _closes_before_end(candidate, start):
            value = _decode_object_prefix(candidate, start)
            if value:
                return value if _is_analysis_object(value) else None
        start = candidate.find("{", start + 1)
    return None


def _extract_json_object(raw: str) -> dict:
    text = _THINK_BLOCK.sub("", raw).strip()
    candidates = [text]
    candidates.extend(match.group(1).strip() for match in _CODE_FENCE.finditer(text))

    for candidate in candidates:
        root = _complete_root(candidate)
        if root is not None:
            return root
    # A cut-off answer has no closing fence, so only the full text can hold it.
    root = _truncated_root(text)
    if root is not None:
        logger.warning("ai_service.vlm_output_truncated", extra={"fields": sorted(root)})
        return root
    raise VisionOutputInvalid("no analysis JSON object found in model output")


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

    if len(items) < len(raw_items):
        # Malformed items (e.g. plain strings) and repeats of a decoding loop.
        logger.info("ai_service.vlm_ocr_dropped", extra={"dropped": len(raw_items) - len(items)})
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

    if raw_items and not concepts:
        # Every item broke the schema the same way (say, all lack "confidence"):
        # a format failure of this generation, not an image without concepts.
        # Retrying beats a 200 that leaves the image untagged.
        raise VisionOutputInvalid("no concept fits the schema")
    if len(concepts) < len(raw_items):
        logger.info("ai_service.vlm_concepts_dropped", extra={"dropped": len(raw_items) - len(concepts)})
    return concepts


def parse_vision_output(raw: str) -> VisionResult:
    data = _extract_json_object(raw)
    result = VisionResult(
        caption=_parse_caption(data),
        ocr=_parse_ocr(data),
        concepts=_parse_concepts(data),
    )
    # An image always has something to describe; an empty analysis means the
    # model failed, and a 200 with it would silently leave the image untagged.
    if not result.caption and not result.concepts:
        raise VisionOutputInvalid("analysis has neither caption nor concepts")
    return result
