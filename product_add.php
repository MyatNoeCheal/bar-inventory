<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

$categories = $conn->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
$sizes      = $conn->query('SELECT * FROM size_types WHERE is_active = 1 ORDER BY code')->fetchAll();

$product = ['product_name' => '', 'category_id' => '', 'size_type_id' => '', 'description' => '', 'is_active' => 1];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $product = [
        'product_name' => trim($_POST['product_name'] ?? ''),
        'category_id'  => (int)($_POST['category_id'] ?? 0),
        'size_type_id' => (int)($_POST['size_type_id'] ?? 0) ?: null,
        'description'  => trim($_POST['description'] ?? ''),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($product['product_name'] === '') $errors[] = 'Product name is required.';
    if ($product['category_id'] <= 0)    $errors[] = 'Please choose a category.';

    if (!$errors) {
        $dup = $conn->prepare('SELECT COUNT(*) FROM products WHERE product_name = ? AND category_id = ? AND size_type_id <=> ?');
        $dup->execute([$product['product_name'], $product['category_id'], $product['size_type_id']]);
        if ($dup->fetchColumn() > 0) $errors[] = 'This product (same name, category and size) already exists.';
    }

    if (!$errors) {
        $conn->prepare('INSERT INTO products (product_name, category_id, size_type_id, description, is_active) VALUES (?, ?, ?, ?, ?)')
             ->execute([
                 $product['product_name'], $product['category_id'], $product['size_type_id'],
                 $product['description'] !== '' ? $product['description'] : null, $product['is_active'],
             ]);
        flash('success', 'Product added.');
        header('Location: ' . BASE_URL . '/products.php');
        exit;
    }
}

$pageTitle   = 'Add Product';
$submitLabel = 'Add Product';
require_once __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/product_form.php';
require_once __DIR__ . '/includes/footer.php';