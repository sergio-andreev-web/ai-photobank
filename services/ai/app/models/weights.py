"""Resolve model weights to a local directory plus the revision reported by the API.

- HuggingFace repo id -> snapshot in the HF cache (HF_HOME, §68; never inside
  the image). The revision is the resolved commit sha (ADR-0009). With
  HF_HUB_OFFLINE=1 the cached snapshot is used without touching the network.
- Local directory -> used as is; the revision is a stable sha256 digest over
  the file set (ADR-0009 "для локальных весов revision — стабильный digest").
"""
from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from pathlib import Path

# Only what AutoModel/AutoProcessor need; skips duplicate *.bin/onnx/gguf weights.
ALLOW_PATTERNS = ["*.json", "*.safetensors", "*.txt", "*.model", "*.jinja", "tokenizer*"]

_COMMIT_SHA = re.compile(r"^[0-9a-f]{40}$")
_DIGEST_CHUNK = 8 * 1024 * 1024


@dataclass(frozen=True)
class ResolvedWeights:
    path: Path
    revision: str


def hub_cache_dir(hf_home: str | None) -> str | None:
    """Explicit cache dir when HF_HOME is configured via Settings; otherwise let
    huggingface_hub read HF_HOME/HF_HUB_CACHE from the environment itself."""
    return str(Path(hf_home) / "hub") if hf_home else None


def local_weights_digest(directory: Path) -> str:
    digest = hashlib.sha256()
    files = sorted(p for p in directory.rglob("*") if p.is_file() and not p.name.startswith("."))
    if not files:
        raise RuntimeError(f"Local model directory has no files: {directory}")
    for file_path in files:
        digest.update(file_path.relative_to(directory).as_posix().encode("utf-8"))
        digest.update(b"\0")
        with file_path.open("rb") as handle:
            while chunk := handle.read(_DIGEST_CHUNK):
                digest.update(chunk)
        digest.update(b"\0")
    return f"sha256:{digest.hexdigest()}"


def _hub_offline() -> bool:
    try:
        from huggingface_hub import constants
    except ImportError:  # mock/CI profile: no hub client, nothing is downloaded anyway
        return False

    return bool(constants.HF_HUB_OFFLINE)


def _default_snapshot_download(**kwargs) -> str:
    from huggingface_hub import snapshot_download

    return snapshot_download(**kwargs)


def resolve_weights(
    model: str,
    revision: str,
    *,
    hf_home: str | None,
    snapshot_download=_default_snapshot_download,
) -> ResolvedWeights:
    local = Path(model)
    if local.is_dir():
        return ResolvedWeights(path=local, revision=local_weights_digest(local))

    snapshot = Path(
        snapshot_download(
            repo_id=model,
            revision=revision,
            cache_dir=hub_cache_dir(hf_home),
            allow_patterns=ALLOW_PATTERNS,
            # huggingface_hub 1.x lists the revision's files before looking at the cache when
            # allow_patterns is set, which fails under HF_HUB_OFFLINE; ask for the cache only.
            local_files_only=_hub_offline(),
        )
    )
    if not _COMMIT_SHA.match(snapshot.name):
        raise RuntimeError(f"Cannot determine commit revision for {model!r} from snapshot {snapshot.name!r}")
    return ResolvedWeights(path=snapshot, revision=snapshot.name)
