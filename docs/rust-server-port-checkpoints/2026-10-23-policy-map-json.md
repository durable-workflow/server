# October 23, policy maps must be JSON objects

- **Found by.** PHP Server #705 (merged to main at `f67f2816e`, not yet in a
  published image). Published PHP turned malformed admission-policy JSON
  into an empty policy, so a configured cap could disappear without notice.
  Native had the same defect.
  - `DW_TASK_QUEUE_ADMISSION_OVERRIDES` and
    `DW_NAMESPACE_ADMISSION_OVERRIDES` accepted malformed JSON.
  - All five maps native refuses accepted `null`, `[]`, booleans and
    numbers.
  - The external-payload namespace overrides, which native honors, became
    an empty policy when malformed.
- **Change.** The six maps PHP now reads with `EnvAuditor::jsonObject` are
  read by the same rule in `config_audit::json_object`:
  - the value is either unset or raw text that decodes to a JSON object;
  - Laravel's `env()` conversions do not apply, so `null`, `(null)`,
    `empty`, quoted text and an empty value are refused;
  - a set `DW_*` name wins over its legacy name even when the `DW_*` value
    is invalid;
  - the startup refusal names the variable it read, never the value.

  Native still refuses a non-empty object in the five maps it has no policy
  for. It still honors `DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES`, and the
  external payload quota now reads that value with the same decoder.
- **Reference.** This follows unreleased PHP source, not the published
  2.11.1 image. For any valid configuration the two behave the same. The
  only values refused are ones published PHP silently discarded and the
  next PHP image refuses at boot.
- **Test.** `policy_maps_must_be_json_objects_like_php_705` covers all six
  maps under both names, 17 invalid values, padded whitespace around `{}`,
  DW-over-legacy precedence in both directions, and that the refusal never
  repeats the value. The shipped Compose and Helm values (`{}` for the
  durable maps) still start.
