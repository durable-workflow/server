# MySQL JSON text upgrade source plan

These files compile the selected planning step for Server #1016. They do not
implement an upgrader, assign a schema version, execute SQL, or qualify a target
catalog. The source is the exact post-#1032 main commit `c9a75838`.

`upgrade-plan.v1.json` pins the version-8 inputs and describes all 75 JSON
columns in 36 tables. Each table has one candidate ALTER, preserved column
attributes/positions, 75 unique enforced-check names, and a derived candidate
CREATE definition. The other 24 relations remain byte-identical. The complete
candidate catalog hash composes the source catalog with those 36 replacements.

The candidate CREATE strings are source-review inputs, not SHOW CREATE output
captured from MySQL. The implementation must qualify actual presentation and
check enforcement before adopting exact target catalog bytes. Catalog ordering
is an identity order; a fresh bootstrap still needs a valid foreign-key creation
order. It must create target definitions directly, without an old-JSON bootstrap
followed by ALTERs. Historical migration/catalog bytes remain unchanged.

`upgrade-vectors.v1.json` binds the exact plan hash. Its 22 required-observation
families include every before/after table interruption (72 cells), every column's
validation/null boundary, and the future precision/read/export/replay controls.
Seven numeric carriers retain raw JSON as strings and expected binary64/int64
identities; they are requirements, not observed database results. Existing
`json-doubles.json` bytes and published-PHP exceptions remain unchanged. Actual
typed error-code/envelope bindings are still required with the implementation.

MySQL >=8.0.16 and proven enforced checks are prerequisites before schema writes.
MariaDB stays on its existing independent catalog/version. Writers must be
stopped/fenced, every resumed table must be exactly old or exactly target, and
final ownership/ledger advancement follows complete target verification. A
post-cutover old backup restore requires its own write-reconciliation/RPO
review; no lossy down conversion is proposed.

The 24 pinned production query sites are a static review inventory. They do not
prove all document readers, preserved plans, or text-column behavior. Later
source changes must be reconciled. The actual bootstrap/flavor/version/catalog,
resumption, query/reader implementation, exact source/backend qualification,
Security review and General's invocation/release gates remain outstanding.
