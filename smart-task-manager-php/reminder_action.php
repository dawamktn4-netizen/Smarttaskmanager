<?php
declare(strict_types=1);
require 'config.php';
require_login();
// Changes require a signed-in user, a POST request and a valid CSRF token from the app.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the reminder controls to make a change.');
}
verify_csrf();
$uid = (int) $_SESSION['user_id'];
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if (!in_array($action, ['create', 'accept', 'change', 'ignore', 'dismiss', 'snooze', 'cancel'], true)) {
    flash('error', 'That reminder action is not supported.');
    redirect('reminders.php');
}
try {
    // Save the reminder and its activity entry together, or undo both if anything fails.
    $pdo->beginTransaction();
    if (in_array($action, ['create', 'accept', 'change', 'ignore'], true)) {
        $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$taskId) throw new InvalidArgumentException('Choose an active task.');
        // Check ownership on the server and lock this task while checking and saving its reminder.
        $query = $pdo->prepare("SELECT * FROM tasks WHERE task_id = ? AND user_id = ? AND status IN ('PENDING','IN_PROGRESS') FOR UPDATE");
        $query->execute([$taskId, $uid]);
        $task = $query->fetch();
        if (!$task) throw new InvalidArgumentException('This task is unavailable or is no longer active.');
        $suggestion = null;
        if ($action !== 'create') {
            $existing = $pdo->prepare("SELECT reminder_id FROM reminders WHERE task_id = ? AND user_id = ? AND (reminder_type = 'AI_SUGGESTED' OR is_enabled = 1) LIMIT 1");
            $existing->execute([$taskId, $uid]);
            if ($existing->fetch()) throw new InvalidArgumentException('This suggestion has already been reviewed, or the task already has a reminder.');
            $suggestion = ai_suggest_reminder($task, ai_profile($pdo, $uid));
            if ($suggestion === null) throw new InvalidArgumentException('A suitable AI reminder is no longer available. You can schedule your own reminder.');
        }
        if (in_array($action, ['create', 'change'], true)) {
            if (!is_string($_POST['reminder_at'] ?? null) || !is_string($_POST['message'] ?? '')) throw new InvalidArgumentException('Enter a valid reminder time and message.');
            $reminderAt = parse_datetime($_POST['reminder_at']);
            if ($reminderAt === null || strtotime($reminderAt) <= time()) throw new InvalidArgumentException('Choose a reminder time in the future.');
            $message = trim($_POST['message'] ?? '');
            $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
            if ($length > 500) throw new InvalidArgumentException('Keep the reminder message to 500 characters or fewer.');
            if ($message === '') $message = 'Time to take the next step on ' . $task['title'] . '.';
        } else {
            // Recompute on the server; a submitted AI time or message is never trusted.
            $reminderAt = $suggestion['reminder_at'];
            $message = $suggestion['message'];
        }
        $duplicate = $pdo->prepare('SELECT reminder_id FROM reminders WHERE user_id = ? AND task_id = ? AND reminder_at = ? AND is_enabled = 1 AND message = ? LIMIT 1');
        $duplicate->execute([$uid, $taskId, $reminderAt, $message]);
        if ($duplicate->fetch()) throw new InvalidArgumentException('That reminder is already scheduled.');
        $type = $action === 'create' ? 'MANUAL' : 'AI_SUGGESTED';
        // Keep the user's decision: accept/change enables delivery; ignore records it without scheduling.
        $status = ['create' => 'NOT_APPLICABLE', 'accept' => 'ACCEPTED', 'change' => 'CHANGED', 'ignore' => 'IGNORED'][$action];
        $query = $pdo->prepare('INSERT INTO reminders (task_id, user_id, reminder_at, reminder_type, suggestion_status, message, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$taskId, $uid, $reminderAt, $type, $status, $message, $action === 'ignore' ? 0 : 1]);
        $activity = $action === 'ignore' ? 'REMINDER_IGNORED' : ($action === 'change' ? 'REMINDER_CHANGED' : 'REMINDER_ACCEPTED');
        $detail = $action === 'ignore' ? 'AI reminder suggestion ignored; no notification scheduled.' : ($action === 'create' ? 'Manual reminder scheduled for ' : ($action === 'change' ? 'AI suggestion adjusted by the user to ' : 'AI reminder accepted for ')) . $reminderAt . '.';
        log_activity($pdo, $uid, (int) $taskId, $activity, $detail);
        $success = $action === 'ignore' ? 'Suggestion ignored. It will not be suggested again for this task.' : ($action === 'change' ? 'Reminder scheduled with your changes.' : 'Reminder scheduled.');
    } else {
        $reminderId = filter_var($_POST['reminder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$reminderId) throw new InvalidArgumentException('Choose a valid reminder.');
        $query = $pdo->prepare("SELECT r.*, t.status AS task_status FROM reminders r JOIN tasks t ON t.task_id = r.task_id AND t.user_id = r.user_id WHERE r.reminder_id = ? AND r.user_id = ? FOR UPDATE");
        $query->execute([$reminderId, $uid]);
        $reminder = $query->fetch();
        if (!$reminder || !$reminder['is_enabled'] || $reminder['dismissed_at'] !== null || !in_array($reminder['suggestion_status'], ['NOT_APPLICABLE', 'ACCEPTED', 'CHANGED'], true)) throw new InvalidArgumentException('This reminder is unavailable or already closed.');
        if ($action === 'snooze') {
            if (!in_array($reminder['task_status'], ['PENDING','IN_PROGRESS'], true)) throw new InvalidArgumentException('Only reminders for active tasks can be snoozed.');
            if (!$reminder['is_sent'] && strtotime($reminder['reminder_at']) > time()) throw new InvalidArgumentException('You can snooze a reminder when it becomes due.');
            $newTime = date('Y-m-d H:i:s', time() + 15 * 60);
            $query = $pdo->prepare("UPDATE reminders SET reminder_at = ?, is_sent = 0, sent_at = NULL, dismissed_at = NULL, suggestion_status = CASE WHEN reminder_type = 'AI_SUGGESTED' THEN 'CHANGED' ELSE suggestion_status END WHERE reminder_id = ? AND user_id = ?");
            $query->execute([$newTime, $reminderId, $uid]);
            log_activity($pdo, $uid, (int) $reminder['task_id'], 'REMINDER_CHANGED', 'Reminder snoozed until ' . $newTime . '. Quiet hours still apply.');
            $success = 'Snoozed for 15 minutes. Your quiet hours still apply.';
        } elseif ($action === 'dismiss') {
            $query = $pdo->prepare('UPDATE reminders SET is_enabled = 0, dismissed_at = NOW() WHERE reminder_id = ? AND user_id = ?');
            $query->execute([$reminderId, $uid]);
            log_activity($pdo, $uid, (int) $reminder['task_id'], 'REMINDER_DISMISSED', 'Reminder dismissed.');
            $success = 'Reminder dismissed. Your task is still available.';
        } else {
            $query = $pdo->prepare('UPDATE reminders SET is_enabled = 0 WHERE reminder_id = ? AND user_id = ?');
            $query->execute([$reminderId, $uid]);
            log_activity($pdo, $uid, (int) $reminder['task_id'], 'REMINDER_CHANGED', 'Reminder cancelled by the user.');
            $success = 'Reminder cancelled.';
        }
    }
    $pdo->commit();
    flash('success', $success);
} catch (InvalidArgumentException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', $error->getMessage());
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Reminder action failed: ' . $error->getMessage());
    flash('error', 'The reminder could not be updated. Please try again.');
}
redirect('reminders.php');
