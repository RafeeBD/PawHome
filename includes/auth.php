<?php
require_once __DIR__ . '/helpers.php';

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function dashboard_url(string $role): string {
    return match ($role) {
        'shelter' => 'shelter.php',
        'vet' => 'vet.php',
        'admin' => 'admin.php',
        default => 'adopter.php',
    };
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'user_id' => (int) $user['user_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function require_role(string ...$roles): array {
    $user = current_user();
    if ($user) {
        $row = db_one('SELECT status, role FROM users WHERE user_id = ?', [$user['user_id']]);
        if (!$row || $row['status'] !== 'active') {
            $_SESSION = [];
            session_destroy();
            $user = null;
        }
    }
    if (!$user) {
        $next = basename($_SERVER['PHP_SELF']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        redirect('login.php?next=' . urlencode($next));
    }
    if (!in_array($user['role'], $roles, true)) {
        redirect(dashboard_url($user['role']));
    }
    return $user;
}

function safe_next(?string $next): ?string {
    if ($next && preg_match('/^[a-z\-]+\.php(\?[^\s]*)?$/i', $next)) {
        return $next;
    }
    return null;
}
