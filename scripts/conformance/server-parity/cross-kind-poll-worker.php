<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
if (getenv('DW_PARITY_SDK_AUTOLOAD')) {
    require getenv('DW_PARITY_SDK_AUTOLOAD');
} else {
    capacityAutoload();
}
capacityConfiguredWorker()->run();
