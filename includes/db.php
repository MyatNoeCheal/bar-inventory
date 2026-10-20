<?php
// Loads the PDO connection ($conn) and defines BASE_URL (project folder URL).

require_once __DIR__ . '/../connection.php';

if (!defined('BASE_URL')) {
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
    $project = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    define('BASE_URL', rtrim(str_replace($docRoot, '', $project), '/'));
}

date_default_timezone_set('Asia/Yangon');
$conn->exec("SET time_zone = '+06:30'");