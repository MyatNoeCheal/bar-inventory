<?php
require_once __DIR__ . '/auth/check_auth.php';

$id = (int)($_GET['id'] ?? 0);

// Void (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $conn->prepare("UPDATE sales SET status = 'voided' WHERE sale_id = ? AND status = 'completed'")->execute([$id]);
    flash('success', 'Sale voided. Its items are back in stock.');
    header('Location: ' . BASE_URL . '/sale_view.php?id=' . $id);
    exit;
}

$st = $conn->prepare('SELECT s.*, u.full_name FROM sales s JOIN users u ON u.user_id = s.created_by WHERE s.sale_id = ?');
$st->execute([$id]);
$sale = $st->fetch();
if (!$sale) {
    flash('error', 'Sale not found.');
    header('Location: ' . BASE_URL . '/sales.php');
    exit;
}

$it = $conn->prepare(
    'SELECT i.*, p.product_name, s.code AS size_code
     FROM sale_items i
     JOIN products p ON p.product_id = i.product_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE i.sale_id = ? ORDER BY i.sale_item_id'
);
$it->execute([$id]);
$items = $it->fetchAll();

$profit = 0;
foreach ($items as $i) $profit += $i['quantity'] * ($i['unit_price'] - $i['unit_cost']);

$pageTitle = 'Sale #' . str_pad($sale['sale_id'], 5, '0', STR_PAD_LEFT);
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <p><strong>Date:</strong> <?= e(date('d-M-Y H:i', strtotime($sale['sale_date']))) ?></p>
    <p><strong>Payment:</strong> <?= e($sale['payment_method']) ?></p>
    <p><strong>Recorded by:</strong> <?= e($sale['full_name']) ?></p>
    <p><strong>Status:</strong>
        <span class="badge <?= $sale['status'] === 'voided' ? 'badge-off' : '' ?>"><?= e(ucfirst($sale['status'])) ?></span>
    </p>
</div>

<table>
    <thead>
        <tr><th>Product</th><th>Qty</th><th>Price</th>
            <?php if (is_admin()): ?><th>Cost</th><?php endif; ?>
            <th>Subtotal</th>
            <?php if (is_admin()): ?><th>Profit</th><?php endif; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i): ?>
        <tr>
            <td><?= e($i['product_name'] . ($i['size_code'] ? ' ' . $i['size_code'] : '')) ?></td>
            <td><?= (int)$i['quantity'] ?></td>
            <td><?= money($i['unit_price']) ?></td>
            <?php if (is_admin()): ?><td><?= money($i['unit_cost']) ?></td><?php endif; ?>
            <td><?= money($i['subtotal']) ?></td>
            <?php if (is_admin()): ?><td><?= money($i['quantity'] * ($i['unit_price'] - $i['unit_cost'])) ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
        <tr>
            <th colspan="<?= is_admin() ? 4 : 3 ?>" style="text-align:right">Total</th>
            <th><?= money($sale['total_amount']) ?></th>
            <?php if (is_admin()): ?><th><?= money($profit) ?></th><?php endif; ?>
        </tr>
    </tbody>
</table>

<div class="actions" style="margin-top:16px">
    <a class="btn btn-secondary" href="sales.php">← Back to sales</a>
    <?php if (is_admin() && $sale['status'] === 'completed'): ?>
        <form method="post" onsubmit="return confirm('Void this sale? Its items will return to stock.');">
            <?= csrf_field() ?>
            <button class="btn" style="background:#b91c1c" type="submit">Void Sale</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>