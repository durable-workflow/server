# Native SBOM source inputs

`tool-pins.v1.json` records the immutable Syft 1.55.0 two-platform tool map and
`actions/attest` v4.2.2 commit reviewed in Server #1012, comments 6644320041,
6648408237 and Security 6649211450. The upstream tool index includes additional
platforms; only the two explicit child/config pairs are selected. Neither tool
identity is a Server SBOM subject. The upstream Syft tag is observed unsigned.
The generator binaries have not been run or qualified by this metadata record.

The new schema input is an unmodified copy of **SPDX Project, SPDX Specification
2.3.1**, `schemas/spdx-schema.json`, from the SPDX Project's
[immutable source](https://github.com/spdx/spdx-spec/blob/6f2cb47d13db19b12c23885573b22ef32ec1ada5/schemas/spdx-schema.json).
The upstream [CC-BY-3.0 license](https://github.com/spdx/spdx-spec/blob/6f2cb47d13db19b12c23885573b22ef32ec1ada5/LICENSE)
is included verbatim as `SPDX-SCHEMA-LICENSE`; attribution is retained here.
The pin map records exact source/blob, size and SHA-256. This schema validates
SPDX-2.3 documents using JSON Schema 2019-09. Its references are all local;
future validation must disable remote retrieval, including instance-supplied
schema URLs. The upstream `spdxVersion` property is only a string, so semantic
validation must additionally require the exact `SPDX-2.3` literal; schema success
alone is insufficient. Vendoring this schema does not qualify a schema-validator
package.

The validator dependency closure now names the exact metadata proposal below;
generation mode and confinement remain unfinished, never permissive defaults. No workflow currently consumes
these inputs. The complete generation/confinement, independent schema/semantic
validation, exact Server child predicates, retained raw bytes/checksum map,
Release asset equality/idempotency and fail-before controls remain required
before a normal source/CI and Security review. No pull, build, tool/scan,
attestation, registry, Release or frozen-subject operation is admitted here.

The source-only `../native_sbom_policy.py` now performs fixed-schema validation
with remote retrieval disabled, followed by exact SPDX/tool identity, namespace,
unique element IDs and internal relationship checks. Declared files must be
linked; a package with `filesAnalyzed=true` must have file coverage. An explicit
`false` does not invent complete file enumeration. It retains raw SHA-256/size
separately from canonical predicate equality and requires distinct platform
namespaces. Eight synthetic test families cover these boundaries. Their formal
schema checks use the already-installed jsonschema4.19.2 solely as local source
check tooling. No dependency was installed or qualified, and direct execution of
the library refuses until the executable/dependency closure is completed.

The candidate offline validator closure is `validator-pins.v1.json`: official
Python 3.13.18 slim-bookworm immutable index and selected amd64/arm64 child/config
pairs, plus JSON Schema 4.26.0, attrs26.1.0, specifications2025.9.1,
referencing0.37.0 and rpds-py2026.9.1. Each package has a concrete public wheel
URL/size/SHA-256; the rpds wheel is CPython3.13/Linux-architecture specific.
The official-images source block and selected active dependency edges are
retained. No optional format extras are selected; typing-extensions is inactive
for Python3.13. The two derived requirements files use local `/deps` paths for
an eventual network-disabled `--no-index --no-deps --require-hashes` install.
They do not authorize a download or install. Python's observed image user is
unset/root; future confinement must explicitly enforce a nonroot UID, read-only
filesystem, bounded scratch/output/resources, no capabilities, no-new-privileges,
no network, daemon socket or host credential mounts. That execution source is
still incomplete. Neither image layers nor wheels were acquired or executed.

The proposed deterministic raw assets are `server-linux-amd64.spdx.json`,
`server-linux-arm64.spdx.json` and `server-sbom-manifest.json`.
`exact_release_asset_bytes` rejects absent, extra, renamed, empty, oversized or
changed bytes for this set. Canonically equivalent JSON is insufficient for
existing raw assets. This helper accepts only previously verified expected
bytes; it does not authenticate a checksum map, signature, provider asset or
image tuple. The future caller must verify those independently and bound each
download before calling it. Ten synthetic test families now cover the source
library. No transport, replacement or publication is implemented by this helper.

The source library also constructs the fixed `gh attestation verify` request:
one selected child under each fixed registry name, exact release signer workflow,
tag ref and source commit, the SPDX 2.3 predicate type and self-hosted refusal.
The index cannot be substituted for the requested child. A separate comparison
requires the authenticated certificate invocation to match the exact release
run and attempt, with no prefix or suffix acceptance. Fourteen source test
families pass. These functions neither run `gh` nor authenticate caller-supplied
tuples/strings; future index membership, provider/signature verification and
bounded transport remain required before using their results.

The exact-index byte guard verifies the expected raw digest and binds exactly
one Linux child for each selected architecture, independently of array order.
Missing/extra/duplicate/unknown platforms, changed bytes, wrong children,
index-as-child and nested-index descriptors refuse. This does not authenticate
the expected publication tuple or a child's fetched config/source; those remain
future transport checks. Seventeen synthetic source test families cover the
library, which remains unwired and refuses direct execution.

`child_identity` now separately checks content-addressed manifest and config
bytes (each at most 1 MiB), the manifest's exact config digest/size, actual
Linux architecture/variant and the expected source/release labels. It accepts
the image/config pairs defined by the
[OCI image manifest specification](https://github.com/opencontainers/image-spec/blob/v1.1.1/manifest.md)
and [Docker schema 2 specification](https://distribution.github.io/distribution/spec/manifest-v2-2/).
An index, changed raw bytes, mismatched config descriptor or correctly hashed
config for another platform/source/tag refuses. Twenty synthetic source test
families pass. The expected tuple must already come from independently verified
publication/index and smoke evidence. This guard does not authenticate those
inputs, fetch an object, validate layer/archive contents, run smoke or scan,
or authorize an execution. Generation mode remains null and direct execution
still refuses.

The SBOM verification request explicitly uses `--bundle-from-oci` for each
fixed registry/child. The [GitHub CLI verifier contract](https://cli.github.com/manual/gh_attestation_verify)
states that the default retrieves bundles from GitHub, even for an OCI image,
so the default cannot prove the SBOM attachment exists in each registry.
Registry-bundle mode checks certificate names as recorded at signing time.
The future executable must additionally bind the fixed repository ID/current
publication identity and authenticated exact release invocation, rather than
infer current repository ownership from recorded names alone. This source
correction runs no verifier and does not complete those transport gates.

`require_certificate_identity` checks claims already extracted from successful
cryptographic verifier output. It requires the fixed repository/owner numeric
IDs as strings, exact GitHub issuer and repository URIs, source/tag,
native-release signer URI/digest, hosted runner, dispatch event and exact
run/attempt URI. The field names follow the
[sigstore-go 1.3.0 certificate extensions](https://github.com/sigstore/sigstore-go/blob/v1.3.0/pkg/fulcio/certificate/extensions.go)
used by [GitHub CLI 2.103.3](https://github.com/cli/cli/blob/v2.103.3/go.mod).
Read-only repository metadata independently matches the fixed IDs. Twenty-two
synthetic source families pass, including same-name/different-ID and every
missing/wrong-type/changed claim. Passing a dictionary does not authenticate it.
The future executable still must verify the actual CLI/tool closure, current
provider identity, selected statement/subject/predicate, and both registry
origins. No attestation or signature was fetched or verified by these tests.

`verified_statement_matches` composes these checks for one already-authenticated
certificate/statement pair. The statement must be in-toto v1 with the exact
SPDX predicate type and exactly one fixed-registry subject whose SHA-256 is the
selected Server child. Its predicate must match the retained, independently
validated SPDX document canonically; raw asset identity remains separate.
Twenty-four synthetic source families now cover this composition, including
index/provenance/foreign-registry substitutions and altered signed predicates.
The future transport still must authenticate and select the actual bounded
current-run pair, bind publication/index/config and generator custody, reject
ambiguous current predicates, and verify both registry origins. A caller-supplied
dictionary or a passing synthetic control supplies none of those authorities.

`release_asset_manifest` compiles the proposed unsigned checksum-map bytes from
independently verified release, two-platform child/config tuples and the two
retained raw documents. It binds the fixed repository/registries, tag/source,
index/run/attempt, platform-specific asset names, raw SHA-256/size, canonical
predicate hashes and distinct namespaces. Missing/ambiguous platforms and
interchanged child/config identities refuse. Input map order does not alter the
output; reformatting raw SPDX does. Twenty-six synthetic families cover the
source library. This map is not a signature or qualification receipt, and the
future caller must establish generation and signed-subject custody first.
Existing Release assets must retain their original bytes, not be reconstructed
through this compiler.
