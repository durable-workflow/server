import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {copyFile, mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {dirname, join} from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));

test('the PHP image context excludes local credentials and preserves application inputs', {timeout: 120_000}, async () => {
  const temporary = await mkdtemp(join(tmpdir(), 'server-php-image-context-'));
  try {
    const context = join(temporary, 'context');
    await mkdir(context);
    await copyFile(join(root, '.dockerignore'), join(context, '.dockerignore'));
    try {
      await copyFile(join(root, 'Dockerfile.dockerignore'), join(context, 'Dockerfile.dockerignore'));
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    await writeFile(join(context, 'Dockerfile'), 'FROM scratch\nCOPY . /app/\n');
    const required = ['.env.example', 'examples/.env.example', 'artisan', 'composer.json', 'composer.lock',
      'app/Http/Controller.php', 'config/server.php', 'routes/api.php', 'public/index.php',
      'docker/bootstrap.sh', 'resources/release/source-release.json', 'static/openapi.yaml'];
    const excluded = ['.env', '.env.production', '.env.local', 'app/.env.staging',
      '.secrets/service-key', '.secrets/.env.example', '.aws/credentials', '.ssh/id_ed25519',
      'config/.secrets/key', 'config/.aws/.env.example', '.git/config', '.phpunit.result.cache',
      'vendor/autoload.php', 'node_modules/package/index.js',
      'tests/private-fixture', 'storage/logs/laravel.log', 'storage/framework/testing/database.sqlite'];
    for (const path of [...required, ...excluded]) {
      await mkdir(dirname(join(context, path)), {recursive: true});
      await writeFile(join(context, path), 'synthetic build-context canary\n');
    }
    const archive = join(temporary, 'context.tar');
    const args = ['buildx', 'build', '--progress=plain'];
    if (process.env.PHP_CONTEXT_TEST_BUILDER) args.push('--builder', process.env.PHP_CONTEXT_TEST_BUILDER);
    args.push('--output', `type=tar,dest=${archive}`, context);
    const built = spawnSync('docker', args, {encoding: 'utf8', timeout: 90_000});
    assert.equal(built.status, 0, built.error?.message || built.stderr);
    const listing = spawnSync('tar', ['-tf', archive], {encoding: 'utf8'});
    assert.equal(listing.status, 0, listing.stderr);
    const members = new Set(listing.stdout.split('\n').map(path => path.replace(/^\.\//, '')));
    for (const path of required) assert.ok(members.has(`app/${path}`), `Required build input omitted: ${path}`);
    const leaked = excluded.filter(path => members.has(`app/${path}`));
    assert.deepEqual(leaked, [], `Local/private build inputs were copied: ${leaked.join(', ')}`);
  } finally {
    await rm(temporary, {recursive: true, force: true});
  }
});
