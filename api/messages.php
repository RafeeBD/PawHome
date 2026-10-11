<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messages.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$me = current_user();
if (!$me || !in_array($me['role'], ['adopter', 'shelter'], true)
    || db_value('SELECT status FROM users WHERE user_id = ?', [$me['user_id']]) !== 'active') {
    json_out(['error' => 'Please sign in again.'], 401);
}

$otherId = (int) ($_GET['with'] ?? $_POST['with'] ?? 0);
$other = can_message($me, $otherId);
if (!$other) {
    json_out(['error' => 'You can\'t message this account.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        json_out(['error' => 'Type a message first.'], 422);
    }
    if (mb_strlen($body) > 1000) {
        json_out(['error' => 'Messages can be up to 1000 characters.'], 422);
    }
    db_exec('INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)', [$me['user_id'], $otherId, $body]);
}

db_exec('UPDATE messages SET read_at = NOW() WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL', [$otherId, $me['user_id']]);
$rows = db_all('SELECT message_id, sender_id, body, sent_at FROM messages
                WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                ORDER BY sent_at, message_id', [$me['user_id'], $otherId, $otherId, $me['user_id']]);

json_out([
    'name' => $other['name'],
    'messages' => array_map(fn($r) => [
        'id' => (int) $r['message_id'],
        'mine' => (int) $r['sender_id'] === $me['user_id'],
        'body' => $r['body'],
        'time' => fmt_datetime($r['sent_at']),
        'ago' => time_ago($r['sent_at']),
    ], $rows),
]);
