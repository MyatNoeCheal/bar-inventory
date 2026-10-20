<?php
$pageTitle = 'Sales (Stock Out)';
require_once __DIR__ . '/includes/header.php';

$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

$st = $conn->prepare(
    'SELECT s.*, u.full_name,
            (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.sale_id) AS item_count,
            (SELECT SUM(i.quantity * (i.unit_price - i.unit_cost)) FROM sale_items i WHERE i.sale_id = s.sale_id) AS profit
     FROM sales s
     JOIN users u ON u.user_id = s.created_by
     WHERE DATE(s.sale_date) BETWEEN ? AND ?
     ORDER BY s.sale_date DESC, s.sale_id DESC'
);
$st->execute([$from, $to]);
$rows = $st->fetchAll();

$sumTotal = 0; $sumProfit = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'completed') {
        $sumTotal  += $r['total_amount'];
        $sumProfit += $r['profit'];
    }
}
?>

<form method="get" class="toolbar">
    <input type="date" name="from" value="<?= e($from) ?>">
    <input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn" type="submit">Filter</button>
    <a class="btn" href="sale_add.php">+ New Sale</a>
</form>

<table>
    <thead>
        <tr><th>Sale #</th><th>Date</th><th>Items</th><th>Payment</th><th>Total</th>
            <?php if (is_admin()): ?><th>Profit</th><?php endif; ?>
            <th>Status</th><th>By</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): $void = $r['status'] === 'voided'; ?>
        <tr style="<?= $void ? 'opacity:.55' : '' ?>">
            <td><?= str_pad($r['sale_id'], 5, '0', STR_PAD_LEFT) ?></td>
            <td><?= e(date('d-M-Y H:i', strtotime($r['sale_date']))) ?></td>
            <td><?= (int)$r['item_count'] ?></td>
            <td><?= e($r['payment_method']) ?></td>
            <td><?= money($r['total_amount']) ?></td>
            <?php if (is_admin()): ?><td><?= money($r['profit']) ?></td><?php endif; ?>
            <td><span class="badge <?= $void ? 'badge-off' : '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td><?= e($r['full_name']) ?></td>
            <td><a href="sale_view.php?id=<?= (int)$r['sale_id'] ?>">View</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="9" class="muted">No sales in this period.</td></tr>
    <?php else: ?>
        <tr>
            <th colspan="4" style="text-align:right">Total (completed)</th>
            <th><?= money($sumTotal) ?></th>
            <?php if (is_admin()): ?><th><?= money($sumProfit) ?></th><?php endif; ?>
            <th colspan="3"></th>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>