<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$st = $conn->prepare('SELECT * FROM suppliers WHERE supplier_id = ?');
$st->execute([$id]);
$supplier = $st->fetch();
if (!$supplier) {
    flash('error', 'Supplier not found.');
    header('Location: ' . BASE_URL . '/suppliers.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $supplier = [
        'supplier_id'   => $id,
        'supplier_name' => trim($_POST['supplier_name'] ?? ''),
        'phone'         => trim($_POST['phone'] ?? ''),
        'address'       => trim($_POST['address'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($supplier['supplier_name'] === '') $errors[] = 'Supplier name is required.';

    if (!$errors) {
        $dup = $conn->prepare('SELECT COUNT(*) FROM suppliers WHERE supplier_name = ? AND supplier_id <> ?');
        $dup->execute([$supplier['supplier_name'], $id]);
        if ($dup->fetchColumn() > 0) $errors[] = 'Another supplier with this name already exists.';
    }

    if (!$errors) {
        $n = fn($v) => $v !== '' ? $v : null;
        $conn->prepare('UPDATE suppliers SET supplier_name = ?, phone = ?, address = ?, description = ?, is_active = ? WHERE supplier_id = ?')
             ->execute([
                 $supplier['supplier_name'], $n($supplier['phone']), $n($supplier['address']),
                 $n($supplier['description']), $supplier['is_active'], $id,
             ]);
        flash('success', 'Supplier updated.');
        header('Location: ' . BASE_URL . '/suppliers.php');
        exit;
    }
}

$supplier['phone']       = $supplier['phone'] ?? '';
$supplier['address']     = $supplier['address'] ?? '';
$supplier['description'] = $supplier['description'] ?? '';

$pageTitle   = 'Edit Supplier';
$submitLabel = 'Save Changes';
require_once __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/supplier_form.php';
require_once __DIR__ . '/includes/footer.php';