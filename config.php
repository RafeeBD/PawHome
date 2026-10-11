<?php

date_default_timezone_set('Asia/Dhaka');

if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'pawhome');

if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/uploads');
if (!defined('MAX_UPLOAD_BYTES')) define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);
