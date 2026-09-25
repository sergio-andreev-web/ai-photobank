"""X-Request-ID propagation (§64, ADR-0002/ADR-0010).

Laravel always sends X-Request-ID for /v1/*; when absent the AI service
generates one and returns it in the response header on every request,
including /health and /ready.
"""
from __future__ import annotations

import uuid

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import Response

REQUEST_ID_HEADER = "X-Request-ID"


class RequestIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next) -> Response:
        incoming = request.headers.get(REQUEST_ID_HEADER)
        resolved = incoming if incoming else str(uuid.uuid4())
        request.state.request_id = resolved
        response = await call_next(request)
        response.headers[REQUEST_ID_HEADER] = resolved
        return response
