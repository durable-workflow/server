<?php
// Cron answers across zone spellings: every identifier PHP lists (with
// backward-compatible links), case variants, every abbreviation, numeric
// offsets and refused strings. Lines match the main corpus format.
require '/app/vendor/autoload.php';
$skip = array_filter(explode(',', getenv('SKIP_ZONES') ?: ''));
$names = [];
foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $i => $id) {
    if (in_array($id, $skip, true)) { continue; }
    $names[] = $id;
    if ($i % 7 === 0) { $names[] = strtolower($id); }
    if ($i % 11 === 0) { $names[] = strtoupper($id); }
}
foreach (array_keys(DateTimeZone::listAbbreviations()) as $abbr) { $names[] = $abbr; $names[] = strtoupper($abbr); }
foreach (['+5', '+05', '-12', '+0530', '-0330', '+05:30', '+5:30', '+5:3', '+053000', '-05:30:15', '+25', '+100',
    'GMT+1', 'GMT-3', 'GMT+05:30', 'UTC+3', 'Z', 'z', 'UTC', 'utc', 'Utc', 'GMT', ' UTC', '(UTC)', 'UTC)', '(Europe/Budapest)',
    '  Europe/Budapest', 'Europe/Budapest ', '', ' ', '+', '-', '+5x', 'Mars/Base', 'Europe', 'UTC+0', 'GMT0', 'Etc/GMT-14', '((UTC))', '(UTC))', '(UTC', 'EST)', '(EST)', '(+05:00)', '+05:00)'] as $extra) {
    $names[] = $extra;
}
$cases = [['0 12 * * *', '2026-07-01T10:17:00.000000Z'], ['30 2 * * *', '2026-03-08T05:00:00.000000Z'],
    ['15 1 * * *', '2026-10-25T00:20:00.000000Z']];
foreach ($names as $tz) {
    $results = [];
    foreach ($cases as [$cron, $after]) {
        try {
            $next = (new Cron\CronExpression($cron))->getNextRunDate(new DateTimeImmutable($after), 0, false, $tz);
            $results[] = $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i\Z');
        } catch (Throwable $e) {
            $results[] = get_class($e);
        }
    }
    echo json_encode([$tz, ...$results], JSON_UNESCAPED_SLASHES), "\n";
}
