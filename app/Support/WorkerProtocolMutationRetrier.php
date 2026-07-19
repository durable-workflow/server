<?php

namespace App\Support;

final class WorkerProtocolMutationRetrier
{
    public function __construct(
        private readonly ControlPlaneMutationRetrier $mutations,
    ) {}

    /**
     * Worker protocol writes share task and attempt fences across every
     * supported database backend. Retry only classified lock contention so a
     * transient MySQL/PostgreSQL conflict cannot terminate a managed worker.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $mutation
     * @return TResult
     */
    public function run(callable $mutation): mixed
    {
        return $this->mutations->run($mutation, allBackends: true);
    }
}
