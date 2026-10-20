<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/products.php');
    exit;
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$conn->prepare('UPDATE products SET is_active = NOT is_active WHERE product_id = ?')->execute([$id]);

flash('success', 'Product status changed.');
header('Location: ' . BASE_URL . '/products.php');
exit;