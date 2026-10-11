# Published SDK execution directions

Observations keep the actual claim and receipt fields needed by the reader.
Separate per-target phase sidecars retain every full HTTP request/response and
exact response bytes, including repeated discovery and capability metadata.

This opt-in Server #325 profile defines all nine PHP/Python/Rust workflow and
activity directions using installed published SDKs. Each direction starts a new
run before worker registration, stops a workflow-only author after scheduling,
executes the original activity on its separate queue, then completes in a cold
workflow worker. Exact int64, Unicode and null values and full history prefixes
remain checked.

The separate manifest pins the PHP Server reference, embedded engine, installed
SDK sources and registry checksums. Existing fixtures and manifests remain
unchanged. Embedded runs the canonical PHP author and explicitly marks SDK
transport inapplicable.

The adapters and reader are implemented; actual PHP/native/embedded
qualification is pending. The existing shared workflow builds each locked SDK
adapter once beside its earlier adapters, reuses the registry and target cache,
and distributes only the binary, installed Python packages and identity
receipts. Debug information and incremental compilation stay disabled.

After the owning isolated database service, network and native target are
prepared, run:

```bash
bash scripts/conformance/server-parity/sdk-direction-adapters.sh
bash scripts/conformance/server-parity/sdk-direction-profile.sh BACKEND NATIVE_URL
bash scripts/conformance/server-parity/sdk-direction-cleanup.sh BACKEND
```

Set `RESOURCE_SCOPE` to the owning network and supply `MYSQL_IMAGE` or
`POSTGRES_IMAGE` for that backend, as the shared workflow does. The profile
starts the exact PHP reference and embedded engine in independent databases;
the native URL must use its separately bootstrapped database. Both workflow
and activity queues are dedicated to this profile. Cleanup runs after success
or failure, before the owning database service and network are removed. Exact
transport bytes, phase outcomes and terminal container states are retained in
the normal evidence artifact. No Cargo target or registry tree is uploaded.

This profile does not establish child directions, full language parity,
performance, migration or cutover.
