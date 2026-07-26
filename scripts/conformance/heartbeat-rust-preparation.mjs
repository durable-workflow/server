export class RustCratesIoPreparationTimeoutError extends Error {
  constructor({
    phase,
    timeoutMilliseconds,
    elapsedMilliseconds,
    completedPhases,
    cause = null,
  }) {
    const detail = cause instanceof Error ? `: ${cause.message}` : '';
    super(
      `exact crates.io preparation exceeded its ${timeoutMilliseconds}ms budget `
        + `during ${phase}${detail}`,
      cause instanceof Error ? { cause } : undefined,
    );
    this.name = 'RustCratesIoPreparationTimeoutError';
    this.phase = phase;
    this.timeoutMilliseconds = timeoutMilliseconds;
    this.elapsedMilliseconds = elapsedMilliseconds;
    this.completedPhases = [...completedPhases];
    this.runnerBlocked = true;
  }
}

function commandTimedOut(error) {
  return error?.timedOut === true || error?.code === 'ETIMEDOUT';
}

export function prepareExactRustCrate({
  steps,
  execute,
  timeoutMilliseconds,
  clock = () => Date.now(),
}) {
  if (!Array.isArray(steps) || steps.length === 0) {
    throw new TypeError('exact crates.io preparation requires at least one step');
  }
  if (typeof execute !== 'function') {
    throw new TypeError('exact crates.io preparation requires an executor');
  }
  if (!Number.isInteger(timeoutMilliseconds) || timeoutMilliseconds <= 0) {
    throw new TypeError('exact crates.io preparation timeout must be a positive integer');
  }

  const startedAt = clock();
  const deadline = startedAt + timeoutMilliseconds;
  const completedPhases = [];
  const phaseEvidence = [];
  const results = {};

  for (const step of steps) {
    const phase = String(step?.phase ?? '');
    if (!phase || !Array.isArray(step?.cargoArguments) || step.cargoArguments.length === 0) {
      throw new TypeError('each exact crates.io preparation step requires a phase and Cargo arguments');
    }

    const phaseStartedAt = clock();
    const remainingMilliseconds = deadline - phaseStartedAt;
    if (remainingMilliseconds <= 0) {
      throw new RustCratesIoPreparationTimeoutError({
        phase,
        timeoutMilliseconds,
        elapsedMilliseconds: phaseStartedAt - startedAt,
        completedPhases,
      });
    }

    try {
      results[phase] = execute({
        ...step,
        timeoutMilliseconds: remainingMilliseconds,
      });
    } catch (error) {
      const elapsedMilliseconds = clock() - startedAt;
      if (commandTimedOut(error) || elapsedMilliseconds >= timeoutMilliseconds) {
        throw new RustCratesIoPreparationTimeoutError({
          phase,
          timeoutMilliseconds,
          elapsedMilliseconds,
          completedPhases,
          cause: error,
        });
      }
      throw error;
    }

    const phaseFinishedAt = clock();
    if (phaseFinishedAt > deadline) {
      throw new RustCratesIoPreparationTimeoutError({
        phase,
        timeoutMilliseconds,
        elapsedMilliseconds: phaseFinishedAt - startedAt,
        completedPhases,
      });
    }
    completedPhases.push(phase);
    phaseEvidence.push({
      phase,
      cargo_arguments: [...step.cargoArguments],
      network_access: step.networkAccess === true,
      elapsed_ms: Math.max(0, phaseFinishedAt - phaseStartedAt),
    });
  }

  return {
    results,
    evidence: {
      source: 'crates.io',
      status: 'pass',
      timeout_ms: timeoutMilliseconds,
      elapsed_ms: Math.max(0, clock() - startedAt),
      completed_phases: completedPhases,
      phases: phaseEvidence,
      network_access_completed_before_offline_metadata_and_build: true,
    },
  };
}
