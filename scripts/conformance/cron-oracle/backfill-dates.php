<?php
// Seeded backfill time strings with published PHP's answers: Laravel's
// `date` rule (validateDate, as in the frozen image's Laravel) and the
// instant `new DateTimeImmutable($value)` reads under the server's UTC
// default. `iso` marks the ISO-8601 shapes native must accept.
// Usage: backfill-dates.php SEED COUNT
date_default_timezone_set('UTC');
mt_srand((int) $argv[1]);
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$pad = fn (int $v, int $w = 2) => str_pad((string) $v, $w, '0', STR_PAD_LEFT);
$valid = function ($value): bool {
    try {
        if ((! is_string($value) && ! is_numeric($value)) || strtotime($value) === false) {
            return false;
        }
    } catch (Exception) {
        return false;
    }
    $date = date_parse($value);

    return checkdate($date['month'], $date['day'], $date['year']);
};
$iso = function () use ($pick, $pad): string {
    $year = mt_rand(0, 5) === 0 ? $pick([1970, 1999, 2000, 2024, 2100, 2400]) : mt_rand(1971, 2099);
    $month = mt_rand(0, 9) === 0 ? mt_rand(0, 13) : mt_rand(1, 12);
    $day = mt_rand(0, 4) === 0 ? $pick([0, 28, 29, 30, 31, 32]) : mt_rand(1, 28);
    $text = $year.'-'.$pad($month).'-'.$pad($day);
    if (mt_rand(0, 5) === 0) {
        return $text;
    }
    $hour = mt_rand(0, 9) === 0 ? $pick([0, 23, 24, 25]) : mt_rand(0, 23);
    $minute = mt_rand(0, 9) === 0 ? $pick([0, 59, 60]) : mt_rand(0, 59);
    $text .= $pick(['T', 'T', 'T', 't', ' ']).$pad($hour).':'.$pad($minute);
    if (mt_rand(0, 4) > 0) {
        $second = mt_rand(0, 9) === 0 ? $pick([0, 59, 60, 61]) : mt_rand(0, 59);
        $text .= ':'.$pad($second);
        if (mt_rand(0, 2) === 0) {
            $text .= '.'.substr((string) mt_rand(100000000, 999999999), 0, mt_rand(1, 9));
        }
    }
    $zone = mt_rand(0, 9);
    if ($zone < 3) {
        return $text;
    }
    if ($zone < 6) {
        return $text.$pick(['Z', 'Z', 'z']);
    }
    $sign = $pick(['+', '-']);
    $hours = mt_rand(0, 9) === 0 ? $pick([0, 14, 15, 23, 24, 25]) : mt_rand(0, 12);
    $minutes = mt_rand(0, 9) === 0 ? $pick([0, 45, 59, 60]) : $pick([0, 0, 30, 45]);
    return $text.($zone === 9 ? ' ' : '').$sign.$pad($hours).$pick([':'.$pad($minutes), $pad($minutes), '']);
};
$others = ['now', 'yesterday', 'tomorrow 05:00', 'next monday', '+1 day', '2026-04-01 +1 day', '@1700000000',
    '@1700000000.5', '1700000000', '20260401', '20260401T050000Z', '2026-4-1', '2026-04-1T5:00', '2026/04/01',
    '04/01/2026', '01.04.2026', '1 April 2026', 'April 1, 2026', 'Wed, 01 Apr 2026 05:00:00 GMT',
    '2026-04-01T05:00:00 UTC', '2026-04-01T05:00:00 Europe/Budapest', '2026-04-01T05:00:00+05:30:00',
    '2026-04-01T05:00:00.000000Z', '2026-04-01T05', '2026-04-01T', '2026-W14-3', '2026-091', '-2026-04-01',
    '12026-04-01', '0000-00-00', '0001-01-01', '9999-12-31T23:59:59Z', 'garbage', 'T05:00:00', '05:00',
    '2026-04-01T05:00:00ZZ', '2026-04-01T05:00:00+2', '2026-04-01T05:00:00-0', '2026-04-01  05:00:00',
    '2026-04-01T05:00:00.Z', '2026-04-01T05:00:00,5Z', '2026-02-29', '2028-02-29T00:00:00Z', '2100-02-29',
    '2000-02-29', '2026-04-01T05:00:00 +02:00', '2026-04-01T05:00:00+02:00 ', ' 2026-04-01', '2026-04-01T05:00:00GMT+2'];
for ($i = 0; $i < (int) $argv[2]; ++$i) {
    $family = $i < count($others) ? 'other' : 'iso';
    $value = $family === 'other' ? $others[$i] : $iso();
    $case = ['value' => $value, 'iso' => $family === 'iso', 'valid' => $valid($value)];
    if ($case['valid']) {
        try {
            $at = new DateTimeImmutable($value);
            $case['at'] = $at->format('U.u');
        } catch (Throwable $e) {
            $case['error'] = get_class($e);
        }
    }
    echo json_encode($case, JSON_UNESCAPED_SLASHES), "\n";
}
