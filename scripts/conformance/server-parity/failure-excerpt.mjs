// A bounded, value-free outline of every wire request a failed case observed,
// in observation order, so a flake can be diagnosed from the retained artifact
// without keeping the record. It names each request's method, path, status,
// transport flags, worker and poll identity. Request and response bodies,
// payloads and credentials are never read into it. Stored workflow task rows
// are listed by their counters and clock fields too, so a reference repair
// pass that touched a task during a phase shows which task and when.

const SKIP = new Set(['request', 'response', 'request_body', 'response_body', 'response_bytes_base64',
  'transport_jsonl', 'stdout', 'stderr', 'fixture']);

function line(path, item) {
  const fields = [`${path} ${item.method} ${item.path} ${item.status}`];
  for (const key of ['client_cancelled', 'transport_error', 'request_body_complete', 'upstream_request_started',
    'response_retry_after']) {
    if (Object.hasOwn(item, key) && item[key] !== null && item[key] !== false) fields.push(`${key}=${item[key]}`);
  }
  const request = item.request && typeof item.request === 'object' ? item.request : {};
  for (const key of ['worker_id', 'poll_request_id', 'task_queue', 'lease_owner']) {
    if (typeof request[key] === 'string') fields.push(`${key}=${request[key].slice(0, 120)}`);
  }
  return fields.join(' ');
}

// The task fields a stored-state line names: identities, enums, counters and
// clocks. Payloads, errors and leases' owners are left out.
const TASK_FIELDS = ['workflow_run_id', 'task_type', 'status', 'attempt_count', 'repair_count', 'available_at',
  'leased_at', 'lease_expires_at', 'last_dispatched_at', 'last_claim_failed_at', 'repair_available_at',
  'created_at', 'updated_at'];

function taskLine(path, row) {
  const fields = [`${path} task ${typeof row.id === 'string' || typeof row.id === 'number' ? row.id : '?'}`];
  for (const key of TASK_FIELDS) {
    const value = row[key];
    if (typeof value === 'string' || typeof value === 'number') fields.push(`${key}=${String(value).slice(0, 64)}`);
  }
  return fields.join(' ');
}

function walk(value, path, out, limit) {
  if (out.length >= limit || value === null || typeof value !== 'object') return;
  if (Array.isArray(value)) {
    value.forEach((item, index) => walk(item, `${path}[${index}]`, out, limit));
    return;
  }
  if (typeof value.method === 'string' && typeof value.path === 'string' && Object.hasOwn(value, 'status')) {
    out.push(line(path, value));
  } else if (typeof value.request === 'string' && /^[A-Z]+ \S+$/.test(value.request) && Number.isInteger(value.status)) {
    // Older probes record a step as {request: 'METHOD path', status, response};
    // the label and status are kept, the response body is not.
    out.push(`${path} ${value.request.slice(0, 200)} ${value.status}`);
  }
  const tasks = value.tables?.workflow_tasks?.rows;
  if (Array.isArray(tasks)) {
    for (const [index, row] of tasks.entries()) {
      if (out.length >= limit) return;
      if (row && typeof row === 'object') out.push(taskLine(`${path}.tables.workflow_tasks[${index}]`, row));
    }
  }
  for (const [key, child] of Object.entries(value)) {
    if (!SKIP.has(key) && key !== 'tables') walk(child, `${path}.${key}`, out, limit);
  }
}

export function failureExcerpt(cases, {linesPerCase = 300, maxBytes = 60 * 1024} = {}) {
  let text = '';
  for (const item of cases) {
    const lines = [];
    walk(item.observation, 'observation', lines, linesPerCase + 1);
    const truncated = lines.length > linesPerCase;
    const block = [`== ${item.fixture_id} (${item.outcome}): ${String(item.error ?? '').split('\n')[0].slice(0, 300)}`,
      ...lines.slice(0, linesPerCase), ...(truncated ? [`… more requests omitted after ${linesPerCase}`] : []), ''].join('\n');
    if (Buffer.byteLength(text + block) > maxBytes) {
      text += `… further failed cases omitted at ${maxBytes} bytes\n`;
      break;
    }
    text += block;
  }
  return text;
}
