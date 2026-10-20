<?php
// Include this at the top of any protected page (it is also called by includes/header.php).
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Make sure the account still exists and is active on every request.
$stmt = $conn->prepare('SELECT is_active FROM users WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$row = $stmt->fetch();

if (!$row || !$row['is_active']) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}