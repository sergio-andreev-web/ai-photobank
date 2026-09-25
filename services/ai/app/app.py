from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.exceptions import RequestValidationError

from .concurrency import InferenceLimiter
from .config import Settings, get_settings
from .exception_handlers import (
    ai_service_error_handler,
    unhandled_exception_handler,
    validation_error_handler,
)
from .models.registry import ModelRegistry
from .request_id import RequestIdMiddleware
from .routers import analyze, embeddings, health
from .schemas.errors import AiServiceError

logger = logging.getLogger("ai_service")


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or get_settings()

    docs_kwargs = {}
    if settings.is_production:
        # §63: "/docs FastAPI запрещать в production либо защищать".
        docs_kwargs = {"docs_url": None, "redoc_url": None, "openapi_url": None}

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        try:
            app.state.registry.startup()
        except Exception:
            logger.exception("ai_service.startup_failed")
        yield

    app = FastAPI(title="AI Photobank — AI Service", version="1.0.0", lifespan=lifespan, **docs_kwargs)

    app.state.settings = settings
    app.state.registry = ModelRegistry(settings)
    app.state.limiter = InferenceLimiter(settings.ai_concurrency)

    app.add_middleware(RequestIdMiddleware)

    app.add_exception_handler(AiServiceError, ai_service_error_handler)
    app.add_exception_handler(RequestValidationError, validation_error_handler)
    app.add_exception_handler(Exception, unhandled_exception_handler)

    app.include_router(health.router)
    app.include_router(analyze.router)
    app.include_router(embeddings.router)

    return app
