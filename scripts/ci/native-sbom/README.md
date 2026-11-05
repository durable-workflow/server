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

The still-null validator dependency closure and generation mode are explicit
unfinished source work, not permissive defaults. No workflow currently consumes
these inputs. The complete generation/confinement, independent schema/semantic
validation, exact Server child predicates, retained raw bytes/checksum map,
Release asset equality/idempotency and fail-before controls remain required
before a normal source/CI and Security review. No pull, build, tool/scan,
attestation, registry, Release or frozen-subject operation is admitted here.
