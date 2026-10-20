<?php
// Usage on every page:
//   $pageTitle = 'Products';
//   require_once __DIR__ . '/includes/header.php';
//   ... page content ...
//   require_once __DIR__ . '/includes/footer.php';

require_once __DIR__ . '/../auth/check_auth.php';

$pageTitle = $pageTitle ?? 'Bar Inventory';
$flash     = get_flash();
$initial   = strtoupper(mb_substr($_SESSION['full_name'] ?? '?', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - Bar Inventory</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<div class="overlay" id="overlay"></div>
<div class="layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="menu-btn" id="menuBtn" aria-label="Open menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>
                <h2><?= e($pageTitle) ?></h2>
            </div>
            <div class="user">
                <div class="user-meta">
                    <strong><?= e($_SESSION['full_name']) ?></strong>
                    <span><?= e($_SESSION['role']) ?></span>
                </div>
                <div class="avatar"><?= e($initial) ?></div>
                <a class="logout" href="<?= BASE_URL ?>/logout.php">Logout</a>
            </div>
        </header>
        <main class="content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>