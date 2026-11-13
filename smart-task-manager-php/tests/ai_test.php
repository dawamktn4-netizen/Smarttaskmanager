<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/ai.php';
date_default_timezone_set('Europe/Copenhagen');
$checks = 0;
$failures = [];
function check(bool $condition, string $name): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $name;
}
function same($actual, $expected, string $name): void
{
    check($actual === $expected, $name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}
function task(array $changes = []): array
{
    return array_merge(['task_id' => 1, 'title' => 'Write a chapter', 'importance' => 'MEDIUM', 'status' => 'PENDING',
        'due_at' => null, 'category_id' => 1, 'postpone_count' => 0], $changes);
}
$now = new DateTimeImmutable('2026-09-22 10:00:00', new DateTimeZone('Europe/Copenhagen'));
$profile = ['timezone' => 'Europe/Copenhagen', 'quiet_start' => '22:00', 'quiet_end' => '08:00',
    'preferred_hour' => 9, 'notifications_enabled' => true];

same(ai_evaluate(task(), [], $now)['score'], 22.0, 'No deadline has no urgency points');
same(ai_evaluate(task(['importance' => 'LOW']), [], $now)['score'], 10.0, 'Low importance');
same(ai_evaluate(task(['importance' => 'HIGH']), [], $now)['score'], 35.0, 'High importance');
same(ai_evaluate(task(['due_at' => 'invalid']), [], $now)['score'], 22.0, 'Invalid date is ignored');
same(ai_date('2026-02-30 12:00:00', $now->getTimezone()), null, 'Impossible date is rejected');
same(ai_date('2026-09-22T10:30', $now->getTimezone())->format('H:i:s'), '10:30:00', 'Form datetime supported');
foreach ([['-1 second', 67.0], ['+0 seconds', 62.0], ['+6 hours', 62.0], ['+6 hours +1 second', 57.0],
    ['+24 hours', 57.0], ['+24 hours +1 second', 47.0], ['+72 hours', 47.0], ['+72 hours +1 second', 37.0],
    ['+168 hours', 37.0], ['+168 hours +1 second', 27.0]] as [$offset, $expected]) {
    same(ai_evaluate(task(['due_at' => $now->modify($offset)->format('Y-m-d H:i:s')]), [], $now)['score'], $expected, 'Urgency boundary ' . $offset);
}
same(ai_evaluate(task(['status' => 'IN_PROGRESS']), [], $now)['score'], 27.0, 'In-progress boost');
same(ai_evaluate(task(['postpone_count' => -1]), [], $now)['score'], 22.0, 'Negative postponements are ignored');
same(ai_evaluate(task(['postpone_count' => 100]), [], $now)['score'], 37.0, 'Postponement contribution capped');
$full = ai_evaluate(task(['importance' => 'HIGH', 'due_at' => '2026-09-21 10:00:00', 'status' => 'IN_PROGRESS', 'postpone_count' => 3]),
    ['category_completion_counts' => [1 => 3], 'category_delay_rates' => [1 => 1]], $now);
same($full['score'], 100.0, 'Total capped at 100');
same((float)array_sum(array_column($full['factors'], 'points')), $full['score'], 'Visible factors reconcile after cap');
same($full['level'], 'CRITICAL', 'Critical level');
foreach ([39 => 'LOW', 40 => 'MEDIUM', 64 => 'MEDIUM', 65 => 'HIGH', 84 => 'HIGH', 85 => 'CRITICAL'] as $score => $level) same(ai_level((float)$score), $level, 'Level boundary ' . $score);
foreach (['COMPLETED', 'CANCELLED'] as $status) {
    same(ai_evaluate(task(['status' => $status, 'importance' => 'HIGH', 'due_at' => '2020-01-01']), [], $now)['score'], 0.0, 'Inactive task ' . $status);
    same(ai_suggest_reminder(task(['status' => $status]), $profile, $now), null, 'No reminder for ' . $status);
}
$low = ai_evaluate(task(['importance' => 'LOW', 'due_at' => '2026-09-24 10:00:00']), [], $now);
$high = ai_evaluate(task(['importance' => 'HIGH', 'due_at' => '2026-09-24 10:00:00']), [], $now);
check($high['score'] > $low['score'], 'Higher importance cannot lower score');
$sooner = ai_evaluate(task(['due_at' => '2026-09-22 11:00:00']), [], $now);
$later = ai_evaluate(task(['due_at' => '2026-09-30 11:00:00']), [], $now);
check($sooner['score'] > $later['score'], 'Nearer deadline increases urgency');
$ranked = ai_rank_tasks([task(['task_id' => 3, 'due_at' => '2026-09-30 10:00:00']), task(['task_id' => 2, 'due_at' => '2026-09-29 10:00:00']), task(['task_id' => 1, 'due_at' => '2026-09-29 10:00:00'])], [], $now);
same(array_column($ranked, 'task_id'), [1, 2, 3], 'Ties use due date, then task ID');
check(isset($ranked[0]['_ai']['factors']), 'Ranked tasks include explanation');
check(!isset($ranked[0]['_ai_input_order']), 'Private ordering field removed');
same(ai_rank_tasks([], [], $now), [], 'Empty ranking is valid');

$records = [
    task(['completed_at' => '2026-09-20 16:15:00', 'status' => 'COMPLETED', 'due_at' => '2026-09-20 15:00:00']),
    task(['completed_at' => '2026-09-21 16:30:00', 'status' => 'COMPLETED', 'postpone_count' => 1]),
    task(['completed_at' => '2026-09-21 09:00:00', 'status' => 'COMPLETED'])
];
$cold = ai_build_profile(array_slice($records, 0, 2), $profile, $now);
same($cold['behavior_source'], 'preference', 'Cold start is explicit');
same($cold['preferred_hour'], 9, 'Cold start respects preference');
$learned = ai_build_profile($records, $profile, $now);
same($learned['behavior_source'], 'history', 'Three completions enable adaptation');
same($learned['preferred_hour'], 16, 'Historical modal completion hour selected');
same($learned['completion_count'], 3, 'Sample count exposed');
same(ai_evaluate(task(), $learned, $now)['score'], 25.0, 'Two delayed of three completions add 3 points');
same(ai_evaluate(task(['category_id' => 2]), $learned, $now)['score'], 22.0, 'Behavior is category-specific');
same(ai_evaluate(task(), $cold, $now)['score'], 22.0, 'Category cold start adds no behavior points');
$filtered = ai_build_profile(array_merge($records, [
    task(['completed_at' => '2026-01-01 16:00:00', 'status' => 'COMPLETED']),
    task(['completed_at' => '2026-09-23 16:00:00', 'status' => 'COMPLETED']),
    task(['completed_at' => 'not a date', 'status' => 'COMPLETED']),
    task(['completed_at' => '2026-09-21 16:00:00', 'status' => 'PENDING'])
]), $profile, $now);
same($filtered['completion_count'], 3, 'Old, future, invalid and incomplete records excluded');
same(ai_build_profile(array_fill(0, 250, $records[0]), $profile, $now)['completion_count'], 200, 'History bounded to 200 records');
$tieRows = [task(['completed_at' => '2026-09-20 08:00:00', 'status' => 'COMPLETED']), task(['completed_at' => '2026-09-20 10:00:00', 'status' => 'COMPLETED']), task(['completed_at' => '2026-09-20 18:00:00', 'status' => 'COMPLETED'])];
same(ai_build_profile($tieRows, $profile, $now)['preferred_hour'], 8, 'Modal tie uses closest preference then earlier hour');

same(ai_suggest_reminder(task(), array_merge($profile, ['notifications_enabled' => false]), $now), null, 'Disabled notifications suppress suggestions');
$suggestion = ai_suggest_reminder(task(['due_at' => '2026-09-25 18:00:00', 'importance' => 'HIGH']), $learned, $now);
same($suggestion['reminder_at'], '2026-09-24 16:00:00', 'Reminder adapts to learned hour');
check(strpos($suggestion['reason'], '3 recent completions') !== false, 'Reminder explains sample size');
$noDeadline = ai_suggest_reminder(task(), $profile, $now);
same($noDeadline['reminder_at'], '2026-09-22 10:15:00', 'Past preferred hour falls back to future time');
$night = new DateTimeImmutable('2026-09-22 23:00:00', $now->getTimezone());
same(ai_suggest_reminder(task(), $profile, $night)['reminder_at'], '2026-09-23 08:00:00', 'Overnight quiet hours shift to next morning');
$early = new DateTimeImmutable('2026-09-22 05:00:00', $now->getTimezone());
same(ai_suggest_reminder(task(), array_merge($profile, ['preferred_hour' => 6]), $early)['reminder_at'], '2026-09-22 08:00:00', 'Early morning quiet hours shift to same morning');
same(ai_after_quiet_hours($now, ['quiet_start' => '09:00', 'quiet_end' => '17:00'])->format('H:i'), '17:00', 'Same-day quiet interval supported');
same(ai_after_quiet_hours($night, ['quiet_start' => '00:00', 'quiet_end' => '00:00']), $night, 'Equal quiet endpoints disable quiet hours');
same(ai_suggest_reminder(task(['due_at' => '2026-09-22 10:10:00']), $profile, $now), null, 'No 15-minute lead before very close deadline');
same(ai_suggest_reminder(task(['due_at' => '2026-09-23 07:00:00']), $profile, $night), null, 'No suggestion inside quiet hours to meet deadline');
$overdue = ai_suggest_reminder(task(['due_at' => '2026-09-20 09:00:00']), $profile, $now);
check($overdue !== null && strpos($overdue['reason'], 'deadline has passed') !== false, 'Overdue tasks get explicit follow-up explanation');
$tomorrowMorning = ai_suggest_reminder(task(['due_at' => '2026-09-23 09:00:00', 'importance' => 'LOW']), $profile, $now);
same($tomorrowMorning['reminder_at'], '2026-09-23 08:00:00', 'Preferred hour at deadline falls back to lead time and quiet end');
$utc = new DateTimeImmutable('2026-09-22 08:00:00', new DateTimeZone('UTC'));
same(ai_evaluate(task(['due_at' => '2026-09-22 10:30:00']), $profile, $utc)['score'], 62.0, 'Account timezone applied to an injected clock');
same(ai_suggest_reminder(task(), $profile, $utc)['reminder_at'], '2026-09-22 10:15:00', 'Reminder output is account-local time');
$dst = new DateTimeImmutable('2026-10-24 23:00:00', new DateTimeZone('Europe/Copenhagen'));
same(ai_suggest_reminder(task(), $profile, $dst)['reminder_at'], '2026-10-25 08:00:00', 'Quiet hours respect local time across DST change');

if ($failures) {
    fwrite(STDERR, count($failures) . " failed of {$checks} checks:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: {$checks} deterministic AI checks. No database or external service required.\n";
