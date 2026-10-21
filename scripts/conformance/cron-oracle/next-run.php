<?php
// Seeded cron corpus with PHP's published answers. Usage: next-run.php SEED COUNT
// Each line: {"cron","after","tz","next"|"error"}. Times cluster around the
// zones' daylight-saving transitions, month ends and leap days.
require '/app/vendor/autoload.php';
mt_srand((int) $argv[1]);
$count = (int) $argv[2];
$zones = ['UTC', 'America/New_York', 'Europe/London', 'Europe/Budapest', 'Australia/Lord_Howe',
    'Asia/Kolkata', 'Pacific/Chatham', 'America/Sao_Paulo', 'America/St_Johns', 'Asia/Tehran',
    'America/Havana', 'Asia/Beirut', 'Pacific/Apia', 'America/Santiago', 'Antarctica/Troll', 'Europe/Dublin'];
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$field = function (int $min, int $max, array $names = [], bool $dow = false, bool $dom = false) use ($pick) {
    $n = fn () => (string) mt_rand($min, $max);
    $name = fn () => $names ? $pick($names) : $n();
    $one = function () use ($pick, $n, $name, $min, $max, $dow, $dom) {
        switch (mt_rand(0, $dow || $dom ? 9 : 6)) {
            case 0: return '*';
            case 1: return $n();
            case 2: $a = mt_rand($min, $max); $b = mt_rand($a, $max); return "$a-$b";
            case 3: return '*/' . mt_rand(1, $max + 2);
            case 4: $a = mt_rand($min, $max); $b = mt_rand($a, $max); return "$a-$b/" . mt_rand(1, 7);
            case 5: return $name();
            case 6: return $n() . ',' . $n();
            case 7: return $dow ? mt_rand(0, 7) . 'L' : 'L';
            case 8: return $dow ? mt_rand(0, 7) . '#' . mt_rand(1, 5) : mt_rand(1, 31) . 'W';
            default: return '?';
        }
    };
    return $one();
};
$months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
$days = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
$aliases = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly', '@DAILY'];
for ($i = 0; $i < $count; ++$i) {
    $tz = $pick($zones);
    if (mt_rand(0, 19) === 0) {
        $cron = $pick($aliases);
    } else {
        $cron = implode(' ', [$field(0, 59), $field(0, 23), $field(1, 31, [], false, true),
            $field(1, 12, $months), $field(0, 7, $days, true)]);
    }
    // Instants near a transition of this zone, a month end or a leap day.
    $zone = new DateTimeZone($tz);
    $transitions = $zone->getTransitions(strtotime('2024-01-01'), strtotime('2029-01-01'));
    $choice = mt_rand(0, 3);
    if ($choice < 2 && count($transitions) > 1) {
        $base = $pick(array_slice($transitions, 1))['ts'];
    } elseif ($choice === 2) {
        $base = strtotime($pick(['2028-02-28', '2028-02-29', '2027-02-28', '2026-12-31', '2026-04-30', '2026-01-31']) . ' 23:00:00 UTC');
    } else {
        $base = mt_rand(strtotime('2025-01-01'), strtotime('2028-12-31'));
    }
    $after = $base + mt_rand(-7200, 7200) + mt_rand(0, 1) * mt_rand(0, 59) + (mt_rand(0, 3) === 0 ? 0.5 : 0);
    $at = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $after));
    $case = ['cron' => $cron, 'after' => $at->format('Y-m-d\TH:i:s.u\Z'), 'tz' => $tz];
    try {
        $next = (new Cron\CronExpression($cron))->getNextRunDate($at, 0, false, $tz);
        $case['next'] = $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    } catch (Throwable $e) {
        $case['error'] = get_class($e);
    }
    echo json_encode($case, JSON_UNESCAPED_SLASHES), "\n";
}
