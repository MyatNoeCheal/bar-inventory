<?php
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

csrf_check();

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    flash('error', 'Please enter your username and password.');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$stmt = $conn->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

// Same generic message for every failure, so attackers can't tell which part was wrong.
if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
    flash('error', 'Invalid username or password.');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Upgrade the hash if PHP's default algorithm/cost has changed.
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $new = password_hash($password, PASSWORD_DEFAULT);
    $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
         ->execute([$new, $user['user_id']]);
}

session_regenerate_id(true); // prevent session fixation

$_SESSION['user_id']   = (int)$user['user_id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

header('Location: ' . BASE_URL . '/dashboard.php');
exit;