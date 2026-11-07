import {constants, existsSync, fstatSync, lstatSync, mkdirSync, openSync, readSync, readdirSync, closeSync, writeFileSync} from 'node:fs';
import {basename, dirname, join, relative, resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {StringDecoder} from 'node:string_decoder';

const FILE_LIMIT = 64 * 1024;
const CONTENT_LIMIT = 2 * 1024 * 1024;
const ENTRY_LIMIT = 128;

// Failed actors and recorders need request outcomes, without request/history payloads. These
// diagnostics read existing evidence only and never qualify a failed actor.
function markerJournal(path, size) {
  if (!Number.isSafeInteger(size) || size < 0 || size > CONTENT_LIMIT) return null;
  const descriptor = openSync(path, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    if (fstatSync(descriptor).size !== size) return null;
    const data = Buffer.alloc(size);
    let read = 0;
    while (read < size) {
      const count = readSync(descriptor, data, read, size - read, read);
      if (!count) return null;
      read += count;
    }
    return fstatSync(descriptor).size === size ? data : null;
  } finally { closeSync(descriptor); }
}

function jsonObject(text) {
  try {
    const value = JSON.parse(text);
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
  } catch { return null; }
}

function markerTaskShape(reply) {
  if (reply?.task === null) return 'none';
  if (reply?.task && typeof reply.task === 'object' && !Array.isArray(reply.task)) return 'present';
  return 'unknown';
}

function markerRequest(row) {
  if (row.method !== 'POST') return null;
  if (row.path === '/api/worker/workflow-tasks/poll') return {kind: 'workflow', operation: 'poll'};
  if (row.path === '/api/worker/activity-tasks/poll') return {kind: 'activity', operation: 'poll'};
  if (typeof row.path !== 'string') return null;
  const match = /^\/api\/worker\/(workflow|activity)-tasks\/[^/]{1,512}\/(complete|fail|heartbeat)$/.exec(row.path);
  return match ? {kind: match[1], operation: match[2]} : null;
}

function markerPollObservation(row) {
  const operation = markerRequest(row);
  if (operation === null) return null;
  const request = jsonObject(row.request_body), reply = jsonObject(row.response_body);
  const phase = typeof request?.worker_id === 'string' ? request.worker_id.split(':').at(-1) : null;
  const phases = ['original', 'excluding', 'restored', 'repaired-wait', 'policy-refusal', 'wider', 'clock-wait'];
  const statuses = ['leased', 'empty', 'timeout', 'task_queue_changed', 'stale_worker_registration', 'workflow_poll_normalization_pending'];
  const reasons = ['backend_lock_pressure', 'stale_worker_registration', 'poll_request_task_kinds_conflict',
    'history_event_exceeds_page_budget', 'lease_expired', 'lease_owner_mismatch', 'stale_attempt',
    'attempt_closed', 'run_closed', 'task_not_leased', 'workflow_task_attempt_mismatch', 'workflow_claim_mismatch',
    'current_attempt_changed', 'current_attempt_not_found', 'start_to_close_timeout', 'schedule_to_close_timeout',
    'schedule_to_start_timeout', 'heartbeat_timeout'];
  return {...operation, phase: phases.includes(phase) ? phase : 'other',
    status: Number.isInteger(row.status) && row.status >= 0 && row.status <= 599 ? row.status : 'unknown',
    poll_status: statuses.includes(reply?.poll_status) ? reply.poll_status : 'other',
    reason: reasons.includes(reply?.reason) ? reply.reason : 'other',
    task: markerTaskShape(reply),
    response_json_object: reply !== null,
    client_cancelled: row.client_cancelled === true, transport_error: row.transport_error != null};
}

export function markerPollDiagnostic(path, size) {
  const result = {schema: 'durable-workflow.marker-poll-diagnostic.v1', complete: false,
    source_bytes: size, observed_rows: 0, poll_rows: 0, request_rows: 0, last_refusal: null, groups: []};
  const data = markerJournal(path, size);
  if (data === null) return result;
  let text;
  try { text = new TextDecoder('utf-8', {fatal: true}).decode(data); } catch { return result; }
  const lines = text.split('\n').filter(line => line !== '');
  if (lines.length > 512 || lines.some(line => Buffer.byteLength(line) > 128 * 1024)) return result;
  const groups = new Map();
  let lastRefusal = null;
  for (const line of lines) {
    const row = jsonObject(line);
    if (row === null) return result;
    result.observed_rows++;
    const observation = markerPollObservation(row);
    if (observation === null) continue;
    const key = JSON.stringify(observation);
    if (!groups.has(key) && groups.size === 64) return result;
    groups.set(key, {...observation, count: (groups.get(key)?.count ?? 0) + 1});
    result.request_rows++;
    if (observation.operation === 'poll') result.poll_rows++;
    if (typeof observation.status === 'number' && observation.status >= 400) {
      lastRefusal = {...observation, row: result.observed_rows};
    }
  }
  result.complete = true;
  result.last_refusal = lastRefusal;
  result.groups = [...groups.values()];
  return result;
}

function failedMarkerJournals(source, files) {
  const failed = new Set();
  for (const file of files.filter(file => /^corrected-marker\/(php|rust)\/[^/]+\.failure-excerpt\.txt$/.test(file.path)).slice(0, 8)) {
    if (file.source_bytes > FILE_LIMIT) continue;
    const content = markerJournal(join(source, file.path), file.source_bytes);
    if (content === null) continue;
    for (const match of content.toString('utf8').matchAll(/^== ([a-z0-9-]{1,96}) \(runner-blocked\): /gm)) {
      // Match the selected fixture's journal without retaining its prefix,
      // exception text, transport body or any supplied identifier.
      const journal = files.find(item => dirname(item.path) === dirname(file.path)
        && item.path.endsWith(`-${match[1]}-http.jsonl`));
      if (journal) failed.add(journal.path);
    }
  }
  return failed;
}

function markerDiagnostics(source, files) {
  const failed = failedMarkerJournals(source, files);
  return files.filter(file => /^corrected-marker\/(php|rust)\/[^/]+-http\.jsonl$/.test(file.path)
    && (failed.has(file.path) || files.some(other => other.source_bytes > 0 && other.path.endsWith('.stderr')
      && other.path.startsWith(file.path.slice(0, -'-http.jsonl'.length) + '-'))))
    .sort((a, b) => Number(failed.has(b.path)) - Number(failed.has(a.path)))
    .slice(0, 8).map(file => {
      const content = Buffer.from(`${JSON.stringify(markerPollDiagnostic(join(source, file.path), file.source_bytes))}\n`);
      return {path: `${file.path}.diagnostic.json`, source_bytes: content.length,
        derived_from: file.path, content, priority: 0};
    });
}

function priority(path, failed) {
  const name = basename(path);
  if (/\.status$/.test(name) || /-status\.txt$/.test(name)) return -1;
  if (failed && /(?:^|\/)query-authority\/clients\/(?:php|rust)\/[^/]+\/(?:client\.stderr|observer\.log)$/.test(path)) return 0;
  if (TEST_SUMMARY.test(name)) return 0;
  if (/^(comparison|compare)([-.].*)?\.json$/.test(name) || /-comparison\.json$/.test(name)) return 1;
  if (failed && (/^(?:php|embedded|rust)-record\.txt$/.test(name) || /\.failure-excerpt\.txt$/.test(name)
      || /^sdk-(?:signals|queries)-profile\.log$/.test(name))) return 1;
  if (failed && (/\.stderr$/.test(name) || /(?:stderr|error|failure|bootstrap|install|build).*(?:\.log|\.txt)$/.test(name)
      || /^(?:php|rust|native)(?:[-.].*)?\.log$/.test(name) || /server\.log$/.test(name))) return 2;
  if (/^(identity|build|database-state|database-memory-limit)\.json$/.test(name)) return 3;
  if (/^execution-(listed-tests|shard(?:-b)?)\.txt$/.test(name)) return 4;
  if (/^(?:php|embedded|rust)-fixtures\.json$/.test(name)) return 4;
  if (/(?:-status|-integrity|-version|-revision|-controls|-contract)\.txt$/.test(name) || /\.status$/.test(name)
      || /^(SHA256SUMS|source-hashes\.sha256)$/.test(name)) return 4;
  return null;
}

const TEST_SUMMARY = /^(rust|mysql|postgres|comparator|codec-load|artifact-summary)(-execution)?-tests(-b)?\.txt$/;

// A node:test (TAP) report's failing tests: each `not ok` line with its
// indented YAML diagnostic block, ending at the block's `...` line. A large
// report keeps only its tail, which can miss a failure reported early. The
// report is read in fixed chunks, one bounded line at a time: memory stays
// within `limit` plus one chunk and one line, whatever the report's size.
// Failures past the excerpt limit are still counted.
const SCAN_CHUNK = 64 * 1024;
const LINE_LIMIT = 8 * 1024;
const BLOCK_LINES = 200;

export function scanTapFailures(path, limit) {
  const descriptor = openSync(path, constants.O_RDONLY | constants.O_NOFOLLOW);
  const chunk = Buffer.alloc(SCAN_CHUNK);
  const decoder = new StringDecoder('utf8');
  const kept = [];
  let keptBytes = 0;
  let count = 0;
  let pending = '';
  let block = null;
  const keep = text => {
    const size = Buffer.byteLength(text) + 1;
    if (keptBytes + size > limit) return;
    kept.push(text);
    keptBytes += size;
  };
  const line = text => {
    if (block !== null) {
      if (block.lines < BLOCK_LINES) {
        keep(text);
        block.lines++;
      }
      if (text === block.end || block.lines >= BLOCK_LINES) block = null;
      return;
    }
    const match = /^(\s*)not ok \d+ /.exec(text);
    if (match) {
      count++;
      keep(text);
      block = {end: `${match[1]}  ...`, lines: 1};
    }
  };
  try {
    let read;
    while ((read = readSync(descriptor, chunk, 0, SCAN_CHUNK, null)) > 0) {
      const parts = (pending + decoder.write(chunk.subarray(0, read))).split('\n');
      pending = parts.pop().slice(0, LINE_LIMIT);
      for (const part of parts) line(part.slice(0, LINE_LIMIT));
    }
    pending = (pending + decoder.end()).slice(0, LINE_LIMIT);
    if (pending !== '') line(pending);
  } finally { closeSync(descriptor); }
  return {text: kept.length > 0 ? `${kept.join('\n')}\n` : '', count};
}

function inventory(root, path = root, files = []) {
  if (!lstatSync(path).isDirectory()) throw new Error('Evidence root must be a directory.');
  for (const entry of readdirSync(path, {withFileTypes: true})) {
    const full = join(path, entry.name);
    if (entry.isSymbolicLink()) continue;
    if (entry.isDirectory()) {
      if (!['vendor', 'node_modules', 'target', '.git', 'objects'].includes(entry.name)) inventory(root, full, files);
    } else if (entry.isFile()) files.push({path: relative(root, full), source_bytes: lstatSync(full).size});
  }
  return files;
}

export function summarizeEvidence(source, destination, {outcome = 'unknown', revision = ''} = {}) {
  source = resolve(source);
  destination = resolve(destination);
  if (destination === source || destination.startsWith(`${source}/`)) throw new Error('Summary must be outside raw evidence.');
  if (existsSync(destination) && (!lstatSync(destination).isDirectory() || readdirSync(destination).length !== 0)) {
    throw new Error('Summary destination must be a fresh empty directory.');
  }
  mkdirSync(destination, {recursive: true});
  const files = inventory(source);
  const diagnostics = outcome === 'success' ? [] : markerDiagnostics(source, files);
  const selected = [...diagnostics, ...files.map(file => ({...file, priority: priority(file.path, outcome !== 'success')}))]
    .filter(file => file.priority !== null && file.source_bytes > 0)
    .sort((a, b) => a.priority - b.priority || a.path.localeCompare(b.path));
  const retained = [];
  let bytes = 0;
  for (const file of selected) {
    if (retained.length >= ENTRY_LIMIT) break;
    // Truncated JSON would be unusable and must not look like a full report.
    if (file.path.endsWith('.json') && file.source_bytes > FILE_LIMIT) continue;
    const size = Math.min(file.source_bytes, FILE_LIMIT);
    if (bytes + size > CONTENT_LIMIT) continue;
    const output = join(destination, file.path);
    mkdirSync(dirname(output), {recursive: true});
    if (file.content !== undefined) writeFileSync(output, file.content);
    else {
      const descriptor = openSync(join(source, file.path), constants.O_RDONLY | constants.O_NOFOLLOW);
      try {
        const content = Buffer.alloc(size);
        const start = file.source_bytes - size;
        let count = 0;
        while (count < size) {
          const read = readSync(descriptor, content, count, size - count, start + count);
          if (read === 0) throw new Error(`Evidence changed while summarizing: ${file.path}`);
          count += read;
        }
        writeFileSync(output, content);
      } finally { closeSync(descriptor); }
    }
    retained.push({path: file.path, source_bytes: file.source_bytes, retained_bytes: size, tail_only: size < file.source_bytes,
      ...(file.derived_from === undefined ? {} : {derived_from: file.derived_from})});
    bytes += size;
    // A tail-only test report also keeps its failing tests, bounded.
    if (size < file.source_bytes && TEST_SUMMARY.test(basename(file.path)) && retained.length < ENTRY_LIMIT) {
      const failures = scanTapFailures(join(source, file.path), Math.min(FILE_LIMIT, CONTENT_LIMIT - bytes));
      if (failures.count > 0) {
        const excerpt = Buffer.from(failures.text);
        if (excerpt.length > 0) {
          const path = `${file.path}.failures.txt`;
          writeFileSync(join(destination, path), excerpt);
          retained.push({path, source_bytes: file.source_bytes, retained_bytes: excerpt.length, tail_only: false,
            derived_from: file.path, failures: failures.count});
          bytes += excerpt.length;
        }
      }
    }
  }
  const summary = {
    schema: 'durable-workflow.server-parity-artifact-summary.v1', outcome, revision,
    scanned_files: files.length, scanned_bytes: files.reduce((sum, file) => sum + file.source_bytes, 0),
    retained_bytes: bytes, retained,
    omitted_files: files.length - retained.filter(file => file.derived_from === undefined).length,
    limits: {file_bytes: FILE_LIMIT, content_bytes: CONTENT_LIMIT, entries: ENTRY_LIMIT,
      excluded_directories: ['vendor', 'node_modules', 'target', '.git', 'objects']},
    scope: 'Test summaries, bounded diagnostic tails and closed worker-request outcome counts only. Raw records, HTTP journals, databases and payload bytes are not retained. This summary does not rerun or qualify assertions.',
  };
  writeFileSync(join(destination, 'summary.json'), `${JSON.stringify(summary, null, 2)}\n`);
  return summary;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const [source, destination] = process.argv.slice(2);
  if (!source || !destination) throw new Error('Usage: artifact-summary.mjs RAW_DIRECTORY SUMMARY_DIRECTORY');
  const result = summarizeEvidence(source, destination, {
    outcome: process.env.PARITY_JOB_OUTCOME, revision: process.env.GITHUB_SHA,
  });
  console.log(JSON.stringify({retained_files: result.retained.length, retained_bytes: result.retained_bytes,
    omitted_files: result.omitted_files, scanned_bytes: result.scanned_bytes}));
}
