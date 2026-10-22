# October 21, search attribute upserts

- **Found by.** The Sample App's search attribute scenario
  (`scripts/sdk-search-attributes.sh`) against native. Its Rust workflow's
  first `upsert_search_attributes` command was refused with
  `unsupported_workflow_command`.
- **Validation.** Native applies the Server's `SearchAttributeValueValidator`
  to each upsert:
  - Keys must start with a letter and contain only letters, digits and
    underscores, within `DW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH` bytes.
  - A value must suit its registered type. A declared type must agree with
    the registration. A namespace with custom definitions refuses an
    unregistered key.
  - Without definitions, a value infers its type: bool, int, float,
    keyword, string (longer than 255 characters or containing whitespace)
    or keyword list.
  - Refusals answer `422` with PHP's messages under
    `commands.N.attributes`.
- **Normalization and storage.** The Workflow package's
  `UpsertSearchAttributesCall` trims strings and list entries and treats an
  empty string as a deletion. `SearchAttributeUpsertService` then writes typed
  rows (`string`, `keyword`, `keyword_list`, `int`, `float`, `bool`,
  `datetime`) and deletes nulls. A run is limited to 100 attributes and 64 KB.
- **History.** `SearchAttributesUpserted` records `sequence`, the
  normalized `attributes`, the canonical `attribute_types` and the `merged`
  view.
- **Capability.** `attribute_types` needs worker protocol 1.16
  (`typed_search_attributes_unavailable`). It also needs a lease owner that
  advertises `typed_search_attributes`
  (`workflow_metadata_capability_not_advertised`). Registering that
  capability needs protocol 1.16. A worker without it cannot claim a run
  whose history has typed upserts. Worker discovery lists the command and
  PHP's `typed_search_attributes` block.
- **Continue-as-new.** The continued run inherits the typed rows at sequence
  1 instead of the continuation being refused, and an upsert may precede
  `continue_as_new` in the same task.
- **Describe.** Reads keyword lists through each backend's JSON decoding.
  PostgreSQL stores them in a `json` column.
- **Remaining.** Lists and typed list queries follow in the same branch
  (`2026-10-21-visibility-queries.md`). Other remaining differences are
  recorded as known gaps: start-time search attributes, child-run
  inheritance, datetime forms and the limit refusal.
- **Differential probe.** Published PHP 2.5.14 and native received the same
  requests: twelve upsert completions covering registered, declared and
  inferred types, bad keys, wrong types, malformed `attribute_types`, empty,
  null, list and string `attributes`, an over-long key and a non-list value.
  - The status, reason and every validation message match.
  - A valid upsert's `SearchAttributesUpserted` payload matches: attributes,
    types and merged view.
  - Describe matches except for an offset datetime. There PHP shifts the
    instant (workflow#898) and native keeps it.
  - Native's message order differs only when one command has several key
    errors, because native visits object keys in sorted order rather than
    request order.
- **Verification.**
  - Unit tests cover type resolution, PHP's messages, normalization and
    the limits.
  - New tests in `tests/execution/search_attribute_upserts.rs` cover:
    - the Sample App's typed values through two upserts;
    - validation, protocol and capability refusals that record nothing;
    - continue-as-new inheritance.
