<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Bar Inventory</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/login.css">
</head>
<body class="login-body">

    <section class="login-hero">
        <div class="brand">
            <div class="brand-mark">&#127864;</div> Bar Inventory
        </div>
        <div>
            <h2>Know exactly what's on your shelves.</h2>
            <p>Track purchases, sales and stock every day, and see your real profit.</p>
            <ul>
                <li>Purchases and sales with price history</li>
                <li>Daily stock count with difference check</li>
                <li>Profit and monthly reports</li>
            </ul>
        </div>
        <div class="copy">&copy; <?= date('Y') ?> Bar Inventory</div>
    </section>

    <section class="login-panel">
        <form class="login-card" method="post" action="<?= BASE_URL ?>/auth/login_process.php">
            <h1>Welcome back</h1>
            <p class="muted">Sign in to continue</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <?= csrf_field() ?>

            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus autocomplete="username">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">

            <button type="submit" class="btn">Sign in</button>
        </form>
    </section>

</body>
</html>