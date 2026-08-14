from __future__ import annotations

import json


def encode(value: object) -> dict[str, str]:
    return {"blob": json.dumps(value), "codec": "avro"}


def decode(blob: str, *, codec: str) -> object:
    if codec != "avro":
        raise ValueError("expected the avro codec")

    return json.loads(blob)
