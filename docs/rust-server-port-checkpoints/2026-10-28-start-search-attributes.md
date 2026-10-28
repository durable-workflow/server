# October 28, search attributes on start

- **Before.** Native answered any start that named search attributes with
  `501 start_search_attributes_unavailable`, and accepted only an empty
  object.
- **Contract** (published 2.11.5, Core 2.15.1;
  `WorkflowController::start`,
  `SearchAttributeValueValidator::validateForNamespace`,
  `DefaultWorkflowControlPlane::seedTypedVisibilityMetadata`):
  - **Validation.** The controller validates the values against the
    namespace's registry after the memo size check and before the
    reservation and routing checks. Each refusal is
    `422 validation_failed`, with the validator's messages under
    `search_attributes`, and creates no run. A registry with definitions
    enforces its schema; without definitions, scalar types are inferred.
  - **Storage.** Core's start upserts the values with the validated types
    at sequence 0, before the start command. A null value is not stored.
  - **Core normalization.** Core normalizes the values while seeding the
    new run (`UpsertSearchAttributesCall`, `validateAttributeSet`), after
    the controller's reservation and routing checks and Core's duplicate
    check. The controller reports Core's `LogicException` under
    `workflow_type`. Without definitions, a 65-character key passes the
    registry's 128-byte limit but not Core's 64-character rule, and 101
    attributes exceed Core's per-run count; both answer
    `422 validation_failed` under `workflow_type`. A workflow id reserved in
    another namespace is refused as reserved before either. (Found in
    review.)
  - **Events.** `StartAccepted` and `WorkflowStarted` record the values as
    the request named them, nulls included; there is no
    `SearchAttributesUpserted` event. An empty object records null on
    `StartAccepted` and leaves the field off `WorkflowStarted`.
  - **Reads.** The run description and the visibility list read the typed
    rows, so a datetime shows with microseconds.
- **Fixture.** `start-search-attributes`, raw HTTP in two fresh namespaces.
  Cases:
  - typed (seven registered types, including a visibility query);
  - unknown key, wrong type, numeric strings, a list instead of a map;
  - an empty object, a null value;
  - inferred scalars, and an unregistered list value;
  - a 65-character key, the same key on a workflow id reserved in another
    namespace, and 101 attributes.
- **Native.**
  - `validate_start_search_attributes` reuses the worker upsert's
    registry validator before the reservation and routing checks, reporting
    under `search_attributes`.
  - `StartSearchAttributes::prepare` runs Core's normalization and per-run
    limits inside `start_in`, after the existing-instance branch, reporting
    under `workflow_type`. Memo and attributes reach `start_in` as one
    `StartMetadata`.
  - `write_typed_rows` is shared with the worker upsert.
  - `start_in` writes the rows and records the values on both start events.
  - Schedule starts pass none, as before.
- **Gap kept.** With several invalid values, native reports the errors in
  sorted key order rather than request order (the port's key-order gap). The
  fixture's two-error case names its keys in sorted order.
- **Tests.** `start_search_attributes.rs` (registered, refused and inferred
  starts, and Core's refusals after the reservation check). The start test in `execution.rs` that expected the 501 now
  expects an inferred value.
