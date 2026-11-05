# Native SBOM generation source proposal

This is the remaining generation design for Server #1012, targeting the future
3.2 line. It is not an executable, accepted producer format or operation
admission. `generation_mode` stays null until the complete source and tool
closure are reviewed. Frozen 3.1 artifacts keep their recorded SBOM-free status.

## Subject and export authority

Each native architecture job must retain its actual successful smoke
observation, immutable local config ID, source commit, release, platform and
provider run/attempt/job. The preferred source candidate emits an additional
OCI archive from the same single-platform build result. It may be produced
before smoke, but cannot supply SBOM authority until its complete config/layer
graph matches the actual successfully smoked config. Never rebuild or resolve
a mutable name to obtain a supposedly equivalent input. The independently verified
published index/child/config tuple remains the attestation authority. An archive
hash or an exporter-generated manifest is not a replacement registry subject.

The proposed offline input is one unnamed OCI-layout tar with uncompressed
layers. [Multiple exporters](https://docs.docker.com/build/exporters/)
allow a second output without a second build. The candidate OCI parameters are
`type=oci,dest=native-sbom/input.oci.tar,compression=uncompressed,force-compression=true,oci-mediatypes=true`.
Force compression is explicit so cached compressed layers cannot silently
enter this input profile. The native Release's existing named registry output
would remain separate; the PR image's daemon output would carry its existing
owned smoke name. Do not use global tags to name the additional archive:
[Buildx 0.38.1](https://github.com/docker/buildx/blob/v0.38.1/build/opt.go)
copies those names to every image exporter.

Output order is part of this proposal. Put the unnamed OCI exporter first and
the existing named registry (or PR daemon) exporter last. The exact
[BuildKit response merger](https://github.com/moby/buildkit/blob/v0.34.2/solver/llbsolver/export.go)
collects results by exporter position, then overwrites duplicate response keys
in that order. Appending OCI after the registry output could replace the
publisher's `containerimage.digest` with an archive-specific digest. Future
source controls must reject that order and independently bind the resulting
registry digest/config to the actual immutable registry and smoke observations.
An aggregate build response alone is not registry-subject authority. Preserve
the existing registry output arguments and never substitute the archive digest
when a publication or smoke read fails.

Read-only historical provider log `42056550973` / job `127346371667` records
Buildx 0.38.1, BuildKit 0.34.2 and Engine 29.4.2. This is existing-tool evidence,
not a new exporter invocation or future runtime qualification. The exact
[BuildKit OCI exporter](https://github.com/moby/buildkit/blob/v0.34.2/exporter/oci/export.go)
selects containerd's skip-Docker-manifest option and adds a UTC creation
annotation; a single manifest descriptor may omit its platform. Those known
presentation fields cannot replace config/platform authority. Unknown names,
annotations or additional graphs still refuse. Actual exporter identity and
produced format must be independently qualified before adoption.

Docker-save hybrid input remains unsupported. Its possible additional image
references/metadata require a different reviewed adapter; it is not a fallback
when the proposed OCI export or guard fails.

The archive guard must stream under a fixed total-byte/member/deadline bound,
without extracting to the host. It must reject duplicate names, traversal,
absolute paths, links, special/sparse files, unsupported header extensions,
truncation, unrelated image graphs and unrecognized formats. Require exactly
one selected image; hash the complete original config bytes against the smoked
config ID; and hash each uncompressed layer against that config's ordered
`rootfs.diff_ids`. Config equality alone does not authenticate layer contents.
Any additional OCI index/manifest must resolve to the same complete graph;
unreferenced or contradictory members refuse. A generated archive manifest may
differ from the registry manifest because its layer representation differs.

Export into an exclusively owned private directory. Retain the original regular
file identity, size and hash through validation, read-only scanner mounting and
post-scan revalidation. A replaced path, changed inode/bytes, unknown reader
state or failed export produces no SBOM authority. Exact exporter-format
fixtures and old-config/new-config, reordered/changed-layer, extra-image,
duplicate/path/link/truncation countercontrols are required before adoption.

## Confined generation and validation

The proposed scanner input is exclusively the held archive. The pinned
[Syft 1.55.0 source](https://github.com/anchore/syft/blob/f15c3343e29dfb20cf6d82c820e7090b49808c96/cmd/syft/internal/commands/scan.go)
supports explicit `--from oci-archive`; omitting the source selection can
fall back to a daemon or registry. The future fixed invocation must select the
archive source, exact platform, SPDX JSON and intended filesystem scope
explicitly, with automatic update/config discovery disabled by reviewed inputs.
No daemon socket, registry access, host credentials or repository configuration
may reach the scanner. Its platform-specific image child/config and actual
Syft version must match the committed tool pins before accepting output.

Both scanner and validator must run as an explicit nonroot UID/GID, with a
read-only root, no capabilities, no-new-privileges, no network or published
ports, and bounded tmpfs/scratch, CPU, memory, PIDs, output and absolute cleanup
deadlines. Mount only the sealed input and reviewed tool/dependency files
read-only. Bound scanner stdout to the existing 16-MiB document limit and retain
the original bytes; stderr is bounded diagnostics, never provider authority.
Source tests must inspect the complete effective create/start arguments and
reject an extra mount, environment key, source, privilege or mutable tool name.

The validator uses the independently pinned Python child/config and installer
source identity, held hash-checked wheels and the unchanged architecture-specific
requirements. Its install is offline with no dependency resolution or ambient
site/config fallback. Effective isolated Python/pip versions and complete
installed dependency identities must match before importing the schema checker.
The pinned vendored schema denies remote retrieval. Validation returns bounded
hash/count observations; it does not rewrite the original SPDX asset.

Use existing job deadlines and the reviewed exact-owner cleanup mechanism,
extended narrowly for the new kind/scope and bound helper bytes. Always-run
cleanup must inspect exact IDs plus owner labels, retain the original failure,
and refuse foreign/unknown resources. Do not add a by-name fallback or another
lifecycle controller. No positive receipt exists until body and cleanup both
succeed and an independent terminal absence readback passes.

## Provider and publication wiring

Retain each platform's original SPDX, config/export observations and cleanup
receipt as bounded provider artifacts bound to exact source/run/attempt/job.
The later publisher must independently verify those bindings against both
registries' actual immutable index/child/config tuples before signing each
platform predicate for each registry. Signing an index or provenance predicate
does not satisfy this contract.

The Release verifier must execute the fixed registry-bundle signature requests,
check current repository/owner identity and exact signer/source/tag/invocation,
and compare every current predicate with the retained document. All four
registry/platform slots must pass before returning the two raw SPDX files and
the checksum map. Existing assets require original byte equality; conflicting,
missing, failed or ambiguous evidence permits no writer or replacement.

Future 3.2 workflow wiring must preserve the shared promotion/Release lock and
revalidate provider evidence after protection approval. It must not apply the
new SBOM requirement retroactively to frozen 3.1. Normal source publication,
complete Security review and General's source/operation selections remain
separate prerequisites; this document selects no scan, build or release.
