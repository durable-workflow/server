"""Published Python client for the separate Python and Rust worker originals."""

import asyncio
import importlib.metadata
import json
import os
import sys
import time
from pathlib import Path

from durable_workflow import Client


async def main():
    language, mode = sys.argv[1:]
    assert language in ('python', 'rust') and mode in ('long', 'loss')
    assert importlib.metadata.version('durable-workflow') == '2.8.0'
    budget = 420 if mode == 'long' else 30
    async with Client(
        os.environ['SERVER_URL'], token='synthetic',
        namespace=os.environ.get('NAMESPACE', 'default'),
    ) as client:
        info = await client.get_cluster_info()
        assert info['worker_protocol']['server_capabilities']['activity_lease_renewal'] is True
        started = time.monotonic()
        handle = await client.start_workflow(
            workflow_type='qualification.runtime-lease-' + language,
            task_queue='lease-qualification-' + language,
            workflow_id=os.environ['WORKFLOW_ID'], input=[360 if mode == 'long' else 120, mode],
            run_timeout_seconds=420, execution_timeout_seconds=420,
        )
        Path('/state/original.json').write_text(json.dumps({
            'language': language, 'mode': mode, 'workflow_id': handle.workflow_id,
            'run_id': handle.run_id, 'observer_seconds': budget,
        }))
        result = await handle.result(timeout=budget)
        elapsed = time.monotonic() - started
        assert result == [9007199254740993, None, 'λ'], result
        if mode == 'long':
            assert elapsed >= 360, elapsed
        else:
            assert elapsed < 30, elapsed
        Path('/state/result.json').write_text(json.dumps(result, ensure_ascii=False))
        print(json.dumps({'language': language, 'mode': mode, 'workflow_id': handle.workflow_id,
                          'run_id': handle.run_id, 'result': result,
                          'elapsed_seconds': elapsed}, ensure_ascii=False))


asyncio.run(main())
