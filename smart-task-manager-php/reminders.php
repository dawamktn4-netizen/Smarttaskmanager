<?php
require 'config.php';
require_login();
$uid = (int) $_SESSION['user_id'];
$preferences = user_preferences($pdo, $uid);
$profile = ai_profile($pdo, $uid);
$selectedTask = filter_input(INPUT_GET, 'task_id', FILTER_VALIDATE_INT) ?: 0;
$q = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND status IN ('PENDING','IN_PROGRESS') ORDER BY title");
$q->execute([$uid]);
$tasks = $q->fetchAll();
$q = $pdo->prepare("SELECT r.*, t.title, t.status AS task_status FROM reminders r JOIN tasks t ON t.task_id = r.task_id AND t.user_id = r.user_id WHERE r.user_id = ? ORDER BY r.is_enabled DESC, r.reminder_at ASC, r.reminder_id DESC");
$q->execute([$uid]);
$reminders = $q->fetchAll();
$blocked = $active = $past = [];
foreach ($reminders as $reminder) {
    // Any previous AI decision, including ignore, prevents repeat suggestions; enabled manual reminders do too.
    if ($reminder['reminder_type'] === 'AI_SUGGESTED' || $reminder['is_enabled']) $blocked[(int) $reminder['task_id']] = true;
    if ($reminder['is_enabled'] && $reminder['dismissed_at'] === null && in_array($reminder['task_status'], ['PENDING','IN_PROGRESS'], true) && in_array($reminder['suggestion_status'], ['NOT_APPLICABLE','ACCEPTED','CHANGED'], true)) $active[] = $reminder;
    else $past[] = $reminder;
}
$suggestions = [];
// These are proposals only; viewing this page does not save or schedule them.
foreach ($tasks as $task) {
    if (isset($blocked[(int) $task['task_id']])) continue;
    $suggestion = ai_suggest_reminder($task, $profile);
    if ($suggestion !== null) $suggestions[] = ['task' => $task, 'suggestion' => $suggestion];
}
usort($suggestions, static function (array $a, array $b) use ($selectedTask): int {
    $selected = ((int) $b['task']['task_id'] === $selectedTask) <=> ((int) $a['task']['task_id'] === $selectedTask);
    return $selected ?: strcmp($a['suggestion']['reminder_at'], $b['suggestion']['reminder_at']);
});
$pageTitle = 'Reminders';
include 'includes/header.php';
?>
<main class="container" id="main-content">
<section class="hero"><div><p class="eyebrow">Your time, your decision</p><h1>A little nudge, on your terms.</h1><p class="muted">Review AI suggestions, choose your own time, and keep interruptions under control.</p></div><button type="button" class="btn secondary" id="enable-notifications">Enable browser alerts</button></section>
<section class="panel"><div class="panel-head"><h2>Delivery preferences</h2><a href="settings.php" class="btn secondary">Adjust preferences</a></div>
<p>Times are shown in <strong><?= e($preferences['timezone']) ?></strong>. Notifications are <strong><?= $preferences['notifications_enabled'] ? 'on' : 'paused' ?></strong>.<?php if ($preferences['quiet_start'] !== $preferences['quiet_end']): ?> Quiet hours: <strong><?= e(substr($preferences['quiet_start'], 0, 5)) ?>–<?= e(substr($preferences['quiet_end'], 0, 5)) ?></strong>.<?php else: ?> Quiet hours are off.<?php endif; ?></p>
<p class="muted">Reminders appear while this app is open. Browser alerts are optional and require permission. There is no email or background delivery; reminders held during quiet hours appear when you next open the app outside those hours.</p><p id="notification-permission-status" class="muted" aria-live="polite"></p></section>
<section class="panel" aria-labelledby="suggestions-heading"><div class="panel-head"><div><p class="eyebrow">Explainable AI</p><h2 id="suggestions-heading">Suggested for you</h2></div><span class="pill"><?= count($suggestions) ?> to review</span></div>
<p class="muted">Suggestions use your task deadlines, preferences, and available activity history. Nothing is scheduled until you accept it or save a time you choose.</p>
<?php if (!$suggestions): ?><div class="empty"><h3>You're in control.</h3><p>No new suggestions right now. Active tasks may already have a reminder, have a suggestion you reviewed, or have no suitable time before their deadline. You can always add a reminder below.</p></div>
<?php else: ?><div class="stack"><?php foreach ($suggestions as $item): $task = $item['task']; $suggestion = $item['suggestion']; ?>
<article class="reminder" id="suggestion-<?= (int) $task['task_id'] ?>"><div class="panel-head"><h3><?= e($task['title']) ?></h3><span class="pill">AI suggestion</span></div><p><strong><?= e(date('D, j M · H:i', strtotime($suggestion['reminder_at']))) ?></strong></p><p><?= e($suggestion['reason']) ?></p><p class="muted"><?= e($suggestion['message']) ?></p>
<form action="reminder_action.php" method="post" class="actions"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="task_id" value="<?= (int) $task['task_id'] ?>"><button class="btn primary" name="action" value="accept">Accept suggestion</button><button class="btn secondary" name="action" value="ignore">Ignore suggestion</button></form>
<details><summary>Choose a different time or message</summary><form action="reminder_action.php" method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="task_id" value="<?= (int) $task['task_id'] ?>"><input type="hidden" name="action" value="change">
<div><label for="suggestion-time-<?= (int) $task['task_id'] ?>">Your reminder time</label><input id="suggestion-time-<?= (int) $task['task_id'] ?>" type="datetime-local" name="reminder_at" value="<?= e(substr(str_replace(' ', 'T', $suggestion['reminder_at']), 0, 16)) ?>" required></div><div class="wide"><label for="suggestion-message-<?= (int) $task['task_id'] ?>">Your message</label><input id="suggestion-message-<?= (int) $task['task_id'] ?>" name="message" maxlength="500" value="<?= e($suggestion['message']) ?>"></div><button class="btn primary">Save my reminder</button></form></details></article>
<?php endforeach; ?></div><?php endif; ?></section>
<section class="panel" aria-labelledby="manual-heading"><div class="panel-head"><div><p class="eyebrow">Make it yours</p><h2 id="manual-heading">Schedule a reminder</h2></div></div>
<?php if (!$tasks): ?><div class="empty"><p>Create an active task to schedule a reminder.</p><a href="add_task.php" class="btn primary">Create a task</a></div><?php else: ?>
<form action="reminder_action.php" method="post" class="form-grid"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="create">
<div><label for="reminder-task">Task</label><select id="reminder-task" name="task_id" required><?php foreach ($tasks as $task): ?><option value="<?= (int) $task['task_id'] ?>" <?= (int) $task['task_id'] === $selectedTask ? 'selected' : '' ?>><?= e($task['title']) ?></option><?php endforeach; ?></select></div><div><label for="reminder-time">Reminder time</label><input id="reminder-time" type="datetime-local" name="reminder_at" required><small class="muted">In <?= e($preferences['timezone']) ?>. Choose a future time.</small></div><div class="wide"><label for="reminder-message">Message <span class="muted">(optional)</span></label><input id="reminder-message" name="message" maxlength="500" placeholder="A small next step is enough."></div><button class="btn primary">Schedule reminder</button></form><?php endif; ?></section>
<section class="panel" aria-labelledby="scheduled-heading"><div class="panel-head"><h2 id="scheduled-heading">Your scheduled reminders</h2><span class="pill"><?= count($active) ?> active</span></div>
<?php if (!$active): ?><div class="empty"><p>No active reminders. Accept a suggestion or set your own time above.</p></div><?php endif; ?>
<?php foreach ($active as $reminder): ?><article class="reminder"><div class="panel-head"><h3><?= e($reminder['title']) ?></h3><span class="pill"><?= $reminder['reminder_type'] === 'MANUAL' ? 'Your reminder' : ($reminder['suggestion_status'] === 'CHANGED' ? 'AI · adjusted by you' : 'AI · accepted by you') ?></span></div><p><?= e($reminder['message'] ?: 'Time to take the next step on this task.') ?></p><p class="muted"><?= e(date('D, j M Y · H:i', strtotime($reminder['reminder_at']))) ?> · <?= $reminder['is_sent'] ? 'Shown in the app' : (strtotime($reminder['reminder_at']) <= time() ? 'Ready when delivery is available' : 'Scheduled') ?></p>
<form action="reminder_action.php" method="post" class="actions"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="reminder_id" value="<?= (int) $reminder['reminder_id'] ?>"><?php if (strtotime($reminder['reminder_at']) <= time() || $reminder['is_sent']): ?><button class="btn secondary" name="action" value="dismiss">Dismiss</button><button class="btn secondary" name="action" value="snooze">Snooze 15 minutes</button><?php endif; ?><button class="btn danger" name="action" value="cancel" data-confirm="Cancel this reminder? Your task will remain active.">Cancel reminder</button></form></article><?php endforeach; ?></section>
<?php if ($past): ?><section class="panel"><details><summary>Reviewed and closed reminders (<?= count($past) ?>)</summary><?php foreach (array_slice($past, 0, 40) as $reminder): ?><article class="reminder"><h3><?= e($reminder['title']) ?></h3><p class="muted"><?= e(date('j M Y · H:i', strtotime($reminder['reminder_at']))) ?> · <?= $reminder['suggestion_status'] === 'IGNORED' ? 'Suggestion ignored' : ($reminder['dismissed_at'] ? 'Dismissed' : (!in_array($reminder['task_status'], ['PENDING','IN_PROGRESS'], true) ? 'Task closed' : ($reminder['suggestion_status'] === 'PENDING' ? 'Unconfirmed · delivery off' : 'Cancelled'))) ?></p></article><?php endforeach; ?><?php if (count($past) > 40): ?><p class="muted">Showing 40 closed reminders.</p><?php endif; ?></details></section><?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
