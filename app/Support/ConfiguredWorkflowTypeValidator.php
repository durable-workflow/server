<?php

namespace App\Support;

use LogicException;
use Workflow\V2\Workflow;

final class ConfiguredWorkflowTypeValidator
{
    /**
     * @throws LogicException
     */
    public function assertLoadable(string $workflowType): void
    {
        $message = $this->validationMessage($workflowType);

        if ($message !== null) {
            throw new LogicException($message);
        }
    }

    public function validationMessage(string $workflowType): ?string
    {
        if (class_exists($workflowType) && is_subclass_of($workflowType, Workflow::class)) {
            return null;
        }

        $configured = config('workflows.v2.types.workflows', []);

        if (! is_array($configured) || ! array_key_exists($workflowType, $configured)) {
            return null;
        }

        $workflowClass = $configured[$workflowType];

        if (! is_string($workflowClass) || ! class_exists($workflowClass) || ! is_subclass_of($workflowClass, Workflow::class)) {
            return sprintf(
                'Configured durable workflow type [%s] points to [%s], which is not a loadable workflow class.',
                $workflowType,
                is_scalar($workflowClass) ? (string) $workflowClass : get_debug_type($workflowClass),
            );
        }

        return null;
    }
}
