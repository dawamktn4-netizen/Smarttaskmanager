<?php
require 'config.php';
require_login();
$uid = (int) $_SESSION['user_id'];
$q = $pdo->prepare("SELECT COUNT(*) total, SUM(status='COMPLETED') completed, SUM(status IN ('PENDING','IN_PROGRESS')) active, SUM(status='CANCELLED') cancelled, SUM(status='COMPLETED' AND due_at IS NOT NULL AND completed_at IS NOT NULL) dated_completed, SUM(status='COMPLETED' AND due_at IS NOT NULL AND completed_at<=due_at) on_time FROM tasks WHERE user_id=?");
$q->execute([$uid]);
$summary = $q->fetch();
// Completion rate uses active + completed tasks; archived tasks are excluded from the denominator.
$trackedTotal = (int) $summary['active'] + (int) $summary['completed'];
$completionRate = $trackedTotal ? (int) round((int) $summary['completed'] / $trackedTotal * 100) : 0;
// On-time rate uses only completed tasks with deadlines; null means no data, not zero performance.
$onTimeRate = (int) $summary['dated_completed'] ? (int) round((int) $summary['on_time'] / (int) $summary['dated_completed'] * 100) : null;
// The chart counts history events: reopening and completing one task again produces another completion.
$q = $pdo->prepare("SELECT DATE(action_at) day,SUM(action_type='COMPLETED') completions,SUM(action_type='POSTPONED') postponements FROM task_history WHERE user_id=? AND action_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) AND action_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY) GROUP BY DATE(action_at)");
$q->execute([$uid]);
$activity = array_column($q->fetchAll(), null, 'day');
$chartDays = [];
for ($i=13; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime('-' . $i . ' days'));
    $chartDays[] = ['date'=>$date, 'label'=>date('j', strtotime($date)), 'count'=>(int) ($activity[$date]['completions'] ?? 0), 'postponements'=>(int) ($activity[$date]['postponements'] ?? 0)];
}
$completedPeriod = array_sum(array_column($chartDays,'count'));
$postponedPeriod = array_sum(array_column($chartDays,'postponements'));
$chartMax = max(1, ...array_column($chartDays,'count'));
$q = $pdo->prepare("SELECT COALESCE(c.category_name,'Uncategorised') category_name, COUNT(*) total, SUM(t.status='COMPLETED') completed, SUM(t.status IN ('PENDING','IN_PROGRESS')) active FROM tasks t LEFT JOIN categories c ON c.category_id=t.category_id WHERE t.user_id=? AND t.status<>'CANCELLED' GROUP BY c.category_id,c.category_name ORDER BY total DESC,category_name");
$q->execute([$uid]);
$categories = $q->fetchAll();
$pageTitle = 'Analytics';
include 'includes/header.php';
?>
<main class="container" id="main-content">
    <section class="page-heading"><div><p class="eyebrow">A LITTLE PERSPECTIVE</p><h1>See your progress<span class="greeting-dot">.</span></h1><p class="muted">Real activity from your workspace, with room to reflect.</p></div><a href="history.php" class="btn secondary"><?= icon('history') ?>View activity log</a></section>
    <section class="stats" aria-label="Progress summary">
        <article class="stat"><div class="stat-top"><span class="stat-label">Tasks completed</span><span class="stat-icon green"><?= icon('check-circle') ?></span></div><strong class="stat-value"><?= (int) $summary['completed'] ?></strong><span class="stat-note">Currently marked complete</span></article>
        <article class="stat"><div class="stat-top"><span class="stat-label">Completion rate</span><span class="stat-icon violet"><?= icon('chart') ?></span></div><strong class="stat-value"><?= $trackedTotal ? $completionRate . '%' : '—' ?></strong><span class="stat-note">Of active and completed tasks</span></article>
        <article class="stat"><div class="stat-top"><span class="stat-label">On-time completion</span><span class="stat-icon amber"><?= icon('clock') ?></span></div><strong class="stat-value"><?= $onTimeRate === null ? '—' : $onTimeRate . '%' ?></strong><span class="stat-note"><?= (int) $summary['dated_completed'] ?> completed tasks with deadlines</span></article>
        <article class="stat"><div class="stat-top"><span class="stat-label">Postponements</span><span class="stat-icon rose"><?= icon('history') ?></span></div><strong class="stat-value"><?= $postponedPeriod ?></strong><span class="stat-note">Recorded in the last 14 days</span></article>
    </section>
    <div class="analytics-grid">
        <section class="panel activity-chart-panel"><div class="panel-head"><div><h2>Small steps add up</h2><p class="muted">Completed actions over the last 14 days.</p></div><span class="subtle-pill"><?= e(date('j M', strtotime('-13 days'))) ?> – <?= e(date('j M')) ?></span></div><div class="week-summary"><strong><?= $completedPeriod ?></strong><span>completion<?= $completedPeriod === 1 ? '' : 's' ?> recorded</span></div><div class="bar-chart chart-fourteen" role="img" aria-label="Daily completion actions: <?= e(implode('; ', array_map(static function ($day) { return $day['date'] . ': ' . $day['count']; }, $chartDays))) ?>"><?php foreach ($chartDays as $day): ?><div class="bar-column<?= $day['date'] === date('Y-m-d') ? ' current' : '' ?>"><span class="bar-value"><?= $day['count'] ?></span><div class="bar-track"><div class="bar-fill<?= $day['count'] === 0 ? ' zero' : '' ?>" style="height:<?= $day['count'] === 0 ? 3 : max(6,(int) round($day['count']/$chartMax*100)) ?>%"></div></div><span class="bar-label"><?= e($day['label']) ?></span></div><?php endforeach; ?></div><p class="chart-footnote">Each bar counts completion actions on that date. A task completed again after reopening counts as another action.</p></section>
        <section class="panel"><div class="panel-head"><h2>Your workspace at a glance</h2></div><div class="progress-ring<?= $trackedTotal ? '' : ' ring-empty' ?>" style="--progress:<?= $completionRate ?>" role="img" aria-label="<?= $completionRate ?> percent completed across <?= $trackedTotal ?> active and completed tasks"><div><strong><?= $trackedTotal ? $completionRate . '%' : '—' ?></strong><span>completed</span></div></div><div class="legend-row"><span><i class="legend-dot completed-dot"></i>Completed</span><strong><?= (int) $summary['completed'] ?></strong></div><div class="legend-row"><span><i class="legend-dot active-dot"></i>Active</span><strong><?= (int) $summary['active'] ?></strong></div><div class="legend-row"><span><i class="legend-dot cancelled-dot"></i>Archived</span><strong><?= (int) $summary['cancelled'] ?></strong></div><p class="chart-footnote">Archived tasks are excluded from the completion rate.</p></section>
        <section class="panel category-panel"><div class="panel-head"><div><h2>Where your attention goes</h2><p class="muted">Progress across your task categories.</p></div><?= icon('book') ?></div><?php if (!$categories): ?><div class="empty"><span class="empty-icon"><?= icon('book') ?></span><h3>Your story starts here.</h3><p>Create and categorise a task to begin exploring your patterns.</p><a class="btn secondary" href="add_task.php"><?= icon('plus') ?>Create task</a></div><?php else: ?><div class="category-progress-list"><?php foreach ($categories as $category): $rate=(int) round((int) $category['completed']/(int) $category['total']*100); ?><div class="category-progress"><div><strong><?= e($category['category_name']) ?></strong><span><?= (int) $category['completed'] ?> / <?= (int) $category['total'] ?> complete</span></div><progress max="100" value="<?= $rate ?>" aria-label="<?= e($category['category_name']) ?>: <?= $rate ?> percent complete"><?= $rate ?>%</progress><small><?= (int) $category['active'] ?> active task<?= (int) $category['active'] === 1 ? '' : 's' ?></small></div><?php endforeach; ?></div><?php endif; ?></section>
        <section class="panel insight-panel"><span class="section-icon violet"><?= icon('sparkles') ?></span><p class="eyebrow">MAKE SENSE OF YOUR ROUTINE</p><h2>Better decisions begin with a little reflection.</h2><p>Your AI assistant uses deadlines, importance, postponements and available completion history to explain its recommendations.</p><a class="btn secondary" href="assistant.php">Explore your recommendations <?= icon('arrow-right') ?></a><div class="insight-divider"></div><h3>What these numbers mean</h3><p class="small">On-time completion compares a completed task's recorded finish time with its current deadline. Tasks without a deadline are excluded. Dates use <?= e(date_default_timezone_get()) ?>.</p><p class="small">These are descriptive workspace statistics. They do not establish that the system improves productivity.</p></section>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
