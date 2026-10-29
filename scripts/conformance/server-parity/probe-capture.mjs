import {closeSync, copyFileSync, mkdtempSync, openSync, readFileSync, rmSync, statSync} from 'node:fs';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';

// Full SDK wire observations can exceed a pipe's buffer even when every actor
// succeeds. Keep output on disk until the child exits, then admit complete bytes.
export function captureProbe(command, args, {directory, env, timeout = 45000, preserveStdout}) {
  const capture = mkdtempSync(join(directory, '.probe-capture-'));
  const stdoutPath = join(capture, 'stdout');
  const stderrPath = join(capture, 'stderr');
  let stdoutFd, stderrFd;
  try {
    stdoutFd = openSync(stdoutPath, 'wx', 0o600);
    stderrFd = openSync(stderrPath, 'wx', 0o600);
    const result = spawnSync(command, args, {env, timeout, stdio: ['ignore', stdoutFd, stderrFd]});
    for (const [path, limit, stream] of [[stdoutPath, 64 * 1024 * 1024, 'stdout'], [stderrPath, 4 * 1024 * 1024, 'stderr']]) {
      if (statSync(path).size > limit) {
        const error = new Error(`Probe ${stream} exceeds ${limit} bytes`);
        error.code = 'DW_PARITY_CAPTURE_LIMIT';
        return {...result, error: result.error ?? error, stdout: '', stderr: ''};
      }
    }
    if (preserveStdout) copyFileSync(stdoutPath, preserveStdout);
    return {...result, stdout: readFileSync(stdoutPath, 'utf8'), stderr: readFileSync(stderrPath, 'utf8'),
      stdoutPath: preserveStdout};
  } finally {
    if (stdoutFd !== undefined) closeSync(stdoutFd);
    if (stderrFd !== undefined) closeSync(stderrFd);
    rmSync(capture, {recursive: true, force: true});
  }
}

// The probe diagnostics a record keeps whatever the probe's exit status:
// only lines of these exact, value-free shapes. Other stderr is not kept.
const NOTE_SHAPES = [/^\{"many_updates_submit_retry":\{"index":\d{1,6},"attempt":\d,"status":\d{3}\}\}$/];

export function probeNotes(stderr, limit = 20) {
  return String(stderr ?? '').split('\n').map(line => line.trim())
    .filter(line => NOTE_SHAPES.some(shape => shape.test(line))).slice(0, limit);
}
