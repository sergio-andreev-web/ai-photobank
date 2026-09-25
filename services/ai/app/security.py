"""Service API key auth (§63, §69 AI_SERVICE_API_KEY; contract: bearer, ADR-0010).

/health and /ready are unauthenticated (contract `security: []` override).
When AI_SERVICE_API_KEY is unset, requests are accepted without a key
(local development), matching contracts/ai-api.openapi.yaml.
"""
from __future__ import annotations

import hmac

from fastapi import Request

from .config import Settings
from .schemas.errors import UnauthorizedError


def _extract_bearer_token(authorization: str | None) -> str | None:
    if not authorization:
        return None
    scheme, _, token = authorization.partition(" ")
    if scheme.lower() != "bearer" or not token:
        return None
    return token


def require_api_key(request: Request) -> None:
    settings: Settings = request.app.state.settings
    if not settings.ai_service_api_key:
        return

    token = _extract_bearer_token(request.headers.get("Authorization"))
    if token is None or not hmac.compare_digest(token, settings.ai_service_api_key):
        raise UnauthorizedError()
