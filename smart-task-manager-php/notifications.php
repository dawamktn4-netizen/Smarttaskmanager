<?php
declare(strict_types=1);
require 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in.']);
    exit;
}
// The user ID is cast to an integer to prevent SQL injection and ensure type safety.
$uid = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'Unsupported request.']);
    exit;
}
// CSRF protection is only necessary for POST requests, as GET requests are idempotent and do not modify server state.
if ($method === 'POST') verify_csrf();
$preferences = user_preferences($pdo, $uid);
$quietStart = substr((string) $preferences['quiet_start'], 0, 5);
$quietEnd = substr((string) $preferences['quiet_end'], 0, 5);
$currentTime = date('H:i');
// A quiet period can cross midnight (for example, 22:00 to 08:00); equal times disable it.
$quiet = $quietStart !== $quietEnd && ($quietStart < $quietEnd
    ? ($currentTime >= $quietStart && $currentTime < $quietEnd)
    : ($currentTime >= $quietStart || $currentTime < $quietEnd));
$deliveryAvailable = (bool) $preferences['notifications_enabled'] && !$quiet;
if ($method === 'GET') {
    // Reading the feed has no delivery side effect. The client acknowledges only after rendering.
    $reminders = [];
    if ($deliveryAvailable) {
        $query = $pdo->prepare("SELECT r.reminder_id, r.task_id, r.message, r.reminder_at, t.title FROM reminders r JOIN tasks t ON t.task_id = r.task_id AND t.user_id = r.user_id WHERE r.user_id = ? AND r.is_enabled = 1 AND r.is_sent = 0 AND r.dismissed_at IS NULL AND r.reminder_at <= NOW() AND r.suggestion_status IN ('NOT_APPLICABLE','ACCEPTED','CHANGED') AND t.status IN ('PENDING','IN_PROGRESS') ORDER BY r.reminder_at, r.reminder_id LIMIT 3");
        $query->execute([$uid]);
        $reminders = $query->fetchAll();
        foreach ($reminders as &$reminder) {
            $reminder['reminder_id'] = (int) $reminder['reminder_id'];
            $reminder['task_id'] = (int) $reminder['task_id'];
        }
        unset($reminder);
    }
    echo json_encode(['reminders' => $reminders, 'paused' => !$preferences['notifications_enabled'], 'quiet' => $quiet], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
// POST: Acknowledging a displayed reminder. The client should not send this if the reminder was not displayed.
if (($_POST['action'] ?? '') !== 'ack') {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported notification action.']);
    exit;
}
// Validate the reminder ID and ensure it belongs to the user and is eligible for acknowledgment.
$reminderId = filter_var($_POST['reminder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$reminderId) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a valid reminder.']);
    exit;
}
// Check if the reminder exists, belongs to the user, and is eligible for acknowledgment.
if (!$deliveryAvailable) {
    echo json_encode(['acknowledged' => false, 'deferred' => true]);
    exit;
}
// Recheck ownership and delivery conditions before marking a displayed reminder as sent, not read by the user.
$query = $pdo->prepare("UPDATE reminders r JOIN tasks t ON t.task_id = r.task_id AND t.user_id = r.user_id SET r.is_sent = 1, r.sent_at = NOW() WHERE r.reminder_id = ? AND r.user_id = ? AND r.is_enabled = 1 AND r.is_sent = 0 AND r.dismissed_at IS NULL AND r.reminder_at <= NOW() AND r.suggestion_status IN ('NOT_APPLICABLE','ACCEPTED','CHANGED') AND t.status IN ('PENDING','IN_PROGRESS')");
$query->execute([$reminderId, $uid]);
echo json_encode(['acknowledged' => $query->rowCount() === 1]);
