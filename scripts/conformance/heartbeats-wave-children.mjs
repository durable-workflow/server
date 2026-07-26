import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const rowsPath = path.resolve(process.argv[2] ?? '');
const resultPath = path.resolve(process.argv[3] ?? '');

if (!process.argv[2] || !process.argv[3]) {
  process.stderr.write(
    'usage: heartbeats-wave-children.mjs <rows-file> <result-file>\n',
  );
  process.exit(2);
}

const cells = {};
for (const line of fs.readFileSync(rowsPath, 'utf8').split(/\r?\n/).filter(Boolean)) {
  const [cell, pidValue, pgidValue, exitCodeValue, settlementValue] = line.split('\t');
  const [settledValue, forcedSignalValue] = String(settlementValue ?? '').split(':');
  const pid = Number.parseInt(pidValue, 10);
  const processGroupId = Number.parseInt(pgidValue, 10);
  const exitCode = Number.parseInt(exitCodeValue, 10);
  if (!cell
    || !Number.isInteger(pid)
    || !Number.isInteger(processGroupId)
    || !Number.isInteger(exitCode)
    || !['true', 'false'].includes(settledValue)
    || !forcedSignalValue) {
    throw new Error(`invalid heartbeat child-process row: ${line}`);
  }
  cells[cell] = {
    pid,
    process_group_id: processGroupId,
    exit_code: exitCode,
    settled: settledValue === 'true',
    forced_signal: forcedSignalValue === 'none' ? null : forcedSignalValue,
  };
}

const requiredCells = ['php', 'python', 'rust', 'waterline'];
const requiredCellsPresent = requiredCells.every((cell) => cells[cell]);
const allSettled = requiredCellsPresent
  && requiredCells.every((cell) => cells[cell].settled === true);
const result = {
  schema: 'durable-workflow.v2.heartbeat-runtime.shared-wave-children',
  version: 1,
  observed_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  outcome: allSettled ? 'pass' : 'fail',
  required_cells: requiredCells,
  required_cells_present: requiredCellsPresent,
  all_process_groups_settled: allSettled,
  cells,
};
const temporary = `${resultPath}.tmp-${process.pid}`;
fs.writeFileSync(temporary, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
fs.renameSync(temporary, resultPath);
