<?php

declare(strict_types=1);

namespace UpdateRequestKeyConsumer;

require_once '/app/vendor/autoload.php';

use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;
use function Workflow\V2\signal;

#[Signal('finish')]
final class WaitingWorkflow extends Workflow
{
    private int $count = 0;

    public function handle(): array
    {
        signal('finish');

        return $this->result();
    }

    #[UpdateMethod('add')]
    public function add(int $amount): array
    {
        $this->count += $amount;

        return $this->result();
    }

    #[UpdateMethod('other')]
    public function other(int $amount): array
    {
        return $this->add($amount);
    }

    private function result(): array
    {
        return ['count' => $this->count, 'typed' => [PHP_INT_MAX, null, 'Київ']];
    }
}
