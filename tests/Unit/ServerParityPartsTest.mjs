// Each Shared Server fixtures database job runs in six parts on separate
// runners. Every step either prepares the part (and so runs in every part) or
// belongs to exactly one part, so no check runs twice or not at all. The one
// exception starts the published PHP and native nodes that the corpus and
// restarts parts each need, and runs in exactly those two.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const workflow = readFileSync('.github/workflows/server-parity.yml', 'utf8');
const PARTS = ['native-tests', 'corpus', 'restarts', 'cohorts', 'sdk-directions', 'corrected-markers'];
const SHARED_NODES = 'Start the published PHP and native nodes the corpus parts share';
const EVERY_PART = [/^actions\/checkout@/, /^Fetch /, /^Create TLS fixture certificates$/, /^Bootstrap independent /,
  /^Collect /, /^Summarize /, /^Retain /];

function job(name) {
  const start = workflow.indexOf(`\n  ${name}:\n`);
  assert.ok(start >= 0, name);
  const rest = workflow.slice(start + 1);
  const end = rest.slice(1).search(/\n {2}[a-z0-9-]+:\n/);
  return end < 0 ? rest : rest.slice(0, end + 2);
}

function steps(block) {
  return block.split(/\n(?= {6}- )/).slice(1).map(text => ({
    name: /^ {6}- (?:name: |uses: )(.*)$/m.exec(text)[1],
    id: /^ {8}id: (.*)$/m.exec(text)?.[1],
    condition: /^ {8}if: (.*)$/m.exec(text)?.[1] ?? null,
    text,
  }));
}

// The parts a step runs in, read from its condition.
function partsOf(step) {
  if (EVERY_PART.some(pattern => pattern.test(step.name))) return PARTS;
  const either = /^\$\{\{ matrix\.part == '([a-z-]+)' \|\| matrix\.part == '([a-z-]+)' \}\}$/.exec(step.condition ?? '');
  if (either) return [either[1], either[2]];
  const only = /matrix\.part == '([a-z-]+)'/.exec(step.condition ?? '');
  if (only) return [only[1]];
  const except = /matrix\.part != '([a-z-]+)'/.exec(step.condition ?? '');
  if (except) return PARTS.filter(part => part !== except[1]);
  return PARTS;
}

for (const [name, key] of [['mysql-execution', 'Compare three runtimes'], ['postgres-storage', 'Compare three runtimes'],
  ['execution-and-codec', 'Execute and compare Rust']]) {
  test(`${name} runs every check in exactly one of six parts`, () => {
    const block = job(name);
    assert.match(block, /^ {8}part: \[native-tests, corpus, restarts, cohorts, sdk-directions, corrected-markers\]$/m);
    assert.match(block, /^ {6}DW_PARITY_PART: \$\{\{ matrix\.part \}\}$/m);
    assert.match(block, /^ {4}timeout-minutes: 60$/m);
    const all = steps(block);
    for (const step of all) {
      const parts = partsOf(step);
      if (parts.length === PARTS.length) {
        assert.ok(EVERY_PART.some(pattern => pattern.test(step.name)), `${name}: "${step.name}" must belong to one part`);
      } else if (step.name === SHARED_NODES) {
        assert.deepEqual(parts, ['corpus', 'restarts'], `${name}: the shared nodes start for exactly the corpus parts`);
      } else if (step.name !== 'Build the native server') {
        assert.equal(parts.length, 1, `${name}: "${step.name}"`);
      }
    }
    const inPart = part => all.filter(step => partsOf(step).length === 1 && partsOf(step)[0] === part).map(step => step.name);
    assert.ok(inPart('corpus').some(step => step.startsWith(key)), `${name}: the corpus comparison is in the corpus part`);
    assert.equal(all.filter(step => step.name === SHARED_NODES).length, 1, `${name}: one shared node start`);
    assert.equal(all.find(step => step.name === SHARED_NODES).id, 'prepare_runtime_comparison', name);
    // Both parts need every node the original step started, including the
    // published PHP maintenance loop the patch profiles pause and resume.
    assert.match(all.find(step => step.name === SHARED_NODES).text, /--name "\$RESOURCE_SCOPE-php-maintenance"/, name);
    // The profiles and restart cases run in their own part, against nodes the
    // part starts itself, so the corpus part no longer waits for them.
    const restarts = inPart('restarts');
    assert.equal(restarts.length, 1, `${name}: ${restarts}`);
    assert.match(restarts[0], /^Compare published patch, legacy-marker and range-repair profiles/, name);
    const profiles = all.find(step => step.name === restarts[0]).text;
    for (const profile of ['published-sdk-patch-profile.sh', 'legacy-marker-profile.sh', 'range-repair-profile.sh']) {
      assert.ok(profiles.includes(profile), `${name}: ${profile} runs in the restarts part`);
    }
    const corpus = all.find(step => step.name.startsWith(key)).text;
    assert.ok(!/published-sdk-patch-profile|legacy-marker-profile|range-repair-profile|docker kill/.test(corpus),
      `${name}: the corpus comparison no longer runs the profiles or restart cases`);
    // The published-SDK direction lanes run once, in their own part, so the
    // corpus part no longer waits for them.
    assert.deepEqual(inPart('sdk-directions'), ['Compare every published-SDK direction profile in three concurrent lanes'], name);
    const lanes = all.filter(step => step.text.includes('sdk-direction-lanes.sh')).map(step => step.name);
    assert.deepEqual(lanes, ['Compare every published-SDK direction profile in three concurrent lanes'], `${name}: lanes run in one step`);
    const cohorts = inPart('cohorts');
    for (const cohort of ['Start the shared native node the cohorts use', 'Compare separate configured role-token cohort',
      'Back up, take over and resume a separate published PHP upgrade source',
      'Back up, take over and resume a published PHP 2.9.7 upgrade source',
      'Compare installed local external payload consumers',
      'Compare published query deadlines and installed Worker authority',
      'Compare pinned PHP, embedded and native worker-fencing source cohort',
      'Compare SDK temporary database pressure with declared fault limits']) {
      assert.ok(cohorts.includes(cohort), `${name}: ${cohort} is in the cohorts part`);
    }
    // The corrected marker cohort runs alone in its part, against that part's
    // own native node, so the cohorts part no longer waits for it.
    assert.deepEqual(inPart('corrected-markers'), ['Start the native node the corrected marker cohort uses',
      'Compare corrected markers, ranges, clocks and activity expiry', 'Retire corrected cohort resources'], name);
    // Cleanups still run after a failure within their part.
    for (const step of all.filter(step => step.name.startsWith('Retire '))) {
      const part = step.name === 'Retire corrected cohort resources' ? 'corrected-markers' : 'cohorts';
      assert.equal(step.condition, `\${{ always() && matrix.part == '${part}' }}`, step.name);
    }
    const corrected = all.find(step => step.name.startsWith('Compare corrected markers'));
    assert.match(corrected.condition, /steps\.prepare_corrected\.outcome == 'success'/);
    assert.equal(all.find(step => step.name === 'Start the native node the corrected marker cohort uses').id, 'prepare_corrected');
    assert.doesNotMatch(block, /steps\.prepare_runtime_comparison\.outcome/);
    assert.equal(all.find(step => step.name === 'Start the shared native node the cohorts use').id, 'prepare_cohorts');
    // Each part retains its own summary.
    assert.match(block, /^ {10}name: [a-z-]+(?:-\$\{\{ matrix\.label \}\})?-\$\{\{ matrix\.part \}\}$/m);
    // The native database suites or the codec suite run once, in native-tests.
    const native = inPart('native-tests');
    assert.ok(native.some(step => /^(Verify TLS|Execute official Rust codec)/.test(step)), `${name}: native-tests checks`);
  });
}

test('the database bootstraps run the native suites only in the native-tests part', () => {
  for (const name of ['mysql-execution', 'postgres-storage']) {
    const bootstrap = steps(job(name)).find(step => step.name.startsWith('Bootstrap independent '));
    assert.match(bootstrap.text, /-e DW_PARITY_PART \\\n/, name);
    assert.match(bootstrap.text, /'if \[ "\$DW_PARITY_PART" = native-tests \]; then cargo test [^']* --test execution [^']*; fi && cargo build --locked --offline'/, name);
  }
});

test('every readiness probe bounds each request, so one silent connection cannot stall a part', () => {
  const probes = workflow.match(/curl[^\n]*\n?[^\n]*\/api\/ready/g) ?? [];
  assert.ok(probes.length >= 20, `${probes.length} readiness probes`);
  for (const probe of probes) assert.match(probe, /--connect-timeout \d+ --max-time \d+ "?http:/, probe);
});
