<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line: php db/add_demo_data.php');
}

require_once __DIR__ . '/seed_helpers.php';
require_once __DIR__ . '/demo_extra.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '" . date('P') . "'");

foreach (['pets', 'private'] as $d) {
    if (!is_dir(UPLOAD_DIR . "/$d")) {
        mkdir(UPLOAD_DIR . "/$d", 0775, true);
    }
}

seed_demo_extra($conn);
