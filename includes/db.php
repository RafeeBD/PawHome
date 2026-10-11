<?php
require_once __DIR__ . '/../config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function get_db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        mysqli_set_charset($conn, 'utf8mb4');
        
        mysqli_query($conn, "SET time_zone = '" . date('P') . "'");
    }
    return $conn;
}

function db_run(string $sql, array $params = []): mysqli_stmt {
    $stmt = mysqli_prepare(get_db(), $sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function db_all(string $sql, array $params = []): array {
    $result = mysqli_stmt_get_result(db_run($sql, $params));
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function db_one(string $sql, array $params = []): ?array {
    $result = mysqli_stmt_get_result(db_run($sql, $params));
    return mysqli_fetch_assoc($result) ?: null;
}

function db_value(string $sql, array $params = []) {
    $row = db_one($sql, $params);
    return $row ? reset($row) : null;
}

function db_exec(string $sql, array $params = []): int {
    db_run($sql, $params);
    return (int) mysqli_insert_id(get_db());
}
