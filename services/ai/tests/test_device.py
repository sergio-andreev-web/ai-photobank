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


@pytest.mark.parametrize(
    "configured, device, bf16, expected",
    [
        ("auto", AiDevice.CUDA, True, "bfloat16"),
        ("auto", AiDevice.CUDA, False, "float16"),
        ("auto", AiDevice.MPS, False, "bfloat16"),
        ("auto", AiDevice.CPU, True, "float32"),
        ("float16", AiDevice.MPS, False, "float16"),
        ("float32", AiDevice.CUDA, True, "float32"),
    ],
)
def test_resolve_dtype_profiles(configured, device, bf16, expected):
    from app.device import resolve_dtype

    assert resolve_dtype(configured, device, cuda_bf16_fn=lambda: bf16) == expected


def test_ensure_device_available_accepts_present_accelerators_and_cpu():
    from app.device import ensure_device_available

    ensure_device_available(AiDevice.CUDA, cuda_available_fn=lambda: True, mps_available_fn=lambda: False)
    ensure_device_available(AiDevice.MPS, cuda_available_fn=lambda: False, mps_available_fn=lambda: True)
    ensure_device_available(AiDevice.CPU, cuda_available_fn=lambda: False, mps_available_fn=lambda: False)


def test_cuda_and_mps_available_do_not_raise_without_torch():
    from app.device import cuda_available, mps_available

    # Must degrade gracefully to False when torch is not installed / has no
    # accelerator, since CI runs without GPU (§73).
    assert cuda_available() in (True, False)
    assert mps_available() in (True, False)
