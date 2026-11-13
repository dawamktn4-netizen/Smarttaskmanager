<?php
require 'config.php';
require_login();
$uid = (int) $_SESSION['user_id'];
// Urgency changes as time passes, so refresh scores on page load before selecting the top three tasks.
ai_refresh($pdo, $uid);
$q = $pdo->prepare("SELECT COUNT(*) total, SUM(status IN ('PENDING','IN_PROGRESS')) active, SUM(status IN ('PENDING','IN_PROGRESS') AND DATE(due_at)=CURDATE()) today, SUM(status IN ('PENDING','IN_PROGRESS') AND due_at<NOW()) overdue, SUM(status='COMPLETED') completed FROM tasks WHERE user_id=?");
$q->execute([$uid]);
$counts = $q->fetch();
$q = $pdo->prepare("SELECT t.*,c.category_name FROM tasks t LEFT JOIN categories c ON c.category_id=t.category_id WHERE t.user_id=? AND t.status IN ('PENDING','IN_PROGRESS') ORDER BY t.ai_priority_score DESC,t.due_at IS NULL,t.due_at,t.task_id LIMIT 3");
$q->execute([$uid]);
$focus = $q->fetchAll();
$q = $pdo->prepare("SELECT t.*,c.category_name FROM tasks t LEFT JOIN categories c ON c.category_id=t.category_id WHERE t.user_id=? AND t.status IN ('PENDING','IN_PROGRESS') AND t.due_at IS NOT NULL ORDER BY t.due_at,t.task_id LIMIT 4");
$q->execute([$uid]);
$upcoming = $q->fetchAll();
$q = $pdo->prepare("SELECT r.*,t.title FROM reminders r JOIN tasks t ON t.task_id=r.task_id AND t.user_id=r.user_id WHERE r.user_id=? AND r.is_enabled=1 AND r.is_sent=0 AND r.suggestion_status NOT IN ('PENDING','IGNORED') AND t.status IN ('PENDING','IN_PROGRESS') ORDER BY r.reminder_at LIMIT 3");
$q->execute([$uid]);
$reminders = $q->fetchAll();
$q = $pdo->prepare("SELECT DATE(action_at) day,COUNT(*) total FROM task_history WHERE user_id=? AND action_type='COMPLETED' AND action_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) AND action_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) GROUP BY DATE(action_at)");
$q->execute([$uid]);
$completionDays = array_column($q->fetchAll(), 'total', 'day');
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $days[] = ['date' => $date, 'label' => date('D', strtotime($date)), 'count' => (int) ($completionDays[$date] ?? 0)];
}
// The total number of completions in the last week is calculated to provide context for the user and to display a summary of their recent productivity.
$weekTotal = array_sum(array_column($days, 'count'));
$chartMax = max(1, ...array_column($days, 'count'));
$firstName = explode(' ', trim((string) ($_SESSION['full_name'] ?? 'there')))[0];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$pageTitle = 'Overview';
include 'includes/header.php';
?>

<main class="container dashboard-container" id="main-content">
    <section class="page-heading">
        <div><p class="eyebrow">YOUR PERSONAL WORKSPACE</p><h1><?= e($greeting) ?>, <?= e($firstName) ?><span class="greeting-dot">.</span></h1><p class="muted">Your priorities, deadlines and progress. All in one place.</p></div>
        <a class="btn primary" href="add_task.php"><?= icon('plus') ?>Create task</a>
    </section>
    <div class="overview-date"><?= icon('calendar') ?><time datetime="<?= date('Y-m-d') ?>"><?= date('l, j F Y') ?></time><span class="date-separator"></span><span><?= (int) $counts['today'] ? (int) $counts['today'] . ' task' . ((int) $counts['today'] === 1 ? '' : 's') . ' due today' : 'Make today a focused day' ?></span></div>
    <section class="stats" aria-label="Task summary">
        <?php foreach ([['active','Active tasks','tasks','violet','Waiting or in progress'],['today','Due today','calendar','amber','Scheduled for today'],['overdue','Overdue','clock','rose','Ready for your attention'],['completed','Completed','check-circle','green','Your progress so far']] as [$key,$label,$symbol,$tone,$note]): ?>
        <article class="stat"><div class="stat-top"><span class="stat-label"><?= e($label) ?></span><span class="stat-icon <?= e($tone) ?>"><?= icon($symbol) ?></span></div><strong class="stat-value"><?= (int) $counts[$key] ?></strong><span class="stat-note"><?= e($note) ?></span></article>
        <?php endforeach; ?>
    </section>
    
    <div class="dashboard-grid">
        <section class="panel dashboard-ai-entry"><div><p class="eyebrow">PLAN WITH CONFIDENCE</p><h2>A clearer plan starts with one decision.</h2><p>Try a different deadline or importance and watch the assistant explain its new score. Preview changes before editing your plan.</p></div><a class="btn primary" href="assistant.php#decision-lab">Explore priority insights <?= icon('sparkles') ?></a></section>
        <section class="panel focus-panel">
            <div class="panel-head"><div class="section-title"><span class="section-icon violet"><?= icon('sparkles') ?></span><div><h2>Your focus list</h2><p class="muted">Your highest-priority tasks, ready for action.</p></div></div><a class="text-link" href="assistant.php">View insights <?= icon('arrow-right') ?></a></div>
            <div class="ai-caption"><span class="ai-label"><?= icon('sparkles') ?>EXPLAINABLE AI</span><span>Local expert system · You stay in control</span></div>
            <?php if (!$focus): ?>
            <div class="empty focus-empty"><span class="empty-icon"><?= icon('target') ?></span><h3>A fresh start, a clear direction.</h3><p>Add your first task and your assistant will help you decide what to focus on next.</p><a href="add_task.php" class="btn secondary"><?= icon('plus') ?>Add your first task</a></div>
            <?php else: ?>
            <div class="focus-list">
            <?php foreach ($focus as $index => $task): ?>
                <article class="focus-task<?= $index === 0 ? ' first-focus' : '' ?>">
                    <span class="focus-rank"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <div class="focus-task-content"><div class="task-title-line"><a href="edit_task.php?id=<?= (int) $task['task_id'] ?>" class="task-title"><?= e($task['title']) ?></a><span class="pill <?= e(strtolower((string) $task['ai_priority_level'])) ?>"><?= e(ucfirst(strtolower((string) $task['ai_priority_level']))) ?></span></div><p class="focus-reason"><?= e($task['ai_reason']) ?></p><div class="task-meta"><span><?= icon('book') ?><?= e($task['category_name'] ?? 'Uncategorised') ?></span><span><?= icon('clock') ?><?= e($task['due_at'] ? format_datetime($task['due_at']) : 'No deadline') ?></span><span class="score-label"><?= (int) round((float) $task['ai_priority_score']) ?>/100 priority</span></div></div>
                    <form action="task_action.php" method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int) $task['task_id'] ?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="return_to" value="dashboard.php"><button type="submit" class="task-check" aria-label="Mark <?= e($task['title']) ?> complete" title="Mark complete"><?= icon('check') ?></button></form>
                </article>
            <?php endforeach; ?>
            </div><a class="panel-footer-link" href="tasks.php">See all your tasks <?= icon('arrow-right') ?></a>
            <?php endif; ?>
        </section>
        <section class="panel week-panel">
            <div class="panel-head"><h2>Weekly progress</h2><span class="subtle-pill">Last 7 days</span></div>
            <div class="week-summary"><strong><?= $weekTotal ?></strong><span>completion<?= $weekTotal === 1 ? '' : 's' ?> recorded</span></div>
            <div class="bar-chart" role="img" aria-label="Completion actions over the last seven days: <?= e(implode('; ', array_map(static function ($day) { return $day['label'] . ' ' . $day['date'] . ': ' . $day['count']; }, $days))) ?>">
                <?php foreach ($days as $day): ?><div class="bar-column<?= $day['date'] === date('Y-m-d') ? ' current' : '' ?>"><span class="bar-value"><?= $day['count'] ?></span><div class="bar-track"><div class="bar-fill<?= $day['count'] === 0 ? ' zero' : '' ?>" style="height:<?= $day['count'] === 0 ? 3 : max(6, (int) round($day['count'] / $chartMax * 100)) ?>%"></div></div><span class="bar-label"><?= e($day['label']) ?></span></div><?php endforeach; ?>
            </div>
            <div class="chart-note"><?= icon($weekTotal ? 'check-circle' : 'target') ?><p><?= $weekTotal ? 'Every finished task is a step forward. Keep building your momentum.' : 'Your progress starts with one task. Completed actions will appear here.' ?></p></div>
            <a class="text-link" href="analytics.php">Explore your activity <?= icon('arrow-right') ?></a>
        </section>
        <section class="panel schedule-panel">
            <div class="panel-head"><div class="section-title"><span class="section-icon neutral"><?= icon('calendar') ?></span><div><h2>Upcoming deadlines</h2><p class="muted">Your next deadlines, in order.</p></div></div><a class="text-link" href="tasks.php">View tasks <?= icon('arrow-right') ?></a></div>
            <?php if (!$upcoming): ?><div class="empty compact-empty"><span class="empty-icon"><?= icon('calendar') ?></span><h3>No deadlines on the horizon.</h3><p>Add a due date to a task to give your day a little structure.</p><a class="text-link" href="add_task.php">Plan a task <?= icon('arrow-right') ?></a></div><?php else: ?><div class="schedule-list"><?php foreach ($upcoming as $task): $isOverdue = strtotime($task['due_at']) < time(); ?><article class="schedule-row"><div class="date-tile<?= $isOverdue ? ' overdue' : '' ?>"><span><?= e(date('M', strtotime($task['due_at']))) ?></span><strong><?= e(date('d', strtotime($task['due_at']))) ?></strong></div><div class="schedule-content"><a class="task-title" href="edit_task.php?id=<?= (int) $task['task_id'] ?>"><?= e($task['title']) ?></a><div class="task-meta"><span><?= e($task['category_name'] ?? 'Uncategorised') ?></span><span><?= e(date('H:i', strtotime($task['due_at']))) ?></span></div></div><span class="pill <?= $isOverdue ? 'high' : 'neutral' ?>"><?= $isOverdue ? 'Overdue' : ($task['status'] === 'IN_PROGRESS' ? 'In progress' : 'To do') ?></span></article><?php endforeach; ?></div><?php endif; ?>
        </section>
        <section class="panel reminders-panel">
            <div class="panel-head"><div class="section-title"><span class="section-icon amber"><?= icon('bell') ?></span><h2>Scheduled reminders</h2></div><a class="text-link icon-link" href="reminders.php" aria-label="Manage reminders"><?= icon('arrow-right') ?></a></div>
            <?php if (!$reminders): ?><div class="empty compact-empty"><span class="empty-icon"><?= icon('bell') ?></span><h3>A little peace of mind.</h3><p>Set a reminder and give yourself one less thing to remember.</p><a href="reminders.php" class="text-link">Set a reminder <?= icon('arrow-right') ?></a></div><?php else: ?><div class="reminder-list"><?php foreach ($reminders as $reminder): ?><article class="reminder-preview"><span class="reminder-dot"></span><div><strong><?= e($reminder['title']) ?></strong><p><?= e(format_datetime($reminder['reminder_at'])) ?></p><span class="mini-label"><?= $reminder['reminder_type'] === 'AI_SUGGESTED' ? 'AI-assisted reminder' : 'Personal reminder' ?></span></div></article><?php endforeach; ?></div><a class="panel-footer-link" href="reminders.php">Manage reminders <?= icon('arrow-right') ?></a><?php endif; ?>
        </section>
    </div>
    <div class="workspace-note"><?= icon('sparkles') ?><p>Your assistant considers deadlines, importance and your task history. <a href="assistant.php">See how it works</a></p></div>
</main>
<?php include 'includes/footer.php'; ?>
