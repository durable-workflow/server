<?php
declare(strict_types=1);
$helpers = [];
foreach (glob('/proc/[0-9]*/cmdline') as $path) {
    $pid = (int) basename(dirname($path));
    $command = @file_get_contents($path);
    if ($pid !== getmypid() && is_string($command)
        && str_contains($command, '/Worker/activity-lease-maintainer.php')) {
        $helpers[] = $pid;
    }
}
echo json_encode(['helpers' => $helpers, 'count' => count($helpers)])."\n";
