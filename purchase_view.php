<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$id = (int)($_GET['id'] ?? 0);

// Void (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();

    $conn->beginTransaction();
    try {
        $st = $conn->prepare("SELECT * FROM purchases WHERE purchase_id = ? AND status = 'completed' FOR UPDATE");
        $st->execute([$id]);
        $pu = $st->fetch();
        if (!$pu) {
            $conn->rollBack();
            flash('error', 'Purchase not found or already voided.');
            header('Location: ' . BASE_URL . '/purchase_view.php?id=' . $id);
            exit;
        }

        $ln = $conn->prepare(
            'SELECT i.product_id, SUM(i.quantity) AS qty, p.product_name, sz.code AS size_code
             FROM purchase_items i
             JOIN products p ON p.product_id = i.product_id
             LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
             WHERE i.purchase_id = ?
             GROUP BY i.product_id, p.product_name, sz.code'
        );
        $ln->execute([$id]);
        $lines = $ln->fetchAll();

        // Lock the products so stock can't change while we check it
        $ids = array_map('intval', array_column($lines, 'product_id'));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $conn->prepare("SELECT product_id FROM products WHERE product_id IN ($in) FOR UPDATE")->execute($ids);
        }

        $closed = get_closed_products($conn, $ids, date('Y-m-d', strtotime($pu['purchase_date'])), ['wh']);
        $errors = [];
        foreach ($lines as $l) {
            $pid   = (int)$l['product_id'];
            $label = $l['product_name'] . ($l['size_code'] ? ' ' . $l['size_code'] : '');
            if (isset($closed[$pid])) {
                $errors[] = $label . ': already counted on ' . date('d-M-Y', strtotime($closed[$pid])) . ', so this purchase can no longer be voided.';
                continue;
            }
            $have = get_stock($conn, $pid, 'wh');
            if ($have < (int)$l['qty']) {
                $errors[] = $label . ': only ' . $have . ' in WH stock but this purchase added ' . (int)$l['qty'] . ', so voiding would make WH stock negative.';
            }
        }

        if ($errors) {
            $conn->rollBack();
            flash('error', implode(' ', $errors));
        } else {
            $conn->prepare("UPDATE purchases SET status = 'voided' WHERE purchase_id = ?")->execute([$id]);
            $conn->commit();
            flash('success', 'Purchase voided. Its items were removed from WH stock.');
        }
    } catch (Throwable $ex) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $ex;
    }
    header('Location: ' . BASE_URL . '/purchase_view.php?id=' . $id);
    exit;
}
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
    <p><strong>Status:</strong>
        <span class="badge <?= $purchase['status'] === 'voided' ? 'badge-off' : '' ?>"><?= e(ucfirst($purchase['status'])) ?></span>
    </p>
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

<div class="actions" style="margin-top:16px">
    <a class="btn btn-secondary" href="purchases.php">← Back to purchases</a>
    <?php if (is_admin() && $purchase['status'] === 'completed'): ?>
        <form method="post" onsubmit="return confirm('Void this purchase? Its items will be removed from WH stock.');">
            <?= csrf_field() ?>
            <button class="btn" style="background:#b91c1c" type="submit">Void Purchase</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>