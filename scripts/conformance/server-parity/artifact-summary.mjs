import {constants, existsSync, lstatSync, mkdirSync, openSync, readSync, readdirSync, closeSync, writeFileSync} from 'node:fs';
import {basename, dirname, join, relative, resolve} from 'node:path';
import {pathToFileURL} from 'node:url';

const FILE_LIMIT = 64 * 1024;
const CONTENT_LIMIT = 2 * 1024 * 1024;
const ENTRY_LIMIT = 128;

function priority(path, failed) {
  const name = basename(path);
  if (/\.status$/.test(name) || /-status\.txt$/.test(name)) return -1;
  if (failed && /(?:^|\/)query-authority\/clients\/(?:php|rust)\/[^/]+\/(?:client\.stderr|observer\.log)$/.test(path)) return 0;
  if (/^(rust|mysql|postgres|comparator|codec-load|artifact-summary)(-execution)?-tests\.txt$/.test(name)) return 0;
  if (/^(comparison|compare)([-.].*)?\.json$/.test(name) || /-comparison\.json$/.test(name)) return 1;
  if (failed && (/^(?:php|embedded|rust)-record\.txt$/.test(name)
      || /^sdk-(?:signals|queries)-profile\.log$/.test(name))) return 1;
  if (failed && (/\.stderr$/.test(name) || /(?:stderr|error|failure|bootstrap|install|build).*(?:\.log|\.txt)$/.test(name)
      || /^(?:php|rust|native)(?:[-.].*)?\.log$/.test(name) || /server\.log$/.test(name))) return 2;
  if (/^(identity|build|database-state|database-memory-limit)\.json$/.test(name)) return 3;
  if (/(?:-status|-integrity|-version|-revision|-controls|-contract)\.txt$/.test(name) || /\.status$/.test(name)
      || /^(SHA256SUMS|source-hashes\.sha256)$/.test(name)) return 4;
  return null;
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
  const selected = files.map(file => ({...file, priority: priority(file.path, outcome !== 'success')}))
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
    retained.push({path: file.path, source_bytes: file.source_bytes, retained_bytes: size, tail_only: size < file.source_bytes});
    bytes += size;
  }
  const summary = {
    schema: 'durable-workflow.server-parity-artifact-summary.v1', outcome, revision,
    scanned_files: files.length, scanned_bytes: files.reduce((sum, file) => sum + file.source_bytes, 0),
    retained_bytes: bytes, retained,
    omitted_files: files.length - retained.length,
    limits: {file_bytes: FILE_LIMIT, content_bytes: CONTENT_LIMIT, entries: ENTRY_LIMIT,
      excluded_directories: ['vendor', 'node_modules', 'target', '.git', 'objects']},
    scope: 'Test summaries and bounded diagnostic tails only. Raw records, HTTP journals, databases and payload bytes are not retained. This summary does not rerun or qualify assertions.',
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
