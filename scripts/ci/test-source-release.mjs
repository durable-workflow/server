#!/usr/bin/env node

import assert from 'node:assert/strict';
import {execFileSync, spawnSync} from 'node:child_process';
import {copyFile, lstat, mkdir, mkdtemp, readFile, readlink, rm, symlink, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const generator = join(repositoryRoot, 'scripts/ci/sync-source-release.mjs');
const remediation = 'node scripts/ci/sync-source-release.mjs --write';

function runGenerator(root, mode) {
  return spawnSync(process.execPath, [generator, ...mode, '--root', root], {
    cwd: repositoryRoot,
    encoding: 'utf8',
  });
}

function nextServerRelease(version) {
  const match = /^(.*-(?:alpha|beta|rc)\.)(\d+)$/.exec(version);
  if (match) {
    return `${match[1]}${Number(match[2]) + 1}`;
  }
  return nextPatch(version);
}

function nextPatch(version) {
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(version);
  assert.ok(match, `chart version ${version} must be stable SemVer`);
  return `${match[1]}.${match[2]}.${Number(match[3]) + 1}`;
}

async function copyRepositoryFiles(destination) {
  const paths = execFileSync(
    'git',
    ['ls-files', '--cached', '--others', '--exclude-standard', '-z'],
    {cwd: repositoryRoot, encoding: 'utf8'},
  ).split('\0').filter(Boolean);

  for (const path of paths) {
    const source = join(repositoryRoot, path);
    const target = join(destination, path);
    const stat = await lstat(source);
    await mkdir(dirname(target), {recursive: true});
    if (stat.isSymbolicLink()) {
      await symlink(await readlink(source), target);
    } else if (stat.isFile()) {
      await copyFile(source, target);
    }
  }
  return paths;
}

test('legacy recovery resolves a bounded Composer source identity through the generator', async () => {
  const temporaryRoot = await mkdtemp(join(tmpdir(), 'server-legacy-source-release-'));
  try {
    await writeFile(
      join(temporaryRoot, 'composer.json'),
      `${JSON.stringify({
        extra: {
          'durable-workflow': {
            'product-train': '2.0.0-rc.46',
          },
        },
      }, null, 2)}\n`,
    );

    const resolved = runGenerator(temporaryRoot, ['--print-legacy-server-version']);
    assert.equal(resolved.status, 0, resolved.stderr);
    assert.equal(resolved.stdout, '2.0.0-rc.46\n');

    await writeFile(
      join(temporaryRoot, 'composer.json'),
      '{"extra":{"durable-workflow":{"product-train":"2.0.0"}}}\n',
    );
    const stable = runGenerator(temporaryRoot, ['--print-legacy-server-version']);
    assert.equal(stable.status, 0, stable.stderr);
    assert.equal(stable.stdout, '2.0.0\n');

    await writeFile(
      join(temporaryRoot, 'composer.json'),
      '{"extra":{"durable-workflow":{"product-train":"latest"}}}\n',
    );
    const invalid = runGenerator(temporaryRoot, ['--print-legacy-server-version']);
    assert.equal(invalid.status, 1);
    assert.match(invalid.stderr, /product-train must be an exact SemVer value/);
  } finally {
    await rm(temporaryRoot, {recursive: true, force: true});
  }
});

test('a stable server source identity fans out through current release consumers', async () => {
  const temporaryRoot = await mkdtemp(join(tmpdir(), 'server-stable-source-release-'));
  try {
    await copyRepositoryFiles(temporaryRoot);
    const manifestPath = join(temporaryRoot, 'resources/release/source-release.json');
    const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
    manifest.server.version = '2.0.0';
    manifest.helm_chart.version = nextPatch(manifest.helm_chart.version);
    await writeFile(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);

    const write = runGenerator(temporaryRoot, ['--write']);
    assert.equal(write.status, 0, write.stderr);
    const check = runGenerator(temporaryRoot, ['--check']);
    assert.equal(check.status, 0, check.stderr);

    const chart = await readFile(
      join(temporaryRoot, 'k8s/helm/durable-workflow/Chart.yaml'),
      'utf8',
    );
    assert.match(chart, /^appVersion: "2\.0\.0"$/m);
    assert.match(chart, /docker\.io\/durableworkflow\/server:2\.0\.0/);
  } finally {
    await rm(temporaryRoot, {recursive: true, force: true});
  }
});

test('a simulated next release fans out without rewriting history or retaining stale current identity', async () => {
  const temporaryRoot = await mkdtemp(join(tmpdir(), 'server-source-release-'));
  try {
    const paths = await copyRepositoryFiles(temporaryRoot);
    const manifestPath = join(temporaryRoot, 'resources/release/source-release.json');
    const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
    const previousServer = manifest.server.version;
    const previousChart = manifest.helm_chart.version;
    const nextServer = nextServerRelease(previousServer);
    const nextChart = nextPatch(previousChart);
    manifest.server.version = nextServer;
    manifest.helm_chart.version = nextChart;
    await writeFile(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);

    const upgradingPath = 'k8s/helm/durable-workflow/docs/UPGRADING.md';
    const chartPath = 'k8s/helm/durable-workflow/Chart.yaml';
    const upgradingBefore = await readFile(join(temporaryRoot, upgradingPath), 'utf8');
    const chartBefore = await readFile(join(temporaryRoot, chartPath), 'utf8');
    assert.ok(
      upgradingBefore.includes('## Per-version migration notes'),
      'upgrade history must retain its versioned-record boundary',
    );
    const historyOffset = chartBefore.indexOf('  artifacthub.io/changes: |');
    assert.notEqual(historyOffset, -1, 'Chart.yaml must retain its historical changelog');
    const chartHistoryBefore = chartBefore.slice(historyOffset);

    const write = runGenerator(temporaryRoot, ['--write']);
    assert.equal(write.status, 0, write.stderr);
    assert.match(write.stdout, new RegExp(`Server ${nextServer.replaceAll('.', '\\.')} and Helm chart ${nextChart.replaceAll('.', '\\.')}`));

    const check = runGenerator(temporaryRoot, ['--check']);
    assert.equal(check.status, 0, check.stderr);

    const expectedComposerHash = execFileSync(
      'php',
      [
        '-r',
        '$c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);'
          + '$r=[];foreach(["name","version","require","require-dev","conflict","replace",'
          + '"provide","minimum-stability","prefer-stable","repositories","extra"] as $k)'
          + '{if(isset($c[$k])){$r[$k]=$c[$k];}}'
          + 'if(isset($c["config"]["platform"])){$r["config"]["platform"]=$c["config"]["platform"];} '
          + 'ksort($r);echo md5(json_encode($r));',
        join(temporaryRoot, 'composer.json'),
      ],
      {encoding: 'utf8'},
    );
    const generatedLock = JSON.parse(
      await readFile(join(temporaryRoot, 'composer.lock'), 'utf8'),
    );
    assert.equal(generatedLock['content-hash'], expectedComposerHash);

    assert.equal(
      await readFile(join(temporaryRoot, upgradingPath), 'utf8'),
      upgradingBefore,
      'release generation must not rewrite immutable upgrade history',
    );
    const chartAfter = await readFile(join(temporaryRoot, chartPath), 'utf8');
    assert.equal(
      chartAfter.slice(chartAfter.indexOf('  artifacthub.io/changes: |')),
      chartHistoryBefore,
      'release generation must not rewrite the Artifact Hub changelog',
    );
    assert.match(chartAfter, new RegExp(`^version: ${nextChart.replaceAll('.', '\\.')}$`, 'm'));
    assert.match(chartAfter, new RegExp(`^appVersion: "${nextServer.replaceAll('.', '\\.')}"$`, 'm'));

    const stale = [];
    for (const path of paths) {
      const bytes = await readFile(join(temporaryRoot, path));
      if (bytes.includes(0)) {
        continue;
      }
      let source = bytes.toString('utf8');
      if (path === chartPath) {
        source = source.slice(0, source.indexOf('  artifacthub.io/changes: |'));
      } else if (path === upgradingPath) {
        source = source.slice(0, source.indexOf('## Per-version migration notes'));
      }
      if (source.includes(previousServer)) {
        stale.push(path);
      }
    }
    if (previousServer.includes('-')) {
      assert.deepEqual(
        stale,
        [],
        `prior-current Server identity remains outside immutable history: ${stale.join(', ')}`,
      );
    }

    const smallComposePath = join(temporaryRoot, 'docker-compose.small-cluster.yml');
    await writeFile(
      smallComposePath,
      (await readFile(smallComposePath, 'utf8')).replace(nextServer, previousServer),
    );
    const drift = runGenerator(temporaryRoot, ['--check']);
    assert.equal(drift.status, 1);
    assert.match(drift.stderr, /docker-compose\.small-cluster\.yml/);
    assert.ok(drift.stderr.includes(remediation));
  } finally {
    await rm(temporaryRoot, {recursive: true, force: true});
  }
});
