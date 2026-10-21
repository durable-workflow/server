<?php
// Seeded schedule specs with published PHP's next fire time
// (WorkflowSchedule::computeNextFireAt, no jitter). Usage: next-fire.php SEED COUNT
require '/app/vendor/autoload.php';
use Workflow\V2\Models\WorkflowSchedule;
mt_srand((int) $argv[1]);
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$n = fn (int $max) => (string) mt_rand(0, $max);
$duration = function () use ($pick, $n): string {
    if (mt_rand(0, 9) === 0) {
        return $pick(['P', 'PT', 'p1d', 'P1DT', 'PT1H1D', 'P2D1W', 'PT1.5S', 'P0001-00-02T00:00:00', 'P-1D', ' P1D', 'P1D ',
            'P0D', 'PT0S', 'P10000000000000000000D', 'P01D', 'PT0001H', 'P1W2D', 'P1H', '', 'P0000-00-00T00:00:01']);
    }
    $date = ''; $time = '';
    foreach (['Y' => 2, 'M' => 14, 'W' => 3, 'D' => 40] as $unit => $max) {
        if (mt_rand(0, 3) === 0) { $date .= $n($max).$unit; }
    }
    foreach (['H' => 50, 'M' => 90, 'S' => 7200] as $unit => $max) {
        if (mt_rand(0, 2) === 0) { $time .= $n($max).$unit; }
    }
    if ($date === '' && $time === '') { $time = (mt_rand(1, 3600)).'S'; }
    return 'P'.$date.($time === '' ? '' : 'T'.$time);
};
$crons = ['0 3 * * *', '*/15 * * * *', '30 2 * * SUN', '0 0 1 * *', '@hourly', '5 4 L * ?', '0 9 * * 1-5', '0 12 29 2 *', 'bad cron', ''];
$zones = ['UTC', 'Europe/Budapest', 'America/New_York', 'Australia/Lord_Howe', 'Asia/Kolkata', 'EST', '+05:30', 'Mars/Base', ''];
for ($i = 0; $i < (int) $argv[2]; ++$i) {
    $spec = [];
    if (mt_rand(0, 2) > 0) {
        $spec['cron_expressions'] = array_map(fn () => $pick($crons), range(1, mt_rand(1, 2)));
    }
    if (mt_rand(0, 2) > 0) {
        $spec['intervals'] = array_map(fn () => array_filter(['every' => $duration(),
            'offset' => mt_rand(0, 2) === 0 ? $duration() : null], fn ($v) => $v !== null), range(1, mt_rand(1, 2)));
    }
    if (mt_rand(0, 3) > 0) { $spec['timezone'] = $pick($zones); }
    $after = sprintf('%.6F', mt_rand(strtotime('2025-01-01'), strtotime('2028-12-31')) + mt_rand(0, 999999) / 1e6);
    $at = DateTimeImmutable::createFromFormat('U.u', $after)->format('Y-m-d\TH:i:s.u\Z');
    $case = ['spec' => $spec, 'after' => $at];
    try {
        $schedule = new WorkflowSchedule();
        $schedule->spec = $spec;
        $next = $schedule->computeNextFireAt(new Carbon\Carbon($at));
        $case['next'] = $next === null ? null : Carbon\Carbon::instance($next)->utc()->format('Y-m-d\TH:i:s.u\Z');
    } catch (Throwable $e) {
        $case['error'] = get_class($e);
    }
    echo json_encode($case, JSON_UNESCAPED_SLASHES), "\n";
}
