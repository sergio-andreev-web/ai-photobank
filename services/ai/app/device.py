"""Device resolution per docs/technical-specification.md §60.

`auto` → cuda if available, else mps if available, else cpu. Detection is
isolated behind small functions so tests can monkeypatch availability without
requiring real GPU hardware or even a torch install (CI has no GPU, §73).
"""
from __future__ import annotations

from .schemas.common import AiDevice


def cuda_available() -> bool:
    try:
        import torch
    except ImportError:
        return False
    try:
        return bool(torch.cuda.is_available())
    except Exception:
        return False


def mps_available() -> bool:
    try:
        import torch
    except ImportError:
        return False
    try:
        return bool(torch.backends.mps.is_available())
    except Exception:
        return False


def cuda_bf16_supported() -> bool:
    try:
        import torch
    except ImportError:
        return False
    try:
        return bool(torch.cuda.is_available() and torch.cuda.is_bf16_supported())
    except Exception:
        return False


def resolve_device(
    configured: str,
    *,
    cuda_available_fn=cuda_available,
    mps_available_fn=mps_available,
) -> AiDevice:
    if configured == "cuda":
        return AiDevice.CUDA
    if configured == "mps":
        return AiDevice.MPS
    if configured == "cpu":
        return AiDevice.CPU
    if configured == "auto":
        if cuda_available_fn():
            return AiDevice.CUDA
        if mps_available_fn():
            return AiDevice.MPS
        return AiDevice.CPU
    raise ValueError(f"Unknown device configuration: {configured!r}")


def ensure_device_available(
    device: AiDevice,
    *,
    cuda_available_fn=cuda_available,
    mps_available_fn=mps_available,
) -> None:
    """§61 step 2 for real models: an explicitly requested accelerator must exist.

    Never silently fall back from cuda/mps to cpu — a misconfigured GPU host
    would otherwise report ready while running 50x slower.
    """
    if device is AiDevice.CUDA and not cuda_available_fn():
        raise RuntimeError("DEVICE=cuda requested but CUDA is not available")
    if device is AiDevice.MPS and not mps_available_fn():
        raise RuntimeError("DEVICE=mps requested but MPS is not available")


def resolve_dtype(configured: str, device: AiDevice, *, cuda_bf16_fn=cuda_bf16_supported) -> str:
    """DTYPE (§60) -> torch dtype name.

    `auto`: cuda -> bfloat16 when supported, else float16; mps -> bfloat16
    (Qwen3 overflows in float16; needs macOS 14+, override with DTYPE=float16
    on older systems); cpu -> float32.
    """
    if configured != "auto":
        return configured
    if device is AiDevice.CUDA:
        return "bfloat16" if cuda_bf16_fn() else "float16"
    if device is AiDevice.MPS:
        return "bfloat16"
    return "float32"
