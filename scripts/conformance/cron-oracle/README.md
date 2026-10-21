# Published PHP cron and schedule corpora

The native schedule engine (`rust/src/runtime/schedule_cron.rs`,
`schedule_zones.rs` and `schedule_spec.rs`) is checked against answers that
published PHP Server 2.5.14 itself computes. These scripts produce them inside
the frozen image, which bundles `dragonmantank/cron-expression` 3.6.0, PHP
8.3.35 and its time zone database 2026.1:

```sh
image=ghcr.io/durable-workflow/server@sha256:28fa0f3968ea253c727aad7fe583bb61c788553f1e4b96ece6ea4570d20faf42
run() { docker run --rm --pull=never --network none --user 1000:1000 --read-only \
  -v "$PWD/scripts/conformance/cron-oracle:/probe:ro" -e SKIP_ZONES=Africa/Casablanca,Africa/El_Aaiun \
  --entrypoint php "$image" "/probe/$1" "${@:2}"; }
run next-run.php 7 2000 | head -1200 > rust/tests/fixtures/cron/php-2.5.14-next-run.jsonl
run zone-names.php > rust/tests/fixtures/cron/php-2.5.14-zone-names.jsonl
run next-fire.php 5 1500 | head -600 > rust/tests/fixtures/cron/php-2.5.14-next-fire.jsonl
run backfill-dates.php 3 700 > rust/tests/fixtures/cron/php-2.5.14-backfill-dates.jsonl
```

- `next-run.php SEED COUNT`: random expressions (names, ranges, steps, lists,
  `L`, `W`, `#`, `?` and aliases) at instants clustered around daylight-saving
  changes, month ends and leap days, in fifteen zones, with PHP's next run or
  exception class.
- `zone-names.php`: every identifier PHP lists, with links, case variants,
  every abbreviation, numeric offsets and refused spellings, each with PHP's
  answers for three fixed cases.
- `next-fire.php SEED COUNT`: whole schedule specifications (cron expressions,
  intervals with offsets, zones and malformed rules) with
  `WorkflowSchedule::computeNextFireAt` and the UTC offset of its result.
- `backfill-dates.php SEED COUNT`: backfill time strings, ISO-8601 shapes
  (marked `iso`) and PHP's other spellings, with Laravel's `date` rule and
  the instant `new DateTimeImmutable()` reads.

The zones skipped above, and America/Edmonton, Inuvik, Vancouver and
Winnipeg, have rules that changed after PHP's database. Native uses the newer
bundled IANA data (2026e), so those zones legitimately differ there and are
not in the corpora. Larger seeds were checked locally before committing.
