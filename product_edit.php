<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$st = $conn->prepare('SELECT * FROM products WHERE product_id = ?');
$st->execute([$id]);
$product = $st->fetch();
if (!$product) {
    flash('error', 'Product not found.');
    header('Location: ' . BASE_URL . '/products.php');
    exit;
}

// Active ones, plus the product's current category/size even if now inactive
$cs = $conn->prepare('SELECT * FROM categories WHERE is_active = 1 OR category_id = ? ORDER BY category_name');
$cs->execute([$product['category_id']]);
$categories = $cs->fetchAll();

$ss = $conn->prepare('SELECT * FROM size_types WHERE is_active = 1 OR size_type_id <=> ? ORDER BY code');
$ss->execute([$product['size_type_id']]);
$sizes = $ss->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $product = [
        'product_id'   => $id,
        'product_name' => trim($_POST['product_name'] ?? ''),
        'category_id'  => (int)($_POST['category_id'] ?? 0),
        'size_type_id' => (int)($_POST['size_type_id'] ?? 0) ?: null,
        'description'  => trim($_POST['description'] ?? ''),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($product['product_name'] === '') $errors[] = 'Product name is required.';
    if ($product['category_id'] <= 0)    $errors[] = 'Please choose a category.';

    if (!$errors) {
        $dup = $conn->prepare('SELECT COUNT(*) FROM products WHERE product_name = ? AND category_id = ? AND size_type_id <=> ? AND product_id <> ?');
        $dup->execute([$product['product_name'], $product['category_id'], $product['size_type_id'], $id]);
        if ($dup->fetchColumn() > 0) $errors[] = 'Another product with the same name, category and size already exists.';
    }

    if (!$errors) {
        $conn->prepare('UPDATE products SET product_name = ?, category_id = ?, size_type_id = ?, description = ?, is_active = ? WHERE product_id = ?')
             ->execute([
                 $product['product_name'], $product['category_id'], $product['size_type_id'],
                 $product['description'] !== '' ? $product['description'] : null, $product['is_active'], $id,
             ]);
        flash('success', 'Product updated.');
        header('Location: ' . BASE_URL . '/products.php');
        exit;
    }
}

$product['description'] = $product['description'] ?? '';

$pageTitle   = 'Edit Product';
$submitLabel = 'Save Changes';
require_once __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/product_form.php';
require_once __DIR__ . '/includes/footer.php';