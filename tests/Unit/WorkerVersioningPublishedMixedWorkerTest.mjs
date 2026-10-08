import assert from 'node:assert/strict';
import test from 'node:test';
import { mixedVersioningPasses } from '../../scripts/conformance/worker-versioning-mixed-published-workers.mjs';

const versions = { server:'2.5.10', 'sdk-php':'2.2.4', 'sdk-python':'2.5.0', 'sdk-rust':'3.4.0' };
function observations() {
  const pairs = [['rust', 'php'], ['php', 'rust'], ['rust', 'python'], ['python', 'rust']];
  return {
    schema:'durable-workflow.conformance.mixed-worker-versioning', version:1, outcome:'pass',
    worker_execution:'managed_php_python_rust_workers', local_product_source_checkouts_used:false,
    artifact_versions:versions, server_image:`durableworkflow/server@sha256:${'a'.repeat(64)}`,
    registry_packages:{ php:{ version:'2.2.4', dist:{ url:'https://api.github.com/repos/durable-workflow/sdk-php/zipball/published', reference:'published' } },
      python:{ metadata:{ version:'2.5.0' }, download_info:{ archive_info:{ hashes:{ sha256:'b'.repeat(64) } } } },
      rust:{ version:'3.4.0', source:'registry+https://github.com/rust-lang/crates.io-index', checksum:'c'.repeat(64) } },
    cells:pairs.map(([v1, v2], cellIndex) => {
      const old = { workflow_id:`old-${cellIndex}`, run_id:`old-run-${cellIndex}` };
      const newer = { workflow_id:`new-${cellIndex}`, run_id:`new-run-${cellIndex}` };
      const observation = (language, run, boundary) => language === 'rust'
        ? { processed:1, side_effect_calls:1, callbacks:[{ ...run, boundary }] }
        : [{ kind:'poll', task:run }, { kind:'producer', workflow_id:run.workflow_id }];
      const waiting = (language, run, build) => ({ description:{ ...run, compatibility:build, status:'waiting' },
        observation:observation(language, run, 'ready') });
      const completed = (language, run, build, index) => ({ language, run,
        description:{ ...run, compatibility:build, status:'completed' }, result:{ producer:`${language}-v${index + 1}` },
        observation:observation(language, run, 'completed'),
        history:{ events:[{ event_type:'SideEffectRecorded' }, { event_type:'WorkflowCompleted' }] } });
      const wrong = (language) => [0, 1].map(() => language === 'rust' ? { processed:0 } : { kind:'poll', task:null });
      return { key:`${v1}_v1_${v2}_v2`, outcome:'pass', v1_language:v1, v2_language:v2,
        v1_build:'v1', v2_build:'v2', worker_ids:['w1', 'w2'], old, new:newer,
        original:{ ...old, compatibility:'v1' }, registered:[{ registered:true, pid:10 }, { registered:true, pid:11 }],
        registry:{ workers:[v1, v2].map((language, index) => ({ worker_id:`w${index + 1}`,
          build_id:`v${index + 1}`, runtime:language, sdk_version:`durable-workflow-${language}/${versions[`sdk-${language}`]}` })) },
        incompatible_old_polls:wrong(v2), incompatible_new_polls:wrong(v1),
        old_waiting:waiting(v1, old, 'v1'), new_waiting:waiting(v2, newer, 'v2'),
        completed:[completed(v2, newer, 'v2', 1), completed(v1, old, 'v1', 0)] };
    }),
  };
}

test('all four published mixed worker cohorts pass', () => {
  assert.equal(mixedVersioningPasses(observations(), versions), true);
});

const invalid = {
  'missing direction':(r) => { r.cells.pop(); },
  'duplicate direction':(r) => { r.cells[3] = r.cells[0]; },
  'incompatible PHP delivery':(r) => { r.cells[0].incompatible_old_polls[0].task = r.cells[0].old; },
  'incompatible Rust execution':(r) => { r.cells[1].incompatible_old_polls[0].processed = 1; },
  'no incompatible poll':(r) => { r.cells[0].incompatible_old_polls = []; },
  'no compatible Rust delivery':(r) => { r.cells[0].old_waiting.observation.processed = 0; },
  'no compatible PHP delivery':(r) => { r.cells[0].completed[0].observation[0].task = null; },
  'opposite cohort delivered to Python':(r) => { r.cells[2].completed[0].observation[0].task = r.cells[2].old; },
  'changed original build':(r) => { r.cells[0].completed[1].description.compatibility = 'v2'; },
  'changed original run':(r) => { r.cells[0].completed[1].description.run_id = 'replacement-run'; },
  'wrong result':(r) => { r.cells[0].completed[1].result.producer = 'rust-v2'; },
  'duplicate completion':(r) => { r.cells[0].completed[1].history.events.push({ event_type:'WorkflowCompleted' }); },
  'repeated PHP producer':(r) => { r.cells[0].completed[0].observation.push({ kind:'producer', workflow_id:r.cells[0].new.workflow_id }); },
  'repeated Rust producer':(r) => { r.cells[0].completed[1].observation.side_effect_calls = 2; },
  'wrong installed SDK':(r) => { r.cells[0].registry.workers[0].sdk_version = 'durable-workflow-rust/3.3.3'; },
  'wrong artifact tuple':(r) => { r.artifact_versions.server = '2.5.9'; },
  'missing registration':(r) => { r.cells[0].registry.workers.pop(); },
  'unpublished image':(r) => { r.server_image = 'local:latest'; },
  'missing Python checksum':(r) => { delete r.registry_packages.python.download_info.archive_info.hashes.sha256; },
  'missing PHP distribution':(r) => { delete r.registry_packages.php.dist; },
  'Rust from checkout':(r) => { r.registry_packages.rust.source = null; },
};
for (const [name, mutate] of Object.entries(invalid)) {
  test(`reject ${name} despite a pass label`, () => {
    const report = structuredClone(observations());
    mutate(report);
    assert.equal(mixedVersioningPasses(report, versions), false);
  });
}
test('failed or absent observations cannot pass', () => {
  assert.equal(mixedVersioningPasses(null, versions), false);
  assert.equal(mixedVersioningPasses({ ...observations(), outcome:'fail' }, versions), false);
});
