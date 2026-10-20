<?php
require_once __DIR__ . '/auth/check_auth.php';

$id = (int)($_GET['id'] ?? 0);
$st = $conn->prepare(
    'SELECT p.*, s.supplier_name, u.full_name
     FROM purchases p
     LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
     JOIN users u ON u.user_id = p.created_by
     WHERE p.purchase_id = ?'
);
$st->execute([$id]);
$purchase = $st->fetch();
if (!$purchase) {
    flash('error', 'Purchase not found.');
    header('Location: ' . BASE_URL . '/purchases.php');
    exit;
}

$it = $conn->prepare(
    'SELECT i.*, p.product_name, s.code AS size_code
     FROM purchase_items i
     JOIN products p ON p.product_id = i.product_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE i.purchase_id = ?
     ORDER BY i.purchase_item_id'
);
$it->execute([$id]);
$items = $it->fetchAll();

$pageTitle = 'Purchase #' . str_pad($purchase['purchase_id'], 5, '0', STR_PAD_LEFT);
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <p><strong>Date:</strong> <?= e(date('d-M-Y H:i', strtotime($purchase['purchase_date']))) ?></p>
    <p><strong>Supplier:</strong> <?= e($purchase['supplier_name'] ?? '—') ?></p>
    <p><strong>Recorded by:</strong> <?= e($purchase['full_name']) ?></p>
    <?php if ($purchase['description']): ?>
        <p><strong>Note:</strong> <?= e($purchase['description']) ?></p>
    <?php endif; ?>
</div>

<table>
    <thead><tr><th>Product</th><th>Qty</th><th>Unit Cost</th><th>Subtotal</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
        <tr>
            <td><?= e($i['product_name'] . ($i['size_code'] ? ' ' . $i['size_code'] : '')) ?></td>
            <td><?= (int)$i['quantity'] ?></td>
            <td><?= money($i['unit_price']) ?></td>
            <td><?= money($i['subtotal']) ?></td>
        </tr>
    <?php endforeach; ?>
        <tr>
            <th colspan="3" style="text-align:right">Total</th>
            <th><?= money($purchase['total_amount']) ?></th>
        </tr>
    </tbody>
</table>

<p style="margin-top:16px"><a class="btn btn-secondary" href="purchases.php">← Back to purchases</a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>