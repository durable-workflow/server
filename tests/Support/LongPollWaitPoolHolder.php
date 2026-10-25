<?php

use App\Support\LongPollWaitPool;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$slot = (new LongPollWaitPool)->tryAcquire($argv[1], 2, $argv[2], 1, 30);

if ($slot === null) {
    fwrite(STDERR, "refused\n");
    exit(1);
}

echo "held\n";
flush();
fgets(STDIN);
$slot->release();
