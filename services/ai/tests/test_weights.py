"""Weight resolution and revision reporting (ADR-0009, §68) without network."""
from __future__ import annotations

from pathlib import Path

import pytest

from app.models.weights import ALLOW_PATTERNS, hub_cache_dir, local_weights_digest, resolve_weights

SHA = "e8e487298228002f3d8a82e0cd5c8ea9c567f57f"


def test_hub_model_revision_is_resolved_commit_sha(tmp_path, monkeypatch):
    monkeypatch.setattr("huggingface_hub.constants.HF_HUB_OFFLINE", False)
    snapshot = tmp_path / "hub" / "models--google--siglip2" / "snapshots" / SHA
    snapshot.mkdir(parents=True)
    calls = []

    def fake_snapshot_download(**kwargs):
        calls.append(kwargs)
        return str(snapshot)

    resolved = resolve_weights(
        "google/siglip2-so400m-patch14-384", "main", hf_home=str(tmp_path), snapshot_download=fake_snapshot_download
    )
    assert resolved.revision == SHA
    assert resolved.path == snapshot
    assert calls == [
        {
            "repo_id": "google/siglip2-so400m-patch14-384",
            "revision": "main",
            "cache_dir": str(tmp_path / "hub"),
            "allow_patterns": ALLOW_PATTERNS,
            "local_files_only": False,
        }
    ]


def test_offline_mode_resolves_from_the_cache_only(tmp_path, monkeypatch):
    monkeypatch.setattr("huggingface_hub.constants.HF_HUB_OFFLINE", True)
    snapshot = tmp_path / "hub" / "models--google--siglip2" / "snapshots" / SHA
    snapshot.mkdir(parents=True)
    calls = []

    def fake_snapshot_download(**kwargs):
        calls.append(kwargs)
        return str(snapshot)

    resolved = resolve_weights(
        "google/siglip2-so400m-patch14-384", SHA, hf_home=str(tmp_path), snapshot_download=fake_snapshot_download
    )
    assert resolved.revision == SHA
    assert calls[0]["local_files_only"] is True


def test_hub_snapshot_without_commit_sha_is_rejected(tmp_path):
    odd = tmp_path / "not-a-sha"
    odd.mkdir()
    with pytest.raises(RuntimeError):
        resolve_weights("org/model", "main", hf_home=None, snapshot_download=lambda **_: str(odd))


def test_cache_dir_defaults_to_environment_when_hf_home_unset():
    assert hub_cache_dir(None) is None
    assert hub_cache_dir("/cache/hf") == str(Path("/cache/hf") / "hub")


def _write_weights(directory: Path, payload: bytes) -> None:
    (directory / "sub").mkdir(parents=True, exist_ok=True)
    (directory / "config.json").write_text('{"a": 1}')
    (directory / "sub" / "model.safetensors").write_bytes(payload)


def test_local_directory_revision_is_stable_content_digest(tmp_path):
    first, second = tmp_path / "a", tmp_path / "b"
    _write_weights(first, b"weights-v1")
    _write_weights(second, b"weights-v1")

    resolved = resolve_weights(str(first), "main", hf_home=None, snapshot_download=None)
    assert resolved.path == first
    assert resolved.revision.startswith("sha256:")
    assert len(resolved.revision) <= 255
    # Same content in another location -> same revision; changed weights -> new revision.
    assert local_weights_digest(second) == resolved.revision
    _write_weights(second, b"weights-v2")
    assert local_weights_digest(second) != resolved.revision


def test_empty_local_directory_is_rejected(tmp_path):
    with pytest.raises(RuntimeError):
        local_weights_digest(tmp_path)
