<?php

namespace App\Support;

/** Roll back preparation or replay when original authority cannot be retained. */
final class PreparedLocalActivityAdmissionRefused extends \RuntimeException
{
    public function __construct(public readonly ?array $reply = null)
    {
        parent::__construct('Original prepared authority could not be retained.');
    }
}
