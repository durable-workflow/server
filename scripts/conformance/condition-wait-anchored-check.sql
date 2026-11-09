-- Read-only anchored check v4 for sdk-php#269 occurrence-deadline cells on
-- Native with server#1098 (condition_wait_occurrence_deadline), MySQL 8.x.
-- A distinct successor. The original condition-wait-physical-check.sql
-- (a162896d), the v1 prototype (485861da), v2 (d09159ce) and v3 (f03de3f0)
-- stay unchanged.
-- v4 changes no predicate. It makes every text value independent of the
-- client connection: each output column, each session-variable or CAST/
-- DATE_FORMAT string, and each table-column/payload comparison is pinned to
-- utf8mb4_bin (case-sensitive, PAD SPACE). v3 aborted on a latin1 client with ERROR 1267
-- (utf8mb3 SYSCONST @@session.time_zone UNION latin1 CAST); a utf8mb4
-- client would instead mix utf8mb4_0900_ai_ci with the schema's
-- utf8mb4_unicode_ci. Run-ID membership uses that collation; the runner admits fixed-format ULIDs.
-- v3 checks each identity's raw JSON type is STRING before unquoting it:
-- JSON null unquotes to the text 'null' and must never pass as an identity.
-- Server-owned bytes; bind them by SHA-256.
--
-- Native keeps the policy, original budget and anchor A in history payloads
-- (ConditionWaitOpened: deadline_policy, condition_wait_timeout_budget_seconds,
-- occurrence_deadline_at; TimerScheduled: deadline_policy, occurrence_deadline_at,
-- fire_at, timer_id) and in the first timer row's fire_at. There is no schema
-- column for them. The waits projection copies TimerScheduled.fire_at into
-- workflow_run_waits.deadline_at (wait_id = condition_wait_id).
--
-- Every predicate refuses with "(...) IS NOT TRUE", so a missing or null
-- field is a violation, never a silent pass.
--
-- Caller sets these, then sources this file on a read-only connection:
--   SET time_zone = '+00:00';               -- required (B-1)
--   SET @run_ids = '<run_id>,...';          -- every closed run of the cell
--   SET @max_dispatch_seconds = 2;          -- required, exactly the selected 2 s (B-2)
--   SET @expected_budgets = NULL;           -- or a JSON object mapping
--       '<run_id>|<condition_wait_occurrence_id>' to that chain's original T.
--       Leave NULL for a heterogeneous cell whose per-chain T is checked by
--       the SDK evidence; when set, every chain needs an equal entry and no
--       key may name a chain that is not present (B2b, B2c).
-- Output: one row per violation (assertion, run_id, detail). No rows = PASS.
-- Only SELECTs; no table or row is written.

WITH opens AS (
  SELECT h.workflow_run_id AS run_id, h.sequence AS hseq,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.condition_wait_id')) AS wait_id,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.condition_wait_occurrence_id')) AS occ,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.condition_key')) AS ckey,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.condition_definition_fingerprint')) AS fp,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.deadline_policy')) AS policy,
         JSON_EXTRACT(h.payload, '$.condition_wait_timeout_budget_seconds') AS budget_j,
         JSON_EXTRACT(h.payload, '$.timeout_seconds') AS timeout_j,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.occurrence_deadline_at')) AS anchor_s,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.condition_wait_id')) AS wait_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.condition_wait_occurrence_id')) AS occ_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.deadline_policy')) AS policy_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.occurrence_deadline_at')) AS anchor_t
  FROM workflow_history_events h
  WHERE FIND_IN_SET(h.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND h.event_type = 'ConditionWaitOpened'
), sched AS (
  SELECT h.workflow_run_id AS run_id, h.sequence AS hseq,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.condition_wait_id')) AS wait_id,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.timer_id')) AS timer_id,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.fire_at')) AS fire_s,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.deadline_policy')) AS policy,
         JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.occurrence_deadline_at')) AS anchor_s,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.condition_wait_id')) AS wait_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.timer_id')) AS timer_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.fire_at')) AS fire_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.deadline_policy')) AS policy_t,
         JSON_TYPE(JSON_EXTRACT(h.payload, '$.occurrence_deadline_at')) AS anchor_t
  FROM workflow_history_events h
  WHERE FIND_IN_SET(h.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND h.event_type = 'TimerScheduled'
    AND JSON_UNQUOTE(JSON_EXTRACT(h.payload, '$.timer_kind')) = 'condition_timeout'
), firsts AS (
  SELECT o.* FROM opens o
  WHERE o.hseq = (SELECT MIN(f.hseq) FROM opens f WHERE f.run_id = o.run_id AND f.occ <=> o.occ)
), anchors AS (
  -- A is the first open's own timer row fire_at, read by its timer ID. The
  -- bijection checks B8 make any join multiplication visible on their own.
  SELECT f.run_id, f.occ, f.wait_id AS first_wait, f.budget_j, f.ckey, f.fp, f.anchor_s,
         t.id AS timer_id, t.fire_at AS a_at
  FROM firsts f
  LEFT JOIN sched s ON s.run_id = f.run_id AND s.wait_id = f.wait_id
  LEFT JOIN workflow_run_timers t ON t.id COLLATE utf8mb4_bin = s.timer_id AND t.workflow_run_id = f.run_id
)

SELECT CONVERT('B-1 session time zone is not +00:00' USING utf8mb4) COLLATE utf8mb4_bin AS assertion, CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_bin AS run_id, CONVERT(@@session.time_zone USING utf8mb4) COLLATE utf8mb4_bin AS detail
FROM DUAL WHERE (@@session.time_zone = '+00:00') IS NOT TRUE

UNION ALL
SELECT CONVERT('B-2 dispatch bound is not the selected 2 seconds' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(COALESCE(CAST(@max_dispatch_seconds AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL') USING utf8mb4) COLLATE utf8mb4_bin
FROM DUAL WHERE (@max_dispatch_seconds <=> 2) IS NOT TRUE

UNION ALL
SELECT CONVERT('B-3 expected budget map is not a JSON object' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(LEFT(CAST(@expected_budgets AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 200) USING utf8mb4) COLLATE utf8mb4_bin
FROM DUAL WHERE @expected_budgets IS NOT NULL
  AND (JSON_VALID(@expected_budgets) AND JSON_TYPE(@expected_budgets) = 'OBJECT') IS NOT TRUE

UNION ALL
-- Every physical open carries the complete opt-in, with integer budget and
-- remaining timeout and 0 <= timeout <= budget.
SELECT CONVERT('B1 condition wait without a complete occurrence policy' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(o.wait_id, 'NULL'), ' policy=', COALESCE(o.policy, 'NULL'), ' budget=', COALESCE(CAST(o.budget_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL'),
              ' timeout=', COALESCE(CAST(o.timeout_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL'), ' occ=', COALESCE(o.occ, 'NULL'),
              ' anchor=', COALESCE(o.anchor_s, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o
WHERE (o.policy = 'occurrence' AND JSON_TYPE(o.budget_j) = 'INTEGER' AND JSON_TYPE(o.timeout_j) = 'INTEGER'
       AND CAST(o.timeout_j AS SIGNED) >= 0 AND CAST(o.timeout_j AS SIGNED) <= CAST(o.budget_j AS SIGNED)
       AND o.wait_t = 'STRING' AND o.occ_t = 'STRING' AND o.policy_t = 'STRING' AND o.anchor_t = 'STRING'
       AND o.wait_id <> '' AND o.occ <> '' AND o.anchor_s <> '') IS NOT TRUE

UNION ALL
-- The chain's first open authors its whole budget.
SELECT CONVERT('B2 first open does not author its whole budget' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(f.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(f.wait_id, 'NULL'), ' timeout=', COALESCE(CAST(f.timeout_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL'),
              ' budget=', COALESCE(CAST(f.budget_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM firsts f
WHERE (JSON_TYPE(f.timeout_j) = 'INTEGER' AND JSON_TYPE(f.budget_j) = 'INTEGER'
       AND CAST(f.timeout_j AS SIGNED) = CAST(f.budget_j AS SIGNED)) IS NOT TRUE

UNION ALL
-- When a per-chain map is bound, each chain's original T equals its entry.
SELECT CONVERT('B2b chain budget differs from its expected entry' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(f.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(f.occ, 'NULL'), ' budget=', COALESCE(CAST(f.budget_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL'), ' expected=',
              COALESCE(CAST(JSON_EXTRACT(@expected_budgets, CONCAT('$."', f.run_id COLLATE utf8mb4_bin, '|', f.occ, '"')) AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'missing')) USING utf8mb4) COLLATE utf8mb4_bin
FROM firsts f
WHERE @expected_budgets IS NOT NULL
  AND (CAST(JSON_EXTRACT(@expected_budgets, CONCAT('$."', f.run_id COLLATE utf8mb4_bin, '|', f.occ, '"')) AS SIGNED)
       = CAST(f.budget_j AS SIGNED)) IS NOT TRUE

UNION ALL
SELECT CONVERT('B2c expected budget names no present chain' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(k.chain USING utf8mb4) COLLATE utf8mb4_bin
FROM JSON_TABLE(COALESCE(JSON_KEYS(@expected_budgets), JSON_ARRAY()), '$[*]' COLUMNS (chain VARCHAR(300) PATH '$')) k
WHERE @expected_budgets IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM firsts f WHERE CONCAT(f.run_id COLLATE utf8mb4_bin, '|', f.occ) = CONVERT(k.chain USING utf8mb4) COLLATE utf8mb4_bin)

UNION ALL
-- Every open of one run/occurrence keeps the first open's budget, key,
-- fingerprint and recorded anchor string.
SELECT CONVERT('B3 reopen changed its chain identity, budget or anchor' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(o.wait_id, 'NULL'), ' vs first ', COALESCE(a.first_wait, 'NULL'),
              ' budget ', COALESCE(CAST(o.budget_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL'), '/', COALESCE(CAST(a.budget_j AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin, 'NULL'),
              ' anchor ', COALESCE(o.anchor_s, 'NULL'), '/', COALESCE(a.anchor_s, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o JOIN anchors a ON a.run_id = o.run_id AND a.occ <=> o.occ
WHERE (CAST(o.budget_j AS SIGNED) = CAST(a.budget_j AS SIGNED) AND o.ckey <=> a.ckey AND o.fp <=> a.fp
       AND o.anchor_s = a.anchor_s) IS NOT TRUE

UNION ALL
-- B8: exact one-to-one identities, so no join below can hide a duplicate.
SELECT CONVERT('B8a duplicate physical open identity' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(CONCAT(COALESCE(o.wait_id, 'NULL'), ' opens=', COUNT(*)) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o GROUP BY o.run_id, o.wait_id HAVING COUNT(*) <> 1

UNION ALL
SELECT CONVERT('B8b open without exactly one condition-timeout schedule' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(o.wait_id, 'NULL'), ' schedules=',
              (SELECT COUNT(*) FROM sched s WHERE s.run_id = o.run_id AND s.wait_id <=> o.wait_id)) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o
WHERE ((SELECT COUNT(*) FROM sched s WHERE s.run_id = o.run_id AND s.wait_id <=> o.wait_id) = 1) IS NOT TRUE

UNION ALL
SELECT CONVERT('B8c orphan condition-timeout schedule' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(s.run_id USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(CONCAT(COALESCE(s.wait_id, 'NULL'), ' timer=', COALESCE(s.timer_id, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM sched s
WHERE NOT EXISTS (SELECT 1 FROM opens o WHERE o.run_id = s.run_id AND o.wait_id <=> s.wait_id)

UNION ALL
SELECT CONVERT('B8d schedule identity not a string, timer shared or without its row' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(s.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(s.wait_id, 'NULL'), ' timer=', COALESCE(s.timer_id, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM sched s
WHERE (s.wait_t = 'STRING' AND s.timer_t = 'STRING' AND s.fire_t = 'STRING' AND s.policy_t = 'STRING' AND s.anchor_t = 'STRING'
       AND s.wait_id <> '' AND s.timer_id <> ''
       AND (SELECT COUNT(*) FROM sched d WHERE d.timer_id = s.timer_id) = 1
       AND (SELECT COUNT(*) FROM workflow_run_timers t WHERE t.id COLLATE utf8mb4_bin = s.timer_id AND t.workflow_run_id = s.run_id) = 1) IS NOT TRUE

UNION ALL
-- The first open's recorded anchor is exactly its own timer row's fire_at
-- (Native writes microsecond RFC 3339 with Z).
SELECT CONVERT('B4 first open anchor is not its own timer fire_at' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(a.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(a.first_wait, 'NULL'), ' timer=', COALESCE(a.timer_id, 'missing'), ' fire_at=',
              COALESCE(CONVERT(DATE_FORMAT(a.a_at, '%Y-%m-%dT%H:%i:%s.%fZ') USING utf8mb4) COLLATE utf8mb4_bin, 'NULL'), ' recorded=', COALESCE(a.anchor_s, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM anchors a
WHERE (CONVERT(DATE_FORMAT(a.a_at, '%Y-%m-%dT%H:%i:%s.%fZ') USING utf8mb4) COLLATE utf8mb4_bin = a.anchor_s) IS NOT TRUE

UNION ALL
-- Every open, the first included, scheduled its timer at exactly A.
SELECT CONVERT('B5 chain timer not scheduled exactly at the first anchor' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(o.wait_id, 'NULL'), ' timer=', COALESCE(s.timer_id, 'missing'), ' scheduled=', COALESCE(s.fire_s, 'NULL'),
              ' policy=', COALESCE(s.policy, 'NULL'), ' row_fire_at=',
              COALESCE(CONVERT(DATE_FORMAT(t.fire_at, '%Y-%m-%dT%H:%i:%s.%fZ') USING utf8mb4) COLLATE utf8mb4_bin, 'NULL'), ' A=', COALESCE(a.anchor_s, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o
JOIN anchors a ON a.run_id = o.run_id AND a.occ <=> o.occ
LEFT JOIN sched s ON s.run_id = o.run_id AND s.wait_id = o.wait_id
LEFT JOIN workflow_run_timers t ON t.id COLLATE utf8mb4_bin = s.timer_id AND t.workflow_run_id = o.run_id
WHERE (s.policy = 'occurrence' AND s.anchor_s = a.anchor_s AND s.fire_s = a.anchor_s AND t.fire_at = a.a_at) IS NOT TRUE

UNION ALL
-- The waits projection carries A as every chain wait's deadline.
SELECT CONVERT('B6 projected wait deadline is not the first anchor' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(o.wait_id, 'NULL'), ' deadline_at=', COALESCE(CONVERT(DATE_FORMAT(w.deadline_at, '%Y-%m-%dT%H:%i:%s.%fZ') USING utf8mb4) COLLATE utf8mb4_bin, 'missing'),
              ' A=', COALESCE(a.anchor_s, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o
JOIN anchors a ON a.run_id = o.run_id AND a.occ <=> o.occ
LEFT JOIN workflow_run_waits w ON w.workflow_run_id = o.run_id AND w.wait_id COLLATE utf8mb4_bin = o.wait_id AND w.kind = 'condition'
WHERE (w.deadline_at = a.a_at) IS NOT TRUE

UNION ALL
-- A fired chain timer is never early and fires within the selected bound.
SELECT CONVERT('B7 chain timer fired early or beyond the dispatch bound' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(o.run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(t.id, ' fire_at=', CONVERT(DATE_FORMAT(t.fire_at, '%Y-%m-%dT%H:%i:%s.%fZ') USING utf8mb4) COLLATE utf8mb4_bin,
              ' fired_at=', COALESCE(CONVERT(DATE_FORMAT(t.fired_at, '%Y-%m-%dT%H:%i:%s.%fZ') USING utf8mb4) COLLATE utf8mb4_bin, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM opens o
JOIN sched s ON s.run_id = o.run_id AND s.wait_id = o.wait_id
JOIN workflow_run_timers t ON t.id COLLATE utf8mb4_bin = s.timer_id AND t.workflow_run_id = o.run_id
WHERE t.status = 'fired'
  AND (t.fired_at >= t.fire_at
       AND TIMESTAMPDIFF(MICROSECOND, t.fire_at, t.fired_at) <= @max_dispatch_seconds * 1000000) IS NOT TRUE

UNION ALL
-- The original A1, A2, A3, A4, A5, A6 and A0 predicates, unchanged in meaning.
-- Server#1098 changed no projection status, timer settlement or task rule.
SELECT CONVERT('A1 open condition wait' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(w.workflow_run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(w.wait_id, ' status=', w.status, ' source_status=', COALESCE(w.source_status, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_run_waits w
WHERE FIND_IN_SET(w.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND w.kind = 'condition' AND w.status = 'open'

UNION ALL
SELECT CONVERT('A2 condition wait not settled to a native terminal pair' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(w.workflow_run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(w.wait_id, ' ', w.status, '/', COALESCE(w.source_status, 'NULL'), ' resolved_at=', COALESCE(w.resolved_at, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_run_waits w
WHERE FIND_IN_SET(w.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND w.kind = 'condition'
  AND NOT (w.resolved_at IS NOT NULL AND (
        (w.status = 'resolved' AND w.source_status = 'satisfied')
     OR (w.status = 'cancelled' AND w.source_status IN ('cancelled', 'terminated', 'continued', 'closed'))))

UNION ALL
SELECT CONVERT('A3 condition timeout timer not fired or cancelled' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(t.workflow_run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(t.id, ' seq=', t.sequence, ' status=', t.status, ' fire_at=', t.fire_at) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_run_timers t
WHERE FIND_IN_SET(t.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND t.status NOT IN ('fired', 'cancelled')
  AND EXISTS (SELECT 1 FROM workflow_run_waits w WHERE w.workflow_run_id = t.workflow_run_id
              AND w.kind = 'condition' AND w.sequence = t.sequence)

UNION ALL
SELECT CONVERT('A4 unexpected timer entry row' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(e.workflow_run_id USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(CONCAT(e.id, ' status=', COALESCE(e.status, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_run_timer_entries e
WHERE FIND_IN_SET(e.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin)

UNION ALL
SELECT CONVERT('A5 runnable condition timeout task after close' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(k.workflow_run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(k.id, ' status=', k.status, ' available_at=', COALESCE(k.available_at, 'NULL')) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_tasks k
WHERE FIND_IN_SET(k.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND k.task_type = 'timer' AND k.status IN ('ready', 'leased')
  AND JSON_EXTRACT(k.payload, '$.condition_wait_id') IS NOT NULL

UNION ALL
SELECT CONVERT('A6 overlapping condition waits for one key' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(a.workflow_run_id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT(COALESCE(a.target_name, 'NULL'), ': ', a.wait_id, ' and ', b.wait_id) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_run_waits a
JOIN workflow_run_waits b ON b.workflow_run_id = a.workflow_run_id AND b.kind = 'condition'
  AND b.target_name <=> a.target_name AND b.wait_id > a.wait_id
WHERE FIND_IN_SET(a.workflow_run_id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) AND a.kind = 'condition'
  AND a.opened_at < COALESCE(b.resolved_at, '9999-12-31') AND b.opened_at < COALESCE(a.resolved_at, '9999-12-31')

UNION ALL
SELECT CONVERT('A6 condition rows differ from ConditionWaitOpened events' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(r.id USING utf8mb4) COLLATE utf8mb4_bin,
       CONVERT(CONCAT('rows=', (SELECT COUNT(*) FROM workflow_run_waits w WHERE w.workflow_run_id = r.id AND w.kind = 'condition'),
              ' events=', (SELECT COUNT(*) FROM workflow_history_events h WHERE h.workflow_run_id = r.id AND h.event_type = 'ConditionWaitOpened')) USING utf8mb4) COLLATE utf8mb4_bin
FROM workflow_runs r
WHERE FIND_IN_SET(r.id, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin)
  AND (SELECT COUNT(*) FROM workflow_run_waits w WHERE w.workflow_run_id = r.id AND w.kind = 'condition')
   <> (SELECT COUNT(*) FROM workflow_history_events h WHERE h.workflow_run_id = r.id AND h.event_type = 'ConditionWaitOpened')

UNION ALL
SELECT CONVERT('A0 run missing or still open' USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(ids.id USING utf8mb4) COLLATE utf8mb4_bin, CONVERT(COALESCE(r.status, 'missing') USING utf8mb4) COLLATE utf8mb4_bin
FROM JSON_TABLE(CONCAT('["', REPLACE(CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin, ',', '","'), '"]'), '$[*]' COLUMNS (id VARCHAR(26) PATH '$')) ids
LEFT JOIN workflow_runs r ON r.id COLLATE utf8mb4_bin = CONVERT(ids.id USING utf8mb4) COLLATE utf8mb4_bin
WHERE r.id IS NULL OR r.status NOT IN ('completed', 'failed', 'cancelled', 'terminated');
