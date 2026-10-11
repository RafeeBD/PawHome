<?php

require_once __DIR__ . '/includes/auth.php';

$me = current_user();
if (!$me) {
    redirect('login.php');
}
$uid = $me['user_id'];
$role = $me['role'];
$isAdmin = $role === 'admin';
$id = (int) ($_GET['id'] ?? 0);
$kind = $_GET['kind'] ?? '';

$owns = fn(int $petId) => (bool) db_value('SELECT COUNT(*) FROM applications WHERE pet_id = ? AND adopter_id = ? AND status = "Completed"', [$petId, $uid]);

$row = null;
$allowed = false;
switch ($kind) {
    case 'userdoc':
        $row = db_one('SELECT user_id, stored_path AS path, original_name AS name FROM user_documents WHERE document_id = ?', [$id]);
        $allowed = $row && ($isAdmin || (int) $row['user_id'] === $uid);
        break;
    case 'application':
        $row = db_one('SELECT a.adopter_id, p.shelter_id, a.document_path AS path, a.document_name AS name
                       FROM applications a JOIN pets p ON p.pet_id = a.pet_id WHERE a.application_id = ?', [$id]);
        $allowed = $row && ($isAdmin || (int) $row['adopter_id'] === $uid || (int) $row['shelter_id'] === $uid);
        break;
    case 'appointment':
        $row = db_one('SELECT adopter_id, vet_id, document_path AS path, document_name AS name FROM appointments WHERE appointment_id = ?', [$id]);
        $allowed = $row && ($isAdmin || (int) $row['adopter_id'] === $uid || (int) $row['vet_id'] === $uid);
        break;
    case 'note':
        $row = db_one('SELECT adopter_id, document_path AS path, document_name AS name FROM health_notes WHERE note_id = ?', [$id]);
        $allowed = $row && ($isAdmin || (int) $row['adopter_id'] === $uid);
        break;
    case 'vaccination':
        $row = db_one('SELECT pet_id, document_path AS path, document_name AS name FROM vaccinations WHERE vaccination_id = ?', [$id]);
        $allowed = $row && ($isAdmin || $role === 'vet' || ($role === 'adopter' && $owns((int) $row['pet_id'])));
        break;
    case 'petdoc':
        $row = db_one('SELECT pet_id, shelter_id, medical_doc_path AS path, medical_doc_name AS name FROM pets WHERE pet_id = ?', [$id]);
        $allowed = $row && ($isAdmin || $role === 'vet' || (int) $row['shelter_id'] === $uid || ($role === 'adopter' && $owns((int) $row['pet_id'])));
        break;
}

$base = realpath(UPLOAD_DIR . '/private');
$full = ($row && $row['path']) ? realpath(__DIR__ . '/' . $row['path']) : false;
if (!$allowed || !$full || !$base || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) {
    http_response_code($row && !$allowed ? 403 : 404);
    exit($row && !$allowed ? 'You do not have permission to view this file.' : 'File not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream';
$name = str_replace(['"', "\r", "\n"], '', $row['name'] ?: basename($full));
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
readfile($full);
