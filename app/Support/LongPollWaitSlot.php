<?php

namespace App\Support;

final class LongPollWaitSlot
{
    private function __construct(
        private mixed $handle,
        private readonly int $processId,
    ) {}

    public static function unlimited(): self
    {
        return new self(null, getmypid());
    }

    /** @param resource $handle */
    public static function acquired(mixed $handle): self
    {
        return new self($handle, getmypid());
    }

    public function release(): void
    {
        $handle = $this->handle;
        $this->handle = null;

        if (! is_resource($handle)) {
            return;
        }

        // A fork may inherit the descriptor. Its cleanup must not unlock the
        // parent's open-file description while the parent still owns a wait.
        if (getmypid() === $this->processId) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
