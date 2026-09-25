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
