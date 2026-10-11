<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/slots.php';

header('Content-Type: application/json; charset=utf-8');

$me = current_user();
if (!$me || $me['role'] !== 'adopter') {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}

$vetId = (int) ($_GET['vet_id'] ?? 0);
if (!db_value('SELECT COUNT(*) FROM users WHERE user_id = ? AND role = "vet" AND status = "active"', [$vetId])) {
    http_response_code(404);
    echo json_encode(['error' => 'Vet not found.']);
    exit;
}

echo json_encode(['slots' => vet_slots($vetId, $me['user_id'])]);
