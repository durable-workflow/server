from __future__ import annotations

import json as json_module


class URL:
    def __init__(self, path: str) -> None:
        self.path = path


class Request:
    def __init__(
        self,
        method: str,
        path: str,
        *,
        json: object = None,
        headers: dict[str, str] | None = None,
    ) -> None:
        self.method = method.upper()
        self.url = URL(path)
        self.headers = headers or {}
        self.content = b"" if json is None else json_module.dumps(json).encode("utf-8")


class Response:
    def __init__(self, status: int, *, json: object = None) -> None:
        self.status_code = status
        self._json = json

    def json(self) -> object:
        return self._json


class MockTransport:
    def __init__(self, handler) -> None:
        self.handler = handler


class AsyncClient:
    def __init__(
        self,
        *,
        base_url: str = "",
        transport: MockTransport | None = None,
        timeout: object = None,
    ) -> None:
        self.base_url = base_url
        self.transport = transport
        self.timeout = timeout

    async def request(
        self,
        method: str,
        path: str,
        *,
        json: object = None,
        headers: dict[str, str] | None = None,
    ) -> Response:
        if self.transport is None:
            raise RuntimeError("test transport was not installed")

        return self.transport.handler(Request(method, path, json=json, headers=headers))

    async def aclose(self) -> None:
        return None
