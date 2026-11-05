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
