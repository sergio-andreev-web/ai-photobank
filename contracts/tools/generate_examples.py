#!/usr/bin/env python3
"""Генерирует детерминированные примеры AI API с полноразмерными векторами (1152).

Запуск: python3 contracts/tools/generate_examples.py
Только стандартная библиотека. Повторный запуск дает побайтно тот же результат.
"""
from __future__ import annotations

import json
import math
from pathlib import Path

DIMENSIONS = 1152
OUT = Path(__file__).resolve().parent.parent / "examples" / "ai"

# 1/sqrt(1152): вектор из таких компонент с любыми знаками имеет L2-норму ~1.
COMPONENT = round(1 / math.sqrt(DIMENSIONS), 10)


def vector(period: int) -> list[float]:
    """L2-нормализованный вектор, знак компонент меняется каждые `period` позиций."""
    return [COMPONENT if (i // period) % 2 == 0 else -COMPONENT for i in range(DIMENSIONS)]


EXAMPLES = {
    "analyze-response.json": {
        "request_id": "0b9f6c1e-3d2a-4c55-9a51-6f1f3c0a7e42",
        "caption": "Two engineers wearing helmets near industrial equipment.",
        "ocr": [{"text": "ЛУКОЙЛ", "confidence": 0.91}],
        "concepts": [
            {"type": "person", "label": "engineer", "confidence": 0.96},
            {"type": "object", "label": "protective helmet", "confidence": 0.97},
            {"type": "scene", "label": "industrial facility", "confidence": 0.93},
            {"type": "activity", "label": "equipment inspection", "confidence": 0.81},
        ],
        "embedding": vector(1),
        "embedding_dimensions": DIMENSIONS,
        "models": {
            "vision": "Qwen/Qwen3-VL-4B-Instruct",
            "embedding": "google/siglip2-so400m-patch14-384",
            "vision_revision": "qwen3vl-4b-20260925",
            "embedding_revision": "siglip2-so400m-20260925",
        },
        "prompt_version": "image-analysis-v1",
        "processing_ms": 4812,
    },
    "text-embeddings-request.json": {
        "texts": ["инженер", "защитная каска", "нефтяная установка"],
    },
    "text-embeddings-response.json": {
        "model": "google/siglip2-so400m-patch14-384",
        "revision": "siglip2-so400m-20260925",
        "dimensions": DIMENSIONS,
        "embeddings": [vector(1), vector(2), vector(3)],
    },
}


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for name, payload in EXAMPLES.items():
        text = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
        (OUT / name).write_text(text, encoding="utf-8")
        print(f"wrote {OUT / name}")


if __name__ == "__main__":
    main()
