<?php
require 'config.php';
require_login();
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/ai_preview.php';
$uid = (int)$_SESSION['user_id'];
$profile = ai_profile($pdo, $uid);
$query = $pdo->prepare("SELECT t.*, c.category_name FROM tasks t LEFT JOIN categories c ON c.category_id = t.category_id WHERE t.user_id = ? AND t.status IN ('PENDING', 'IN_PROGRESS')");
$query->execute([$uid]);
$ranked = ai_rank_tasks($query->fetchAll(), $profile);
$reviewedQuery = $pdo->prepare("SELECT DISTINCT task_id FROM reminders WHERE user_id=? AND (reminder_type='AI_SUGGESTED' OR is_enabled=1)");
$reviewedQuery->execute([$uid]);
$reviewedReminders = array_fill_keys(array_map('intval', $reviewedQuery->fetchAll(PDO::FETCH_COLUMN)), true);
$now = ai_clock($profile);
$labInput = ['task_id'=>'0','importance'=>'MEDIUM','deadline'=>'keep','status'=>'PENDING','postpone_count'=>'0'];
$labError = '';
$labResult = ai_preview($ranked, $profile, $labInput, $now);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $labResult = ai_preview($ranked, $profile, $_POST, $now);
        $labInput = array_intersect_key($_POST, $labInput);
    } catch (InvalidArgumentException $error) { $labError = $error->getMessage(); }
    // AJAX and ordinary form submissions share validation and the exact same server-rendered explanation.
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        if ($labError !== '') { http_response_code(422); echo json_encode(['error'=>$labError]); exit; }
        ob_start(); include __DIR__.'/includes/ai_lab_result.php'; $html = ob_get_clean();
        echo json_encode(['html'=>$html], JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
}
$overdue = count(array_filter($ranked, static fn($t)=>!empty($t['due_at']) && $t['due_at']<$now->format('Y-m-d H:i:s')));
$hasDemo = false;
foreach ($ranked as $task) if (strpos($task['title'], '[Demo]') === 0) $hasDemo = true;
$demoQuery = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id=? AND title LIKE '[Demo]%' AND status='COMPLETED'");
$demoQuery->execute([$uid]);
$hasDemo = $hasDemo || (int)$demoQuery->fetchColumn()>0;
$pageTitle = 'AI Assistant';
include 'includes/header.php';
?>
<main id="main-content" class="container">
    <section class="hero">
        <div><p class="eyebrow">YOUR STUDY ASSISTANT</p><h1>Decide what to work on next.</h1><p class="muted">Turn competing deadlines into a clear starting point. Explore the reasons, try a change, and choose your next step.</p></div>
        <a class="btn primary" href="add_task.php">+ Create a task</a>
    </section>
    <?php if ($hasDemo): ?><p class="notice">Your workspace includes [Demo] tasks. These examples influence rankings and completion patterns; they are not evidence of your real study habits.</p><?php endif; ?>
    <section class="panel dashboard-ai-entry"><div><p class="eyebrow">TALK TO YOUR WORKSPACE</p><h2>Prefer to ask?</h2><p>Chat about your tasks and prepare changes in everyday language. Review each change before saving.</p></div><a class="btn primary" href="chatbot.php">Open AI chatbot →</a></section>
    <section class="panel student-brief" aria-labelledby="brief-title">
        <div class="panel-head"><div><p class="eyebrow">A STARTING POINT FOR TODAY</p><h2 id="brief-title"><?= $ranked?'Start with one manageable step':'Make room for your next deadline' ?></h2></div><a class="btn secondary" href="#decision-lab">Try the AI decision lab</a></div>
        <?php if ($ranked): $next=$ranked[0]; ?>
        <h3><?= e($next['title']) ?></h3><p><?= e($next['_ai']['reason']) ?></p>
        <p><?= $overdue ?> overdue <?= $overdue===1?'task needs':'tasks need' ?> a review. <?= $overdue?'Check whether those deadlines are still realistic before following the order.':'Choose a short study block to make a start.' ?></p>
        <p class="field-help">Suggested study strategy: spend 5 minutes defining one small outcome, then 20 minutes working on it. This is a fixed study prompt, not an AI estimate of how long the task will take.</p>
        <div class="actions"><a class="btn primary" href="edit_task.php?id=<?= (int)$next['task_id'] ?>">Review this task</a><a class="btn secondary" href="reminders.php?task_id=<?= (int)$next['task_id'] ?>">Choose a reminder</a></div>
        <?php else: ?><p>Add an assignment, exam revision session or everyday responsibility. The assistant can compare its deadline and importance with your other tasks.</p><a class="btn primary" href="add_task.php">Add a study task</a><?php endif; ?>
    </section>
    <?php include __DIR__.'/includes/ai_lab.php'; ?>
    <section class="panel">
        <div class="panel-head"><h2>Your assistant, in the open</h2><span class="pill">Local expert system</span></div>
        <p>This assistant combines your deadlines, importance, progress, postponements and recent completion patterns. Each recommendation has a visible explanation. Your task information is processed in this application; these recommendations do not call an external AI service.</p>
        <div class="cards">
            <div><h3>Timing that adapts</h3><p><?= e($profile['behavior_summary']) ?></p><p class="muted">Quiet hours: <?= e((string)$profile['quiet_start']) ?>–<?= e((string)$profile['quiet_end']) ?> · <?= e((string)$profile['timezone']) ?>.</p><a class="link" href="settings.php">Adjust your preferences →</a></div>
            <div><h3>You stay in control</h3><p>Scores recommend an order; they never change your deadline or importance. Reminder suggestions require your review. You can accept, change or ignore them.</p><p class="muted">The score is a rule-based ranking, not a probability or a prediction of success.</p></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Your recommended order</h2><span class="pill"><?= count($ranked) ?> active <?= count($ranked) === 1 ? 'task' : 'tasks' ?></span></div>
        <p class="muted">Recalculated using the current time. Equal scores are ordered by the earliest deadline.</p>
        <?php if (!$ranked): ?>
            <div class="empty"><h3>A clear starting point</h3><p>Add a task with an importance level and a deadline to see its score and explanation here.</p><a class="btn primary" href="add_task.php">Create your first task</a></div>
        <?php else: ?>
            <ol class="recommendation-list">
            <?php foreach ($ranked as $index => $task): $ai = $task['_ai']; $reminderReviewed = isset($reviewedReminders[(int)$task['task_id']]); $suggestion = $reminderReviewed ? null : ai_suggest_reminder($task, $profile); ?>
                <li class="reminder">
                    <div class="panel-head"><div><p class="eyebrow"><?= $index === 0 ? 'SUGGESTED NEXT' : 'FOCUS ' . ($index + 1) ?></p><h3><?= e($task['title']) ?></h3></div><span class="score <?= e($ai['level']) ?>"><?= (int)round($ai['score']) ?>/100 · <?= e($ai['level']) ?></span></div>
                    <p><?= e($ai['reason']) ?></p>
                    <p class="muted"><?= e($task['category_name'] ?: 'Uncategorised') ?> · <?= e(str_replace('_', ' ', strtolower($task['status']))) ?> · Due <?= e(format_datetime($task['due_at'])) ?></p>
                    <details<?= $index === 0 ? ' open' : '' ?>>
                        <summary>Why this score?</summary>
                        <div class="table-wrap"><table><thead><tr><th scope="col">Factor</th><th scope="col">Points</th><th scope="col">Explanation</th></tr></thead><tbody>
                        <?php foreach ($ai['factors'] as $factor): ?><tr><th scope="row"><?= e($factor['label']) ?></th><td><?= $factor['points'] > 0 ? '+' : '' ?><?= (int)$factor['points'] ?></td><td><?= e($factor['detail']) ?></td></tr><?php endforeach; ?>
                        </tbody><tfoot><tr><th scope="row">Total</th><td><?= (int)round($ai['score']) ?></td><td>0–39 Low · 40–64 Medium · 65–84 High · 85–100 Critical</td></tr></tfoot></table></div>
                    </details>
                    <?php if ($suggestion): ?><p><strong>Suggested reminder:</strong> <?= e(format_datetime($suggestion['reminder_at'])) ?></p><p class="muted"><?= e($suggestion['reason']) ?></p><?php else: ?><p class="muted"><?= $reminderReviewed ? 'A reminder is already scheduled or its AI suggestion has been reviewed. Manage your reminders to see your decision or set a new time.' : (!$profile['notifications_enabled'] ? 'Enable reminders in preferences to see timing suggestions.' : 'No suitable reminder time is available at least 15 minutes from now and before the upcoming deadline while respecting quiet hours.') ?></p><?php endif; ?>
                    <div class="actions"><a class="btn secondary" href="edit_task.php?id=<?= (int)$task['task_id'] ?>">Review task</a><?php if ($suggestion || $reminderReviewed): ?><a class="btn secondary" href="reminders.php?task_id=<?= (int)$task['task_id'] ?>">Review reminders</a><?php endif; ?></div>
                </li>
            <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <section class="panel"><h2>What these recommendations can tell you</h2><p>The assistant can surface urgent work and recurring delays. It cannot judge the quality of your work, estimate task effort, read your calendar or guarantee a better outcome. Completion patterns use up to 200 tasks from the last 90 days. A completion hour is only a rough signal of when a reminder might be useful.</p><p class="muted">This prototype uses explicit rules and small statistical summaries. These priority recommendations do not use a trained predictive model. The separate AI chatbot uses a configured language model when configured.</p></section>
    <section class="panel"><h2>How this can support student life</h2><div class="cards"><div><h3>Balance competing deadlines</h3><p>Compare coursework, revision and everyday tasks in one order. The explanation shows whether urgency, importance or repeated delays drove a recommendation.</p></div><div><h3>Plan reminders around your routine</h3><p>Review a suggested time based on your preferences and recent completion history. Quiet hours help keep study reminders out of your rest time.</p></div><div><h3>Understand and challenge the advice</h3><p>Use the decision lab to test a different deadline or importance. You can disagree with the order and edit your plan; these potential benefits still need evaluation with students.</p></div></div></section>
</main>
<script src="assets/assistant.js?v=<?= (string)filemtime(__DIR__.'/assets/assistant.js') ?>" defer></script>
<?php include 'includes/footer.php'; ?>
