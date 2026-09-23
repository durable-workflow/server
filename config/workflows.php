<?php

declare(strict_types=1);

use Workflow\Support\Env;

return [
    'v1' => [
        'enabled' => (bool) Env::dw('DW_V1_ENABLED', 'WORKFLOW_V1_ENABLED', false),
    ],
];
