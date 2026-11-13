<?php
declare(strict_types=1);

/**
 * Transparent, local expert-system recommendations. No model or remote API.
 * Datetimes use the account's local timezone, matching the application's schema.
 */
function ai_clock(array $profile = [], ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now = $now ?? new DateTimeImmutable('now');
    try {
        return isset($profile['timezone']) ? $now->setTimezone(new DateTimeZone((string)$profile['timezone'])) : $now;
    } catch (Exception $e) {
        return $now;
    }
}

function ai_date(?string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') return null;
    foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\\TH:i', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))) return $date;
    }
    return null;
}

function ai_level(float $score): string
{
    return $score >= 85 ? 'CRITICAL' : ($score >= 65 ? 'HIGH' : ($score >= 40 ? 'MEDIUM' : 'LOW'));
}

/** Pure scoring function; explicit $now makes boundary tests reproducible. */
function ai_evaluate(array $task, array $profile = [], ?DateTimeImmutable $now = null): array
{
    $now = ai_clock($profile, $now);
    $status = strtoupper((string)($task['status'] ?? 'PENDING'));
    if (!in_array($status, ['PENDING', 'IN_PROGRESS'], true)) {
        return ['score' => 0.0, 'level' => 'LOW', 'reason' => 'This task is inactive and is excluded from focus recommendations.',
            'factors' => [['label' => 'Task status', 'points' => 0, 'detail' => 'Completed or cancelled tasks do not need a priority recommendation.']]];
    }

    $importance = strtoupper((string)($task['importance'] ?? 'MEDIUM'));
    $importancePoints = ['LOW' => 10, 'MEDIUM' => 22, 'HIGH' => 35][$importance] ?? 22;
    $factors = [['label' => 'Your importance', 'points' => $importancePoints,
        'detail' => ucfirst(strtolower(in_array($importance, ['LOW', 'MEDIUM', 'HIGH'], true) ? $importance : 'MEDIUM')) . ' importance chosen by you.']];
    $due = ai_date(isset($task['due_at']) ? (string)$task['due_at'] : null, $now->getTimezone());
    $urgency = 0;
    $deadlineDetail = empty($task['due_at']) ? 'No deadline set; no urgency points added.' : 'The deadline could not be read; no urgency points added.';
    if ($due) {
        // These fixed urgency bands give nearer deadlines more points; overdue tasks get the largest boost.
        $hours = ($due->getTimestamp() - $now->getTimestamp()) / 3600;
        if ($hours < 0) { $urgency = 45; $deadlineDetail = 'The deadline has passed.'; }
        elseif ($hours <= 6) { $urgency = 40; $deadlineDetail = 'Due within the next 6 hours.'; }
        elseif ($hours <= 24) { $urgency = 35; $deadlineDetail = 'Due within the next 24 hours.'; }
        elseif ($hours <= 72) { $urgency = 25; $deadlineDetail = 'Due within the next 3 days.'; }
        elseif ($hours <= 168) { $urgency = 15; $deadlineDetail = 'Due within the next 7 days.'; }
        else { $urgency = 5; $deadlineDetail = 'The deadline is more than 7 days away.'; }
    }
    $factors[] = ['label' => 'Deadline urgency', 'points' => $urgency, 'detail' => $deadlineDetail];

    $postponements = max(0, (int)($task['postpone_count'] ?? 0));
    $factors[] = ['label' => 'Postponement pattern', 'points' => min(15, 5 * $postponements),
        'detail' => $postponements ? "Postponed {$postponements} time(s); adds 5 points each, capped at 15." : 'No postponements recorded for this task.'];
    $factors[] = ['label' => 'Work in progress', 'points' => $status === 'IN_PROGRESS' ? 5 : 0,
        'detail' => $status === 'IN_PROGRESS' ? 'A small boost helps you finish work already started.' : 'This task has not been started.'];

    $category = (string)($task['category_id'] ?? 'none');
    $sample = (int)($profile['category_completion_counts'][$category] ?? 0);
    $rate = max(0.0, min(1.0, (float)($profile['category_delay_rates'][$category] ?? 0)));
    // Require 3 category completions before adapting; the 5-point limit keeps history from dominating the user's choices.
    $behaviorPoints = $sample >= 3 ? (int)round(5 * $rate) : 0;
    $factors[] = ['label' => 'Recent completion pattern', 'points' => $behaviorPoints,
        'detail' => $sample >= 3
            ? round($rate * 100) . "% of {$sample} recent completions in this category were late or postponed. This adds at most 5 points."
            : 'At least 3 completed tasks in this category are needed; no behavior points added.'];

    // Add the rule weights into one priority score. Record any cap adjustment so the displayed factors still total the score.
    $rawScore = (float)array_sum(array_column($factors, 'points'));
    if ($rawScore > 100) $factors[] = ['label' => 'Score cap', 'points' => 100 - $rawScore, 'detail' => 'The displayed score is limited to 100.'];
    $score = min(100.0, $rawScore);
    $reason = $factors[0]['detail'] . ' ' . $deadlineDetail;
    if ($postponements) $reason .= " Postponed {$postponements} time(s).";
    if ($status === 'IN_PROGRESS') $reason .= ' Already in progress.';
    if ($behaviorPoints) $reason .= ' Recent category delays add a small boost.';
    return ['score' => $score, 'level' => ai_level($score), 'reason' => $reason, 'factors' => $factors];
}

/** Pure aggregation of recent completed-task observations. */
function ai_build_profile(array $completedTasks, array $preferences = [], ?DateTimeImmutable $now = null): array
{
    $profile = array_merge(['quiet_start' => '22:00', 'quiet_end' => '08:00', 'preferred_hour' => 9,
        'timezone' => date_default_timezone_get(), 'notifications_enabled' => true], $preferences);
    $now = ai_clock($profile, $now);
    // Use only the latest 200 valid completions within 90 days, so old habits do not drive today's recommendations.
    $cutoff = $now->modify('-90 days');
    $configuredHour = max(0, min(23, (int)$profile['preferred_hour']));
    $histogram = array_fill(0, 24, 0);
    $counts = [];
    $delays = [];
    $observations = [];
    foreach ($completedTasks as $task) {
        if (($task['status'] ?? 'COMPLETED') !== 'COMPLETED') continue;
        $completed = ai_date(isset($task['completed_at']) ? (string)$task['completed_at'] : null, $now->getTimezone());
        if (!$completed || $completed < $cutoff || $completed > $now) continue;
        $observations[] = ['task' => $task, 'completed' => $completed];
    }
    usort($observations, static function (array $a, array $b): int { return $b['completed'] <=> $a['completed']; });
    foreach (array_slice($observations, 0, 200) as $observation) {
        $task = $observation['task'];
        $completed = $observation['completed'];
        $histogram[(int)$completed->format('G')]++;
        $category = (string)($task['category_id'] ?? 'none');
        $counts[$category] = ($counts[$category] ?? 0) + 1;
        $due = ai_date(isset($task['due_at']) ? (string)$task['due_at'] : null, $now->getTimezone());
        $delayed = (int)($task['postpone_count'] ?? 0) > 0 || ($due && $completed > $due);
        $delays[$category] = ($delays[$category] ?? 0) + ($delayed ? 1 : 0);
    }
    $count = array_sum($histogram);
    $preferred = $configuredHour;
    $source = 'preference';
    // With fewer than 3 completions, keep the user's setting. Tied popular hours favour the nearest setting, then the earlier hour.
    if ($count >= 3) {
        $hours = array_keys($histogram, max($histogram), true);
        usort($hours, static function (int $a, int $b) use ($configuredHour): int {
            $distanceA = min(abs($a - $configuredHour), 24 - abs($a - $configuredHour));
            $distanceB = min(abs($b - $configuredHour), 24 - abs($b - $configuredHour));
            return ($distanceA <=> $distanceB) ?: ($a <=> $b);
        });
        $preferred = $hours[0];
        $source = 'history';
    }
    $rates = [];
    foreach ($counts as $category => $sample) $rates[$category] = $delays[$category] / $sample;
    return array_merge($profile, ['configured_preferred_hour' => $configuredHour, 'preferred_hour' => $preferred,
        'completion_count' => $count, 'behavior_source' => $source, 'completion_hour_histogram' => $histogram,
        'category_completion_counts' => $counts, 'category_delay_rates' => $rates,
        'behavior_summary' => $source === 'history'
            ? sprintf('Your most common completion hour is %02d:00, based on %d recent completed tasks.', $preferred, $count)
            : sprintf('Using your preferred hour, %02d:00. Complete at least 3 tasks to enable history-based timing.', $preferred)]);
}

function ai_profile(PDO $pdo, int $uid): array
{
    $preferences = function_exists('user_preferences') ? user_preferences($pdo, $uid) : [];
    $now = ai_clock($preferences);
    $query = $pdo->prepare("SELECT task_id, category_id, status, completed_at, due_at, postpone_count FROM tasks WHERE user_id = ? AND status = 'COMPLETED' AND completed_at >= ? AND completed_at <= ? ORDER BY completed_at DESC LIMIT 200");
    $query->execute([$uid, $now->modify('-90 days')->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);
    return ai_build_profile($query->fetchAll(PDO::FETCH_ASSOC), $preferences, $now);
}

/** Refresh deadline-sensitive fields without adding noisy activity/history rows. */
function ai_refresh(PDO $pdo, int $uid): void
{
    $profile = ai_profile($pdo, $uid);
    $now = ai_clock($profile);
    $query = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND status IN ('PENDING', 'IN_PROGRESS')");
    $query->execute([$uid]);
    $update = $pdo->prepare("UPDATE tasks SET ai_priority_score = ?, ai_priority_level = ?, ai_reason = ?, ai_last_calculated_at = ?, updated_at = updated_at WHERE task_id = ? AND user_id = ? AND status IN ('PENDING', 'IN_PROGRESS')");
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $recommendation = ai_evaluate($task, $profile, $now);
        $update->execute([$recommendation['score'], $recommendation['level'], $recommendation['reason'], $now->format('Y-m-d H:i:s'), (int)$task['task_id'], $uid]);
    }
}

function ai_rank_tasks(array $tasks, array $profile = [], ?DateTimeImmutable $now = null): array
{
    $now = ai_clock($profile, $now);
    foreach ($tasks as $index => &$task) {
        $task['_ai'] = ai_evaluate($task, $profile, $now);
        $task['_ai_input_order'] = $index;
    }
    unset($task);
    // Rank by highest score, then earliest deadline (missing dates last), task ID and original order to resolve ties consistently.
    usort($tasks, static function (array $a, array $b) use ($now): int {
        $scoreOrder = $b['_ai']['score'] <=> $a['_ai']['score'];
        if ($scoreOrder) return $scoreOrder;
        $dueA = ai_date(isset($a['due_at']) ? (string)$a['due_at'] : null, $now->getTimezone());
        $dueB = ai_date(isset($b['due_at']) ? (string)$b['due_at'] : null, $now->getTimezone());
        return (($dueA ? $dueA->getTimestamp() : PHP_INT_MAX) <=> ($dueB ? $dueB->getTimestamp() : PHP_INT_MAX))
            ?: (((int)($a['task_id'] ?? 0)) <=> ((int)($b['task_id'] ?? 0)))
            ?: ($a['_ai_input_order'] <=> $b['_ai_input_order']);
    });
    foreach ($tasks as &$task) unset($task['_ai_input_order']);
    unset($task);
    return $tasks;
}

function ai_minutes(string $time, int $fallback): int
{
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) return $fallback;
    return (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
}

/** Shift to the next allowed minute; equal start and end disables quiet hours. */
function ai_after_quiet_hours(DateTimeImmutable $candidate, array $profile): DateTimeImmutable
{
    $start = ai_minutes((string)($profile['quiet_start'] ?? '22:00'), 22 * 60);
    $end = ai_minutes((string)($profile['quiet_end'] ?? '08:00'), 8 * 60);
    if ($start === $end) return $candidate;
    $minute = (int)$candidate->format('G') * 60 + (int)$candidate->format('i');
    $quiet = $start < $end ? ($minute >= $start && $minute < $end) : ($minute >= $start || $minute < $end);
    if (!$quiet) return $candidate;
    if ($start > $end && $minute >= $start) $candidate = $candidate->modify('+1 day');
    return $candidate->setTime(intdiv($end, 60), $end % 60, 0);
}

/** A proposed time only: callers must let the user accept, edit, or ignore it. */
function ai_suggest_reminder(array $task, array $profile = [], ?DateTimeImmutable $now = null): ?array
{
    if (!in_array($task['status'] ?? 'PENDING', ['PENDING', 'IN_PROGRESS'], true)) return null;
    if (array_key_exists('notifications_enabled', $profile) && !$profile['notifications_enabled']) return null;
    $now = ai_clock($profile, $now);
    $due = ai_date(isset($task['due_at']) ? (string)$task['due_at'] : null, $now->getTimezone());
    $earliest = $now->modify('+15 minutes');
    $futureDeadline = $due && $due > $now;
    $hour = max(0, min(23, (int)($profile['preferred_hour'] ?? 9)));
    // Importance selects a starting point before the deadline; the preferred hour then selects a time on that day.
    $leadHours = ['HIGH' => 24, 'MEDIUM' => 12, 'LOW' => 4][$task['importance'] ?? 'MEDIUM'] ?? 12;
    $target = $futureDeadline ? $due->modify("-{$leadHours} hours") : $earliest;
    if ($target < $earliest) $target = $earliest;
    $preferred = $target->setTime($hour, 0, 0);
    $candidate = ai_after_quiet_hours($preferred < $earliest ? $earliest : $preferred, $profile);
    // Quiet hours may push the suggestion past its deadline. Try earlier alternatives, or return no suggestion if none fits.
    if ($futureDeadline && $candidate >= $due) {
        $candidate = ai_after_quiet_hours($target, $profile);
        if ($candidate >= $due) $candidate = ai_after_quiet_hours($earliest, $profile);
        if ($candidate >= $due) return null;
    }
    $reason = ($profile['behavior_source'] ?? 'preference') === 'history'
        ? sprintf('Starts from your common completion hour (%02d:00), from %d recent completions, when timing allows.', $hour, (int)($profile['completion_count'] ?? 0))
        : sprintf('Starts from your preferred hour (%02d:00); there is not enough completion history for adaptive timing yet.', $hour);
    if ($due && $due <= $now) $reason .= ' The deadline has passed, so this is the next suitable follow-up time.';
    elseif ($futureDeadline) $reason .= ' Scheduled before the deadline; earlier timing is used when necessary.';
    else $reason .= ' No deadline is set; this is a gentle follow-up.';
    if ($candidate->format('H:i') !== sprintf('%02d:00', $hour)) $reason .= ' The time is adjusted for the current time, deadline or quiet hours.';
    $reason .= ' Quiet hours are respected. You can change or ignore this suggestion.';
    $title = trim((string)($task['title'] ?? 'your task'));
    $message = 'Time to focus on ' . ($title !== '' ? $title : 'your task') . '.';
    return ['reminder_at' => $candidate->format('Y-m-d H:i:s'), 'reason' => $reason, 'message' => $message];
}
