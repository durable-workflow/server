<?php

namespace App\Support;

use RuntimeException;

final class CachedPollTaskKindConflict extends RuntimeException
{
    public const STATE_LEGACY_MISSING_DISCRIMINATOR = 'legacy_missing_discriminator';

    public const STATE_UNREQUESTED_DISCRIMINATOR = 'unrequested_discriminator';

    public readonly string $cachedTaskKindState;

    /**
     * @param  list<string>  $requestedTaskKinds
     */
    public function __construct(
        public readonly string $pollRequestId,
        public readonly array $requestedTaskKinds,
        public readonly ?string $cachedTaskKind,
    ) {
        $this->cachedTaskKindState = $cachedTaskKind === null
            ? self::STATE_LEGACY_MISSING_DISCRIMINATOR
            : self::STATE_UNREQUESTED_DISCRIMINATOR;

        parent::__construct($cachedTaskKind === null
            ? 'Cached poll result has no task-kind discriminator and cannot be replayed safely.'
            : 'Cached poll result has an unrequested task-kind discriminator and cannot be replayed safely.');
    }
}
