"""QwenVisionModel orchestration: retries, deadline, pixel budget (no torch)."""
from __future__ import annotations

import json

import pytest
from PIL import Image

from app.models.errors import InferenceOutOfMemory, InferenceTimeout, VisionOutputInvalid
from app.models.vision import QwenVisionModel, fit_to_pixel_budget

GOOD_OUTPUT = json.dumps({"caption": "A gray square.", "ocr": [], "concepts": [{"type": "color", "label": "gray", "confidence": 0.9}]})


class FakeClock:
    def __init__(self) -> None:
        self.now = 1000.0

    def __call__(self) -> float:
        return self.now


class ScriptedGenerator:
    def __init__(self, outputs, clock: FakeClock | None = None, step: float = 0.0):
        self.outputs = list(outputs)
        self.calls: list[dict] = []
        self.clock = clock
        self.step = step
        self.closed = False

    def generate(self, image, prompt, *, sample, deadline):
        self.calls.append({"size": image.size, "prompt": prompt, "sample": sample, "deadline": deadline})
        if self.clock is not None:
            self.clock.now += self.step
        output = self.outputs.pop(0)
        if isinstance(output, Exception):
            raise output
        return output

    def close(self):
        self.closed = True


def make_model(generator, *, attempts=2, timeout=60.0, max_pixels=1024 * 1024, clock=None) -> QwenVisionModel:
    return QwenVisionModel(
        name="Qwen/Qwen3-VL-4B-Instruct",
        revision="abc",
        generator=generator,
        prompt="PROMPT",
        max_pixels=max_pixels,
        timeout_seconds=timeout,
        attempts=attempts,
        clock=clock or FakeClock(),
    )


def test_first_attempt_is_greedy_and_uses_prompt():
    generator = ScriptedGenerator([GOOD_OUTPUT])
    result = make_model(generator).analyze(Image.new("RGB", (32, 32)))
    assert result.caption == "A gray square."
    assert generator.calls == [{"size": (32, 32), "prompt": "PROMPT", "sample": False, "deadline": 1060.0}]


def test_retries_with_sampling_after_invalid_output():
    generator = ScriptedGenerator(["not json", GOOD_OUTPUT])
    result = make_model(generator, attempts=2).analyze(Image.new("RGB", (8, 8)))
    assert result.concepts[0].label == "gray"
    assert [call["sample"] for call in generator.calls] == [False, True]


def test_raises_output_invalid_after_all_attempts():
    generator = ScriptedGenerator(["garbage", "{broken", "[]"])
    with pytest.raises(VisionOutputInvalid):
        make_model(generator, attempts=3).analyze(Image.new("RGB", (8, 8)))
    assert len(generator.calls) == 3


def test_deadline_is_shared_across_attempts():
    clock = FakeClock()
    generator = ScriptedGenerator(["garbage", GOOD_OUTPUT], clock=clock, step=31.0)
    with pytest.raises(InferenceTimeout):
        make_model(generator, attempts=3, timeout=30.0, clock=clock).analyze(Image.new("RGB", (8, 8)))
    assert len(generator.calls) == 1


def test_generator_failures_propagate():
    generator = ScriptedGenerator([InferenceOutOfMemory("cuda out of memory")])
    with pytest.raises(InferenceOutOfMemory):
        make_model(generator).analyze(Image.new("RGB", (8, 8)))


def test_image_is_downscaled_to_pixel_budget_before_generation():
    generator = ScriptedGenerator([GOOD_OUTPUT])
    make_model(generator, max_pixels=100 * 100).analyze(Image.new("RGB", (400, 200)))
    width, height = generator.calls[0]["size"]
    assert width * height <= 100 * 100
    assert abs(width / height - 2.0) < 0.05


def test_fit_to_pixel_budget_never_upscales():
    image = Image.new("RGB", (10, 10))
    assert fit_to_pixel_budget(image, 10_000) is image


def test_close_releases_generator():
    generator = ScriptedGenerator([])
    make_model(generator).close()
    assert generator.closed
