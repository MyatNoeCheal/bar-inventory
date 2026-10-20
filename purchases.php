<?php
$pageTitle = 'Purchases (Stock In)';
require_once __DIR__ . '/includes/header.php';

$from     = $_GET['from'] ?? date('Y-m-01');
$to       = $_GET['to']   ?? date('Y-m-d');
$supplier = (int)($_GET['supplier'] ?? 0);

$sql = 'SELECT p.*, s.supplier_name, u.full_name,
               (SELECT COUNT(*) FROM purchase_items i WHERE i.purchase_id = p.purchase_id) AS item_count
        FROM purchases p
        LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
        JOIN users u ON u.user_id = p.created_by
        WHERE DATE(p.purchase_date) BETWEEN ? AND ?';
$params = [$from, $to];
if ($supplier > 0) { $sql .= ' AND p.supplier_id = ?'; $params[] = $supplier; }
$sql .= ' ORDER BY p.purchase_date DESC, p.purchase_id DESC';

$st = $conn->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$grand = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'completed') $grand += $r['total_amount'];
}
$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name')->fetchAll();
?>

<form method="get" class="toolbar">
    <input type="date" name="from" value="<?= e($from) ?>">
    <input type="date" name="to" value="<?= e($to) ?>">
    <select name="supplier">
        <option value="0">All suppliers</option>
        <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['supplier_id'] ?>" <?= $supplier === (int)$s['supplier_id'] ? 'selected' : '' ?>>
                <?= e($s['supplier_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
    <a class="btn" href="purchase_add.php">+ New Purchase</a>
</form>

<table>
    <thead>
        <tr><th>#</th><th>Date</th><th>Supplier</th><th>Items</th><th>Total</th><th>Status</th><th>By</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): $void = $r['status'] === 'voided'; ?>
        <tr style="<?= $void ? 'opacity:.55' : '' ?>">
            <td><?= str_pad($r['purchase_id'], 5, '0', STR_PAD_LEFT) ?></td>
            <td><?= e(date('d-M-Y H:i', strtotime($r['purchase_date']))) ?></td>
            <td><?= e($r['supplier_name'] ?? '—') ?></td>
            <td><?= (int)$r['item_count'] ?></td>
            <td><?= money($r['total_amount']) ?></td>
            <td><span class="badge <?= $void ? 'badge-off' : '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e($r['full_name']) ?></td>
            <td><a href="purchase_view.php?id=<?= (int)$r['purchase_id'] ?>">View</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">No purchases in this period.</td></tr>
    <?php else: ?>
        <tr><th colspan="4" style="text-align:right">Total (completed)</th><th><?= money($grand) ?></th><th colspan="3"></th></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>