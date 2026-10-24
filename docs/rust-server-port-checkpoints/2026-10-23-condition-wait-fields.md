# October 23, optional condition wait fields

- **The gap.** Native refused an `open_condition_wait` command without a
  `condition_key`, or without a `condition_definition_fingerprint` of the form
  `sha256:` followed by 64 hex digits. Published PHP accepts both.
- **How PHP 2.11.1 does it.** `normalizeOpenConditionWaitCommand` treats the key
  and the fingerprint as optional strings of any form. It trims them and leaves
  them out when empty. `applyOpenConditionWait` records only the fields that
  remain, on `ConditionWaitOpened` and on a condition timeout's
  `TimerScheduled`. Run summaries name a keyless wait "Waiting for condition",
  without a key. Observed live against the published image: a bare
  `open_condition_wait`, a key of `"  ready  "` with the fingerprint
  `"any form"`, and blank values all behave this way.
- **What native does now.** The same. The open wait projection stores no
  target name for a keyless wait, and the run summary reads such a wait as
  PHP does. A key or fingerprint that is not a string is still refused by the
  completion transport rules.
- **How it was checked.** A new integration test covers a bare wait, a padded
  key with an arbitrary fingerprint, blank values with a timeout, and a key
  that is not a string. The 63 existing condition, timed-condition and
  observation tests still pass.
