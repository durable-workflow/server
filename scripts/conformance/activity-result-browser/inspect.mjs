import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync, writeFileSync} from 'node:fs';
import {parseArgs} from 'node:util';
import {chromium} from 'playwright';

const {values} = parseArgs({options: {
  'waterline-url': {type: 'string'}, 'server-url': {type: 'string'},
  identity: {type: 'string'}, 'other-identity': {type: 'string'},
  width: {type: 'string'}, theme: {type: 'string'}, summary: {type: 'string'},
}});
for (const key of ['waterline-url', 'server-url', 'identity', 'other-identity', 'width', 'theme', 'summary']) {
  assert.ok(values[key], `--${key} is required`);
}
const selected = JSON.parse(readFileSync(values.identity, 'utf8'));
const other = JSON.parse(readFileSync(values['other-identity'], 'utf8'));
for (const original of [selected, other]) for (const field of ['namespace', 'workflow_id', 'run_id', 'activity_id']) {
  assert.equal(typeof original[field], 'string', `Original ${field} must be recorded by the real author`);
  assert.ok(original[field].length > 0);
}
assert.notEqual(selected.run_id, other.run_id, 'Wrong-selector controls need a distinct genuine run');
assert.notEqual(selected.activity_id, other.activity_id, 'Wrong-selector controls need a distinct genuine activity');
assert.ok(Number.isFinite(selected.original_deadline), 'The author must set the original observation deadline');
const remaining = () => {
  const milliseconds = Math.floor(selected.original_deadline * 1000 - Date.now());
  assert.ok(milliseconds > 0, 'Original author observation deadline expired');
  return milliseconds;
};
remaining();
const base = name => {
  const url = new URL(values[name]);
  assert.ok(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password);
  return url.toString().replace(/\/$/, '');
};
const service = base('waterline-url'), server = base('server-url');
const width = Number(values.width), theme = values.theme;
assert.ok([390, 1440].includes(width) && ['light', 'dark'].includes(theme));
assert.ok(process.env.DW_RESULT_BROWSER_TOKEN, 'A disposable Server token is required');
const segment = encodeURIComponent;
const history = async original => {
  const response = await fetch(`${server}/api/workflows/${segment(original.workflow_id)}/runs/${segment(original.run_id)}/history`, {
    headers: {Authorization: `Bearer ${process.env.DW_RESULT_BROWSER_TOKEN}`,
      'X-Namespace': original.namespace, 'X-Durable-Workflow-Control-Plane-Version': '2'},
    signal: AbortSignal.timeout(remaining()),
  });
  assert.equal(response.status, 200, 'Original history must be readable');
  const bytes = Buffer.from(await response.arrayBuffer()), document = JSON.parse(bytes);
  assert.ok(Array.isArray(document.events) && !document.next_page_token, 'Use complete small originals');
  const counts = {};
  for (const event of document.events) counts[event.event_type] = (counts[event.event_type] ?? 0) + 1;
  return {sha256: createHash('sha256').update(bytes).digest('hex'), events: document.events.length, counts};
};
const typed = {integer: {$type: 'long', value: '9007199254740993'}, optional: null, text: '日本語 λ'};
const expected = (() => {
  switch (selected.case) {
    case 'encoded-null': return null;
    case 'encoded-false': return false;
    case 'typed-map': case 'retry-completed': return typed;
    case 'external-result': return {...typed, large: 'x'.repeat(2 * 1024 * 1024)};
    default: throw Error('Unknown genuine activity result case');
  }
})();
const route = (run, activity) => `/waterline/api/instances/${segment(selected.workflow_id)}/runs/${segment(run)}/activities/${segment(activity)}/result`;
const resultPath = route(selected.run_id, selected.activity_id);
const record = {outcome: 'failure', workflow_id: selected.workflow_id, run_id: selected.run_id,
  activity_id: selected.activity_id, namespace: selected.namespace, case: selected.case, width, theme,
  original_deadline: selected.original_deadline, started_at: new Date().toISOString()};
let browser;
try {
  const before = [await history(selected), await history(other)];
  assert.equal(before[0].counts.ActivityScheduled, 1, 'One original activity admission is required');
  assert.equal(before[0].counts.ActivityCompleted, 1, 'The original activity must actually complete');
  assert.equal(before[0].counts.ActivityStarted, selected.case === 'retry-completed' ? 2 : 1);
  assert.equal(before[0].counts.ActivityRetryScheduled ?? 0, selected.case === 'retry-completed' ? 1 : 0);
  assert.equal(before[0].counts.WorkflowCompleted, 1, 'The separate original parent must complete');
  browser = await chromium.launch({timeout: remaining(), args: ['--no-sandbox']});
  const context = await browser.newContext({viewport: {width, height: 844}});
  context.setDefaultTimeout(remaining());
  await context.addInitScript(value => localStorage.setItem('waterline-theme', value), theme);
  const page = await context.newPage();
  const reads = [], writes = [], errors = [], failures = [];
  page.on('pageerror', error => errors.push(error.message.slice(0, 512)));
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (path === resultPath) reads.push({method: request.method(), path});
    if (path.startsWith('/waterline/api/instances/') && request.method() !== 'GET') writes.push(path);
  });
  page.on('response', response => {
    const path = new URL(response.url()).pathname;
    if (path.startsWith('/waterline/api/') && response.status() >= 400) {
      failures.push({path, status: response.status()});
    }
  });
  await page.goto(`${service}/waterline/flows/instances/${segment(selected.workflow_id)}/runs/${segment(selected.run_id)}`,
    {waitUntil: 'domcontentloaded', timeout: remaining()});
  assert.ok(!new URL(page.url()).pathname.startsWith('/login'), 'Use the isolated unauthenticated read-only fixture');
  const view = page.locator('#collapseActivities').getByRole('button', {name: 'View', exact: true});
  try {
    const inspect = page.getByRole('button', {name: 'Inspect full details', exact: true});
    await inspect.waitFor({timeout: Math.min(5000, remaining())});
    assert.deepEqual(reads, [], 'Bounded observation must not select an activity result');
    await inspect.click();
    await view.waitFor({timeout: Math.min(5000, remaining())});
  } catch (error) {
    throw Error(`Actual run view unavailable: ${JSON.stringify({failures, errors})}; ${error.message.slice(0, 256)}`);
  }
  assert.equal(await view.count(), 1, 'The original has exactly one activity');
  assert.deepEqual(reads, [], 'Metadata loading must not select an activity result');
  await view.focus();
  const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname === resultPath,
    {timeout: remaining()});
  await page.keyboard.press('Enter');
  const response = await responsePromise, result = await response.json();
  assert.equal(response.status(), 200, 'Selected real result HTTP status');
  assert.equal(result.instance_id, selected.workflow_id);
  assert.equal(result.run_id, selected.run_id);
  assert.equal(result.activity_id, selected.activity_id);
  assert.equal(result.available, true, `Actual backend result unavailable: ${result.reason}`);
  assert.equal(result.state, 'available');
  assert.deepEqual(result.result, expected, 'Exact typed activity result differs from the separate parent output');
  const dialog = page.getByRole('dialog');
  await dialog.waitFor({timeout: remaining()});
  await dialog.evaluate(node => Promise.all(node.getAnimations().map(animation => animation.finished)));
  const rendered = await dialog.locator('.swal2-html-container').innerText();
  assert.deepEqual(JSON.parse(rendered), expected, 'Actual selected dialog must preserve the typed result');
  const geometry = await dialog.evaluate(node => {
    const box = node.getBoundingClientRect();
    return {top: box.top, bottom: box.bottom, width: box.width, viewportWidth: innerWidth, viewportHeight: innerHeight};
  });
  assert.ok(geometry.top >= 0 && geometry.bottom <= geometry.viewportHeight + 1
    && geometry.width <= geometry.viewportWidth, `Dialog geometry: ${JSON.stringify(geometry)}`);
  await dialog.getByRole('button', {name: 'Okay', exact: true}).click();
  await dialog.waitFor({state: 'hidden', timeout: remaining()});
  assert.ok(await view.evaluate(node => document.activeElement === node), 'Dialog must restore selection focus');
  assert.deepEqual(reads, [{method: 'GET', path: resultPath}], 'One explicit selection makes one result request');
  assert.deepEqual(writes, []);
  assert.deepEqual(errors, []);
  assert.deepEqual(failures, [], 'The real page must not silently swallow failed observation requests');
  for (const path of [route(other.run_id, selected.activity_id), route(selected.run_id, other.activity_id)]) {
    const wrong = await context.request.get(service + path, {timeout: remaining()});
    assert.equal(wrong.status(), 404, 'A genuine wrong run/activity selector must refuse');
  }
  const after = [await history(selected), await history(other)];
  assert.deepEqual(after, before, 'UI and wrong-selector reads must preserve both original histories');
  remaining();
  Object.assign(record, {outcome: 'pass', history: before, full_detail_selections: 1, selected_requests: reads.length,
    rendered_bytes: Buffer.byteLength(rendered), rendered_sha256: createHash('sha256').update(rendered).digest('hex'),
    geometry, finished_at: new Date().toISOString()});
} catch (error) {
  record.error = String(error.message).slice(0, 2048);
  record.finished_at = new Date().toISOString();
  process.exitCode = 1;
} finally {
  await browser?.close();
  writeFileSync(values.summary, JSON.stringify(record, null, 2) + '\n');
  console.log(JSON.stringify(record));
}
