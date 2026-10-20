<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

$supplier = ['supplier_name' => '', 'phone' => '', 'address' => '', 'description' => '', 'is_active' => 1];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $supplier = [
        'supplier_name' => trim($_POST['supplier_name'] ?? ''),
        'phone'         => trim($_POST['phone'] ?? ''),
        'address'       => trim($_POST['address'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($supplier['supplier_name'] === '') $errors[] = 'Supplier name is required.';

    if (!$errors) {
        $dup = $conn->prepare('SELECT COUNT(*) FROM suppliers WHERE supplier_name = ?');
        $dup->execute([$supplier['supplier_name']]);
        if ($dup->fetchColumn() > 0) $errors[] = 'A supplier with this name already exists.';
    }

    if (!$errors) {
        $n = fn($v) => $v !== '' ? $v : null;
        $conn->prepare('INSERT INTO suppliers (supplier_name, phone, address, description, is_active) VALUES (?, ?, ?, ?, ?)')
             ->execute([
                 $supplier['supplier_name'], $n($supplier['phone']), $n($supplier['address']),
                 $n($supplier['description']), $supplier['is_active'],
             ]);
        flash('success', 'Supplier added.');
        header('Location: ' . BASE_URL . '/suppliers.php');
        exit;
    }
}

$pageTitle   = 'Add Supplier';
$submitLabel = 'Add Supplier';
require_once __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/supplier_form.php';
require_once __DIR__ . '/includes/footer.php';