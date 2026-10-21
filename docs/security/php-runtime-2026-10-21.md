# Critical PHP runtime update, 21 October 2026

The PHP project disclosed two critical vulnerabilities affecting web runtimes,
including Apache with mod_php, PHP-FPM and FrankenPHP:

- CVE-2026-109618, out-of-memory request-state corruption.
- CVE-2026-109619, stale request-owned global state.

Read the [upstream advisory](https://www.php.net/archive/2026.php#2026-10-21-1).
The fixed PHP versions are 8.2.35, 8.3.36, 8.4.27 and 8.5.12. Older branches
require a vendor backport or an upgrade to a supported patched release.

## Update an installed Server

Use a [Server release](https://github.com/durable-workflow/server/releases) whose
notes record the patched PHP version and exact published image digest. Pull that
image and replace every API, worker, scheduler and bootstrap role. An unchanged
running container does not adopt a newly pulled image.

Verify the actual `PHP_VERSION` in each replacement role and the PHP version used
by the HTTP handler. Do not infer it from a floating base tag, environment
variable, application version, cached build or startup banner. Check readiness,
authentication, worker polling/completion and your external-payload transport
after the rollout.

Published version tags remain immutable. Historical images, including frozen
conformance references, keep their original bytes and do not become patched.
Rollback to a vulnerable generation restores service only temporarily and must
be followed by a forward update.

## Image build provenance

### One-time Server 2.9 maintenance patch

Server 2.9.12 is an image-only security patch on the 2.9.11 application and
dependency source. Its tag does not republish Helm chart 0.2.1 or change the
current chart's default Server version. Existing immutable images and chart
packages remain unchanged. Choose the exact patched image through the chart's
`image.tag` override, or use its digest setting after verifying the published
release evidence. Replace every runtime role and verify the actual PHP version.

The generated chart files in this maintenance source are not a new published
chart artifact. Chart publication remains on current main. This one-time patch
does not establish a separate long-term support branch.

### Signed PHP source

While the official Apache image is being published, this image builds PHP
8.3.36 from the upstream release tarball on an immutable Apache foundation.
The build verifies the tarball's fixed SHA-256 and detached signature against
the published PHP release keyring, whose SHA-256 is also fixed. Configure
options follow docker-library/php revision
`f6048b5f6e3c2202ee082665a4ccc0d90de291a3`.

The resulting CLI, Apache module, runtime libraries, headers and extension
source replace the older foundation's PHP artifacts. The build checks the
actual CLI version before installing the application. Release qualification
must additionally check the real Apache handler and normal API, worker and
binary transport behavior. Source checksums or an image label alone do not
establish a working patched image.

## Configuration reductions are incomplete

The Server image disables automatic POST parsing and session upload-progress
tracking before request handling. Finite POST/upload settings preserve the
default 64 MiB binary transport; the API separately enforces its JSON and
authenticated binary limits. Large payloads use the runtime's binary transport,
not PHP multipart uploads.

These settings reduce exposure but do not fix either vulnerability. Follow the
upstream guidance for an installation that cannot update immediately, including
its memory-limit and container-boundary tradeoffs. Only a patched PHP runtime
completes the correction. Do not treat a configuration-only image as patched.
