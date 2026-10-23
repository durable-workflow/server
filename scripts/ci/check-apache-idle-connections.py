#!/usr/bin/env python3
"""Keep idle clients from starving a fresh request in the production image.

Run inside an otherwise idle Server container after /up becomes live. The
static fixture avoids database work and leaves the PHP pool mostly idle.
"""

import collections
import http.client
import json
import os
from pathlib import Path
import threading
import time


CLIENTS = 24
BODY = b"keepalive capacity control\n"
fixture = Path("/app/public") / f"__dw_idle_connections_{os.getpid()}.txt"
stop = threading.Event()
lock = threading.Lock()
ready = set()
open_sockets = set()
errors = collections.Counter()
responses = 0


def load_client(index):
    global responses
    connection = None
    try:
        while not stop.is_set():
            if connection is None:
                connection = http.client.HTTPConnection("127.0.0.1", 8080, timeout=5)
            try:
                connection.request("GET", "/" + fixture.name)
                response = connection.getresponse()
                body = response.read()
                if response.status != 200 or body != BODY:
                    raise ValueError(f"unexpected static response {response.status}")
                with lock:
                    ready.add(index)
                    responses += 1
                    if connection.sock is not None:
                        open_sockets.add(index)
                    else:
                        open_sockets.discard(index)
            except Exception as error:
                with lock:
                    errors[type(error).__name__] += 1
                    open_sockets.discard(index)
                connection.close()
                connection = None
            stop.wait(0.5)
    finally:
        if connection is not None:
            connection.close()


with fixture.open("xb") as output:
    output.write(BODY)
fixture.chmod(0o644)
threads = [threading.Thread(target=load_client, args=(index,)) for index in range(CLIENTS)]
controls = []
window_seconds = 0
try:
    for thread in threads:
        thread.start()
    deadline = time.monotonic() + 25
    while time.monotonic() < deadline:
        with lock:
            if len(ready) == CLIENTS:
                break
        time.sleep(0.1)
    with lock:
        initial = {"ready_clients": len(ready), "persistent_at_start": len(open_sockets)}
    window_start = time.monotonic()
    for index in range(3):
        stop.wait(max(0, window_start + index * 5 - time.monotonic()))
        started = time.monotonic()
        connection = http.client.HTTPConnection("127.0.0.1", 8080, timeout=5)
        try:
            connection.request("GET", "/up", headers={"Connection": "close"})
            response = connection.getresponse()
            response.read()
            controls.append({"status": response.status, "seconds": time.monotonic() - started})
        except Exception as error:
            controls.append({"error": type(error).__name__, "seconds": time.monotonic() - started})
        finally:
            connection.close()
    stop.wait(max(0, window_start + 15 - time.monotonic()))
    window_seconds = time.monotonic() - window_start
finally:
    stop.set()
    for thread in threads:
        if thread.ident is not None:
            thread.join(timeout=7)
    fixture.unlink()

result = {
    "clients": CLIENTS,
    **initial,
    "offered_window_seconds": window_seconds,
    "control_timeout_seconds": 5,
    "controls": controls,
    "load_responses": responses,
    "load_errors": dict(errors),
    "threads_remaining": sum(thread.is_alive() for thread in threads),
}
print(json.dumps(result, indent=2))
assert result["ready_clients"] == CLIENTS, "Not every load client reached the static fixture"
assert not result["load_errors"], "The load itself failed"
assert not result["threads_remaining"], "A load thread did not stop"
assert all(control.get("status") == 200 for control in controls), "Idle clients starved a fresh request"
