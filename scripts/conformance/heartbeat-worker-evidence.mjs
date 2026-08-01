export class MalformedWorkerLogError extends Error {
  constructor(workerId, lineNumber, line) {
    super(`worker ${workerId} emitted a malformed JSON log record at line ${lineNumber}: ${line}`);
    this.name = 'MalformedWorkerLogError';
    this.workerId = workerId;
    this.lineNumber = lineNumber;
    this.line = line;
  }
}

export class WorkerLogIdentityError extends Error {
  constructor(expectedWorkerId, observedWorkerId) {
    super(`expected logs for worker ${expectedWorkerId}, but the registration belongs to ${observedWorkerId}`);
    this.name = 'WorkerLogIdentityError';
    this.expectedWorkerId = expectedWorkerId;
    this.observedWorkerId = observedWorkerId;
  }
}

export class WorkProcessedEvidenceTimeoutError extends Error {
  constructor(workerId, baselineCount, attempts) {
    super(
      `worker ${workerId} work_processed count did not advance from ${baselineCount} after ${attempts} attempts`,
    );
    this.name = 'WorkProcessedEvidenceTimeoutError';
    this.workerId = workerId;
    this.baselineCount = baselineCount;
    this.attempts = attempts;
  }
}

export function parseWorkerLogSnapshot(logOutput, expectedWorkerId) {
  const records = [];
  const lines = String(logOutput ?? '').split(/\r?\n/);
  for (const [index, line] of lines.entries()) {
    if (!line.trim()) continue;
    let record;
    try {
      record = JSON.parse(line);
    } catch {
      throw new MalformedWorkerLogError(expectedWorkerId, index + 1, line);
    }
    if (!record || typeof record !== 'object' || Array.isArray(record)) {
      throw new MalformedWorkerLogError(expectedWorkerId, index + 1, line);
    }
    records.push(record);
  }

  const registrations = records.filter((record) => record.event === 'worker_registered');
  for (const registration of registrations) {
    if (registration.worker_id !== expectedWorkerId) {
      throw new WorkerLogIdentityError(expectedWorkerId, registration.worker_id ?? 'missing-worker-id');
    }
  }
  if (registrations.length === 0) {
    throw new WorkerLogIdentityError(expectedWorkerId, 'missing-registration');
  }

  return {
    worker_id: expectedWorkerId,
    records,
    work_processed_records: records.filter((record) => record.event === 'work_processed'),
  };
}

export function captureWorkProcessedBaseline({ workerId, logOutput }) {
  const snapshot = parseWorkerLogSnapshot(logOutput, workerId);
  return {
    worker_id: workerId,
    work_processed_count: snapshot.work_processed_records.length,
  };
}

export async function waitForWorkProcessedAdvance({
  baseline,
  readLogs,
  maxAttempts,
  retryDelayMs,
  wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
  observedAt = () => new Date().toISOString(),
}) {
  if (!Number.isInteger(maxAttempts) || maxAttempts < 1) {
    throw new Error('maxAttempts must be a positive integer');
  }
  if (!Number.isInteger(retryDelayMs) || retryDelayMs < 0) {
    throw new Error('retryDelayMs must be a non-negative integer');
  }

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const snapshot = parseWorkerLogSnapshot(await readLogs(), baseline.worker_id);
    const observedCount = snapshot.work_processed_records.length;
    if (observedCount < baseline.work_processed_count) {
      throw new Error(
        `worker ${baseline.worker_id} work_processed count regressed from ${baseline.work_processed_count} to ${observedCount}`,
      );
    }
    if (observedCount > baseline.work_processed_count) {
      return {
        worker_id: baseline.worker_id,
        baseline_count: baseline.work_processed_count,
        observed_count: observedCount,
        attempts: attempt,
        observed_at: observedAt(),
        new_records: snapshot.work_processed_records.slice(baseline.work_processed_count),
      };
    }
    if (attempt < maxAttempts) await wait(retryDelayMs);
  }

  throw new WorkProcessedEvidenceTimeoutError(
    baseline.worker_id,
    baseline.work_processed_count,
    maxAttempts,
  );
}
