<?php

namespace App\Support;

use RuntimeException;

final class TaskDeliveryOfferRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The worker task offer could not be accepted.');
    }
}
