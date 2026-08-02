export function dockerObjectMissing(result) {
  return result?.status !== 0
    && /no such (?:object|container)/i.test(`${result?.stderr ?? ''}\n${result?.stdout ?? ''}`);
}

function resultSummary(result) {
  const output = `${result?.stderr ?? ''}\n${result?.stdout ?? ''}`.trim();
  return output || `docker exited with status ${result?.status ?? 'unknown'}`;
}

function blockingSleep(milliseconds) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
}

export function removeNamedDockerContainer({
  containerName,
  initialInspection,
  inspect,
  remove,
  attempts = 3,
  retryDelayMilliseconds = 250,
  sleep = blockingSleep,
}) {
  if (typeof containerName !== 'string' || containerName === '') {
    throw new TypeError('Docker container cleanup requires a container name');
  }
  if (typeof inspect !== 'function' || typeof remove !== 'function') {
    throw new TypeError('Docker container cleanup requires inspect and remove functions');
  }
  if (!Number.isInteger(attempts) || attempts <= 0) {
    throw new TypeError('Docker container cleanup attempts must be a positive integer');
  }
  if (!Number.isInteger(retryDelayMilliseconds) || retryDelayMilliseconds < 0) {
    throw new TypeError('Docker container cleanup retry delay must be a non-negative integer');
  }

  let inspection = initialInspection ?? inspect();
  if (dockerObjectMissing(inspection)) {
    return {
      status: 'already_absent',
      absence_confirmed: true,
      asynchronous_removal: false,
      attempts: [],
    };
  }

  const removalAttempts = [];
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const removal = remove();
    removalAttempts.push({
      attempt,
      status: removal?.status ?? null,
      error: removal?.status === 0 ? null : resultSummary(removal),
    });

    inspection = inspect();
    if (dockerObjectMissing(inspection)) {
      const asynchronousRemoval = removalAttempts.some((entry) => entry.status !== 0);
      return {
        status: asynchronousRemoval ? 'removed_asynchronously' : 'removed',
        absence_confirmed: true,
        asynchronous_removal: asynchronousRemoval,
        attempts: removalAttempts,
      };
    }

    if (attempt < attempts && retryDelayMilliseconds > 0) {
      sleep(retryDelayMilliseconds);
    }
  }

  const removalErrors = removalAttempts
    .filter((entry) => entry.error !== null)
    .map((entry) => `attempt ${entry.attempt}: ${entry.error}`);
  const finalInspection = inspection?.status === 0
    ? `worker container ${containerName} still exists after ${attempts} bounded removal attempts`
    : `could not verify removal of worker container ${containerName}: ${resultSummary(inspection)}`;
  throw new Error([...removalErrors, finalInspection].join('; '));
}
