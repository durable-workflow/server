#!/usr/bin/env python3
"""Resolve ghcr.io/durable-workflow/server:latest to one platform's digest.

Reads the public registry over HTTPS with an anonymous pull token; nothing is
pulled or run. Prints `ghcr.io/durable-workflow/server@sha256:...`.

Usage: resolve_latest_platform_digest.py linux/amd64|linux/arm64
"""
from __future__ import annotations

import json
import re
import sys
import urllib.request

REPOSITORY = "durable-workflow/server"
INDEX_TYPES = ("application/vnd.oci.image.index.v1+json",
               "application/vnd.docker.distribution.manifest.list.v2+json")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """A redirect could leave ghcr.io; refuse it instead of following."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        raise SystemExit(f"refusing redirect to {newurl}")


OPENER = urllib.request.build_opener(NoRedirect)


def get(url: str, headers: dict[str, str]) -> bytes:
    if not url.startswith("https://ghcr.io/"):
        raise SystemExit(f"refusing {url}")
    with OPENER.open(urllib.request.Request(url, headers=headers), timeout=30) as response:
        return response.read(1 << 20)


def main(argv: list[str]) -> int:
    if len(argv) != 2 or argv[1] not in ("linux/amd64", "linux/arm64"):
        print(__doc__, file=sys.stderr)
        return 2
    os_name, arch = argv[1].split("/")
    token = json.loads(get(f"https://ghcr.io/token?scope=repository:{REPOSITORY}:pull", {}))["token"]
    index = json.loads(get(f"https://ghcr.io/v2/{REPOSITORY}/manifests/latest",
                           {"Authorization": f"Bearer {token}", "Accept": ", ".join(INDEX_TYPES)}))
    if index.get("mediaType") not in INDEX_TYPES:
        raise SystemExit("latest is not a multi-architecture index")
    found = [entry["digest"] for entry in index.get("manifests", [])
             if entry.get("platform", {}).get("os") == os_name and entry["platform"].get("architecture") == arch]
    if len(found) != 1 or not re.fullmatch(r"sha256:[0-9a-f]{64}", found[0]):
        raise SystemExit(f"expected one {argv[1]} manifest, found {found}")
    print(f"ghcr.io/{REPOSITORY}@{found[0]}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
