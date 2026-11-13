<?php
require_once __DIR__ . '/icons.php';
$pageTitle = $pageTitle ?? 'SmartTask AI';
$signedIn = !empty($_SESSION['user_id']);
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$navItems = [
    ['dashboard.php', 'grid', 'Overview'],
    ['tasks.php', 'tasks', 'My tasks'],
    ['chatbot.php', 'sparkles', 'AI chatbot'],
    ['assistant.php', 'target', 'Priority insights'],
    ['reminders.php', 'bell', 'Reminders'],
    ['analytics.php', 'chart', 'Analytics'],
    ['history.php', 'history', 'Activity log'],
    ['settings.php', 'settings', 'Settings'],
];
$reminderCount = 0;
if ($signedIn) {
    $noticeQuery = $pdo->prepare("SELECT COUNT(*) FROM reminders r JOIN tasks t ON t.task_id=r.task_id AND t.user_id=r.user_id WHERE r.user_id=? AND r.is_enabled=1 AND r.is_sent=0 AND r.suggestion_status NOT IN ('PENDING','IGNORED') AND t.status IN ('PENDING','IN_PROGRESS')");
    $noticeQuery->execute([(int) $_SESSION['user_id']]);
    $reminderCount = (int) $noticeQuery->fetchColumn();
}
$displayName = trim((string) ($_SESSION['full_name'] ?? 'Your workspace'));
$initials = '';
foreach (array_slice(preg_split('/\s+/', $displayName) ?: [], 0, 2) as $namePart) $initials .= mb_strtoupper(mb_substr($namePart, 0, 1, 'UTF-8'), 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#153b32">
    <meta name="csrf-token" content="<?= e(csrf()) ?>">
    <title><?= e($pageTitle) ?> · SmartTask AI</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= (string) filemtime(__DIR__ . '/../assets/style.css') ?>">
    <link rel="stylesheet" href="assets/professional.css?v=<?= (string) filemtime(__DIR__ . '/../assets/professional.css') ?>">
    <link rel="stylesheet" href="assets/studio.css?v=<?= (string) filemtime(__DIR__ . '/../assets/studio.css') ?>">
</head>
<body class="<?= $signedIn ? 'workspace-body' : 'guest-body' ?>" data-authenticated="<?= $signedIn ? 'true' : 'false' ?>">
<a href="#main-content" class="skip-link">Skip to content</a>
<?php if ($signedIn): ?>
<div class="app-shell">
    <aside class="sidebar" aria-label="Workspace navigation">
        <a class="brand" href="dashboard.php"><span class="brand-mark"><?= icon('sparkles') ?></span><span>SmartTask <em>AI</em></span></a>
        <p class="nav-section-label">WORKSPACE</p>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <?php foreach ($navItems as [$href, $symbol, $label]): $isActive = $currentPage === $href || ($href === 'tasks.php' && in_array($currentPage, ['add_task.php', 'edit_task.php'], true)); ?>
            <a href="<?= e($href) ?>" class="nav-item<?= $isActive ? ' active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= icon($symbol) ?><span><?= e($label) ?></span><?php if ($href === 'reminders.php' && $reminderCount): ?><span class="nav-count"><?= $reminderCount ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-ai"><span class="sidebar-ai-icon"><?= icon('sparkles') ?></span><strong>Make space for<br>your best work.</strong><p>Plan your next step with your personal task assistant.</p><a href="chatbot.php">Talk to your assistant <?= icon('arrow-right') ?></a></div>
            <div class="sidebar-profile"><span class="avatar"><?= e($initials) ?></span><div><strong><?= e($displayName) ?></strong><small>Personal workspace</small></div><form action="logout.php" method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="icon-button logout-button" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button></form></div>
        </div>
    </aside>
    <div class="app-content">
        <header class="topbar">
            <div class="topbar-location"><span class="mobile-brand-mark"><?= icon('sparkles') ?></span><span>Workspace</span><?= icon('chevron-right') ?><strong><?= e($pageTitle) ?></strong></div>
            <div class="topbar-actions"><span class="workspace-status"><span class="status-dot"></span> Your personal workspace</span><a class="icon-button notification-button" href="reminders.php" aria-label="Reminders<?= $reminderCount ? ', ' . $reminderCount . ' scheduled' : '' ?>"><?= icon('bell') ?><?php if ($reminderCount): ?><span class="notification-dot"></span><?php endif; ?></a><a href="settings.php" class="avatar avatar-small" aria-label="Account settings"><?= e($initials) ?></a></div>
            <details class="mobile-nav"><summary class="icon-button" aria-label="Open navigation"><?= icon('menu') ?></summary><nav aria-label="Mobile navigation"><?php foreach ($navItems as [$href, $symbol, $label]): ?><a class="nav-item<?= $currentPage === $href ? ' active' : '' ?>" href="<?= e($href) ?>" <?= $currentPage === $href ? 'aria-current="page"' : '' ?>><?= icon($symbol) ?><?= e($label) ?></a><?php endforeach; ?><form action="logout.php" method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="nav-item" type="submit"><?= icon('logout') ?>Sign out</button></form></nav></details>
        </header>
<?php else: ?>
<header class="guest-topbar"><a class="brand" href="login.php"><span class="brand-mark"><?= icon('sparkles') ?></span><span>SmartTask <em>AI</em></span></a><span class="guest-tagline">Make room for what matters.</span></header>
<?php endif; ?>
<?php if (!empty($_SESSION['flash'])): [$flashType, $flashMessage] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="flash <?= e($flashType) ?>" role="status"><?= icon($flashType === 'error' ? 'info' : 'check') ?><span><?= e($flashMessage) ?></span></div>
<?php endif; ?>
