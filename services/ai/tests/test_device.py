from __future__ import annotations

import pytest

from app.device import resolve_device
from app.schemas.common import AiDevice


def test_resolve_device_explicit_values_bypass_detection():
    assert resolve_device("cuda") == AiDevice.CUDA
    assert resolve_device("mps") == AiDevice.MPS
    assert resolve_device("cpu") == AiDevice.CPU


def test_resolve_device_auto_prefers_cuda():
    resolved = resolve_device(
        "auto",
        cuda_available_fn=lambda: True,
        mps_available_fn=lambda: True,
    )
    assert resolved == AiDevice.CUDA


def test_resolve_device_auto_falls_back_to_mps():
    resolved = resolve_device(
        "auto",
        cuda_available_fn=lambda: False,
        mps_available_fn=lambda: True,
    )
    assert resolved == AiDevice.MPS


def test_resolve_device_auto_falls_back_to_cpu():
    resolved = resolve_device(
        "auto",
        cuda_available_fn=lambda: False,
        mps_available_fn=lambda: False,
    )
    assert resolved == AiDevice.CPU


def test_resolve_device_rejects_unknown_value():
    with pytest.raises(ValueError):
        resolve_device("tpu")


def test_cuda_and_mps_available_do_not_raise_without_torch():
    from app.device import cuda_available, mps_available

    # Must degrade gracefully to False when torch is not installed / has no
    # accelerator, since CI runs without GPU (§73).
    assert cuda_available() in (True, False)
    assert mps_available() in (True, False)
