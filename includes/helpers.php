<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Your session expired or the form was tampered with. Go back, refresh the page and try again.');
    }
}

function logout_button(string $class = 'btn-logout'): string {
    return '<form class="inline-form" method="post" action="logout.php">' . csrf_field()
        . '<button type="submit" class="' . e($class) . '">Log out</button></form>';
}

function flash(string $message): void {
    $_SESSION['flash'] = $message;
}

function take_flash(): ?string {
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}

function flash_html(): string {
    $msg = take_flash();
    if ($msg === null) {
        return '';
    }
    return '<div class="ph-toast" role="status">' . e($msg) . '</div>';
}

function fmt_date(?string $value): string {
    return $value ? date('M j, Y', strtotime($value)) : '—';
}

function fmt_time(?string $value): string {
    return $value ? date('g:i A', strtotime($value)) : '';
}

function fmt_datetime(?string $value, string $joiner = ' · '): string {
    return $value ? fmt_date($value) . $joiner . fmt_time($value) : '—';
}

function age_months(string $birthDate): int {
    $diff = (new DateTime($birthDate))->diff(new DateTime('today'));
    return $diff->y * 12 + $diff->m;
}

function fmt_age(string $birthDate, bool $long = false): string {
    $months = age_months($birthDate);
    if ($months < 12) {
        $months = max($months, 1);
        return $months . ($months === 1 ? ' month' : ' months');
    }
    $years = intdiv($months, 12);
    if ($long) {
        return $years . ($years === 1 ? ' year' : ' years');
    }
    return $years . ($years === 1 ? ' yr' : ' yrs');
}

function initials(string $name): string {
    $name = preg_replace('/^(dr\.?|mr\.?|mrs\.?|ms\.?)\s+/i', '', trim($name));
    $parts = preg_split('/\s+/', $name);
    $out = mb_substr($parts[0] ?? '', 0, 1);
    if (count($parts) > 1) {
        $out .= mb_substr(end($parts), 0, 1);
    }
    return mb_strtoupper($out);
}

function time_ago(?string $value): string {
    if (!$value) {
        return '';
    }
    $secs = time() - strtotime($value);
    if ($secs < 60) return 'now';
    if ($secs < 3600) return intdiv($secs, 60) . 'm';
    if ($secs < 86400) return intdiv($secs, 3600) . 'h';
    if ($secs < 604800) return intdiv($secs, 86400) . 'd';
    return date('M j', strtotime($value));
}

function availability_style(string $status): string {
    return match ($status) {
        'Pending' => 'background:#F3E6C9;color:#8A5A19;',
        'Adopted' => 'background:#E9E1F1;color:#6C4B7A;',
        default => 'background:#DCEEE2;color:#2C6B4A;',
    };
}

function pet_photo_url(?string $path): string {
    return $path ? $path : 'assets/logo.png';
}

function clean(string $key, int $max = 255): string {
    $value = trim((string) ($_POST[$key] ?? ''));
    return mb_substr($value, 0, $max);
}

function valid_name(string $name): bool {
    return (bool) preg_match("/^[\p{L}][\p{L}\s.'\-]{1,99}$/u", $name);
}

function valid_phone(string $phone): bool {
    if (!preg_match('/^\+?[0-9\s\-()]{7,20}$/', $phone)) {
        return false;
    }
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) >= 7 && strlen($digits) <= 15;
}

function password_problem(string $password): ?string {
    if (strlen($password) < 8) return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Za-z]/', $password)) return 'Password must include at least one letter.';
    if (!preg_match('/[0-9]/', $password)) return 'Password must include at least one number.';
    return null;
}

function parse_date(string $value): ?string {
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const DOC_MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function uploaded_files(string $field): array {
    if (empty($_FILES[$field])) {
        return [];
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function upload_problem(array $file, array $allowed): ?string {
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > MAX_UPLOAD_BYTES) {
        return '"' . $file['name'] . '" is larger than 5 MB.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'Upload of "' . $file['name'] . '" failed — please try again.';
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        $kinds = strtoupper(implode(', ', array_unique(array_values($allowed))));
        return '"' . $file['name'] . '" must be one of: ' . $kinds . '.';
    }
    return null;
}

function store_upload(array $file, array $allowed, string $subdir): string {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = $allowed[$mime];
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
    return 'uploads/' . $subdir . '/' . $name;
}

function delete_upload(?string $path): void {
    if ($path && str_starts_with($path, 'uploads/')) {
        $full = __DIR__ . '/../' . $path;
        if (is_file($full)) {
            unlink($full);
        }
    }
}

function dev_footer(): string {
    $year = date('Y');
    return '<footer class="site-dev-footer" style="width:100%!important;margin:36px auto 16px auto!important;padding:16px 10px!important;text-align:center!important;display:block!important;clear:both!important;">'
        . '<p style="margin:0 auto!important;text-align:center!important;font-size:11px!important;color:#9A94A0!important;font-family:\'Inter\',-apple-system,sans-serif!important;letter-spacing:0.04em!important;display:block!important;line-height:1.5!important;">&copy; ' . $year . ' Developed by Mahiduzzaman Rafee</p>'
        . '</footer>';
}

