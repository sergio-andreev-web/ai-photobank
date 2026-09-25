from __future__ import annotations

from fastapi import APIRouter, Request, Response

from ..models.registry import ModelRegistry
from ..schemas.errors import ModelNotReadyError
from ..schemas.health import HealthResponse, ReadyResponse

router = APIRouter(tags=["probes"])


@router.get("/health", response_model=HealthResponse)
def get_health(request: Request, response: Response) -> HealthResponse:
    registry: ModelRegistry = request.app.state.registry
    return HealthResponse(**registry.health())


@router.get("/ready", response_model=ReadyResponse)
def get_ready(request: Request, response: Response) -> ReadyResponse:
    registry: ModelRegistry = request.app.state.registry
    if not registry.ready:
        # Matches contracts/ai-api.openapi.yaml NotReady example (request_id: null);
        # /ready is a probe with no request_id concept of its own.
        raise ModelNotReadyError(request_id=None)
    return ReadyResponse(**registry.ready_payload())
