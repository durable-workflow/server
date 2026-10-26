import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {chmodSync, copyFileSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';

const LANES = new URL('../../scripts/conformance/server-parity/sdk-direction-lanes.sh', import.meta.url).pathname;
const PROFILES = {
  update: 'sdk-updates', 'update-failure': 'sdk-update-failures', child: 'sdk-child-directions',
  'namespace-signal': 'sdk-namespace-signals', timer: 'sdk-timers', activity: 'sdk-directions',
  'selected-run-signal': 'sdk-selected-run-signals', signal: 'sdk-signals', query: 'sdk-queries',
};
// The profiles that run against the second native server.
const SECOND_SERVER = new Set(['namespace-signal', 'timer', 'activity']);
const URLS = {a: 'http://native-a:8080', b: 'http://native-b:8080'};

// Runs the wrapper in a scratch tree whose profile script and `docker` are
// stubs that append to one event log: a profile starts its PHP server and
// exits FAIL_<variant> (default 0); `docker stop` exits STOP_FAIL_<tag>.
function runLanes(environment = {}) {
  const root = mkdtempSync(join(tmpdir(), 'dw-sdk-lanes-'));
  try {
    const scripts = join(root, 'scripts/conformance/server-parity');
    const bin = join(root, 'bin');
    mkdirSync(scripts, {recursive: true});
    mkdirSync(bin);
    copyFileSync(LANES, join(scripts, 'sdk-direction-lanes.sh'));
    const events = join(root, 'events.log');
    writeFileSync(join(scripts, 'sdk-direction-profile.sh'), `#!/usr/bin/env bash
set -euo pipefail
variant=$3
echo "start $variant $1 $2" >> "$EVENTS"
echo "profile output for $variant"
sleep 0.2
echo "end $variant" >> "$EVENTS"
fail="FAIL_\${variant//-/_}"
exit "\${!fail:-0}"
`);
    writeFileSync(join(bin, 'docker'), `#!/usr/bin/env bash
echo "docker $*" >> "$EVENTS"
name=\${*: -1}
tag=\${name#"$RESOURCE_SCOPE"-}
tag=\${tag%-server}
fail="STOP_FAIL_\${tag//-/_}"
exit "\${!fail:-0}"
`);
    chmodSync(join(bin, 'docker'), 0o755);
    const child = spawnSync('bash', ['scripts/conformance/server-parity/sdk-direction-lanes.sh', 'sqlite', URLS.a, URLS.b], {
      cwd: root, encoding: 'utf8', timeout: 30000,
      env: {...process.env, ...environment, PATH: `${bin}:${process.env.PATH}`, EVENTS: events, RESOURCE_SCOPE: 'dw-scope-1'},
    });
    const statuses = Object.fromEntries(Object.values(PROFILES).map(tag => [tag,
      readFileSync(join(root, 'parity-evidence/sdk-direction-lanes', `${tag}.status`), 'utf8').trim()]));
    return {child, statuses, events: readFileSync(events, 'utf8').trim().split('\n')};
  } finally {
    rmSync(root, {recursive: true, force: true});
  }
}

const started = (events, variant) => events.findIndex(event => event.startsWith(`start ${variant} `));

// Each profile ran once against the given backend and its lane's server, and
// its PHP server was stopped after the profile ended and before the lane's
// next profile.
function assertEveryServerRetired(events) {
  const running = new Set();
  let peak = 0;
  for (const event of events) {
    const [kind, ...rest] = event.split(' ');
    if (kind === 'start') {
      assert.deepEqual(rest.slice(1), ['sqlite', SECOND_SERVER.has(rest[0]) ? URLS.b : URLS.a], event);
      running.add(PROFILES[rest[0]]);
      peak = Math.max(peak, running.size);
    } else if (kind === 'docker') {
      assert.deepEqual(rest.slice(0, 3), ['stop', '--time', '30'], event);
      const tag = rest[3].replace(/^dw-scope-1-/, '').replace(/-server$/, '');
      assert.ok(running.delete(tag), `${tag} stopped once, after it started`);
      const end = events.indexOf(`end ${Object.keys(PROFILES).find(variant => PROFILES[variant] === tag)}`);
      assert.ok(end >= 0 && end < events.indexOf(event), `${tag} stopped after its profile ended`);
    }
  }
  assert.equal(running.size, 0, `servers left running: ${[...running]}`);
  assert.ok(peak <= 3, `at most three PHP servers run at once, not ${peak}`);
  const started = events.filter(event => event.startsWith('start ')).map(event => event.split(' ')[1]).sort();
  assert.deepEqual(started, Object.keys(PROFILES).sort(), 'all nine profiles ran once');
  assert.deepEqual(events.filter(event => event.startsWith('docker ')).map(event => event.split(' ').at(-1)).sort(),
    Object.values(PROFILES).map(tag => `dw-scope-1-${tag}-server`).sort(), 'every profile server stopped once');
}

test('all nine profiles pass and each PHP server is stopped after its profile', () => {
  const {child, statuses, events} = runLanes();
  assert.equal(child.status, 0, child.stderr);
  assert.deepEqual(new Set(Object.values(statuses)), new Set(['0']));
  assertEveryServerRetired(events);
  for (const [variant, tag] of Object.entries(PROFILES)) {
    assert.match(child.stdout, new RegExp(`::group::${tag} \\(exit 0, \\d+ s\\)\\nprofile output for ${variant}\\n::endgroup::`));
  }
  assert.doesNotMatch(child.stderr, /::error::|::warning::/);
});

test('a failed profile still runs its lane and fails the wrapper only after every status and log', () => {
  // The timer profile is second in its lane; the activity profile follows it.
  const {child, statuses, events} = runLanes({FAIL_timer: '7'});
  assert.equal(child.status, 1);
  assert.deepEqual(statuses, {...Object.fromEntries(Object.values(PROFILES).map(tag => [tag, '0'])), 'sdk-timers': '7'});
  assertEveryServerRetired(events);
  assert.ok(started(events, 'activity') > events.indexOf('end timer'), 'the lane continues after a failure');
  assert.match(child.stderr, /^::error::SDK direction profile sdk-timers exited 7$/m);
  assert.equal(child.stderr.match(/::error::/g).length, 1);
  assert.match(child.stdout, /::group::sdk-timers \(exit 7, \d+ s\)\nprofile output for timer\n::endgroup::/);
  assert.match(child.stdout, /::group::sdk-queries \(exit 0, \d+ s\)/, 'logs after the failure are still printed');
});

test('a failed stop warns without changing any profile status', () => {
  const {child, statuses, events} = runLanes({STOP_FAIL_sdk_updates: '1'});
  assert.equal(child.status, 0, child.stderr);
  assert.equal(statuses['sdk-updates'], '0');
  assert.match(child.stderr, /^::warning::The sdk-updates PHP server was not stopped; the final cleanup removes it$/m);
  assert.ok(started(events, 'update-failure') > events.indexOf('docker stop --time 30 dw-scope-1-sdk-updates-server'),
    'the lane continues after a failed stop');
});

test('a failed profile and a failed stop of its server keep the profile status', () => {
  const {child, statuses} = runLanes({FAIL_query: '3', STOP_FAIL_sdk_queries: '1'});
  assert.equal(child.status, 1);
  assert.equal(statuses['sdk-queries'], '3');
  assert.match(child.stderr, /^::error::SDK direction profile sdk-queries exited 3$/m);
});

test('the wrapper refuses to run without a second native server', () => {
  const child = spawnSync('bash', [LANES, 'sqlite', URLS.a], {
    encoding: 'utf8', env: {...process.env, RESOURCE_SCOPE: 'dw-scope-1'}, cwd: tmpdir(),
  });
  assert.notEqual(child.status, 0);
  assert.match(child.stderr, /Set the second native URL, a server with its own database/);
});

test('the wrapper refuses to run without an owning resource scope', () => {
  const child = spawnSync('bash', [LANES, 'sqlite', URLS.a, URLS.b], {
    encoding: 'utf8', env: {...process.env, RESOURCE_SCOPE: ''}, cwd: tmpdir(),
  });
  assert.equal(child.status, 2);
  assert.match(child.stderr, /Set the owning isolated network\/resource scope/);
});

// A native server is shared by the lanes that name it. Two profiles whose
// fixtures name the same task queue would see each other's workers on one
// server, so they share a lane or run on different servers.
test('profiles that share a task queue on one server run in the same lane', () => {
  const script = readFileSync(LANES, 'utf8');
  const parsed = [...script.slice(script.indexOf('lanes=(')).split(')')[0].matchAll(/^ {2}"([^"]+)"$/gm)]
    .map(match => match[1].split(' '));
  const servers = parsed.map(([server]) => server);
  assert.deepEqual([...new Set(servers)].sort(), ['a', 'b'], 'each lane names server a or b');
  const lanes = parsed.map(([, ...entries]) => entries.map(entry => entry.split(':')[1]));
  assert.deepEqual(lanes.flat().sort(), Object.values(PROFILES).sort(), 'every profile in exactly one lane');
  for (const [index, lane] of parsed.entries()) {
    for (const entry of lane.slice(1)) {
      assert.equal(SECOND_SERVER.has(entry.split(':')[0]), servers[index] === 'b', `${entry} runs on its declared server`);
    }
  }
  const queues = tag => {
    const directory = new URL(`../Fixtures/ServerParityProfiles/published-${tag}/`, import.meta.url);
    const text = readdirSync(directory).filter(name => name.endsWith('.json'))
      .map(name => readFileSync(new URL(name, directory), 'utf8')).join('\n');
    return new Set(text.match(/server-parity-sdk-[a-z-]+/g) ?? []);
  };
  for (const [index, lane] of lanes.entries()) {
    for (const [offset, other] of lanes.slice(index + 1).entries()) {
      if (servers[index] !== servers[index + 1 + offset]) continue;
      for (const tag of lane) {
        for (const peer of other) {
          const shared = [...queues(tag)].filter(queue => queues(peer).has(queue));
          assert.deepEqual(shared, [], `${tag} and ${peer} share ${shared} but run in different lanes`);
        }
      }
    }
  }
  assert.ok(queues('sdk-signals').has('server-parity-sdk-signals'), 'queues are read from the fixtures');
});
