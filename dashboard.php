<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/stock.php';

$lowLimit = 5;               // products with this many units or fewer are "low"
$today    = date('Y-m-d');

// Today's sales
$st = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) AS t, COUNT(*) AS c FROM sales WHERE status = 'completed' AND DATE(sale_date) = ?");
$st->execute([$today]);
$salesToday = $st->fetch();

// Today's purchases
$st = $conn->prepare('SELECT COALESCE(SUM(total_amount),0) AS t, COUNT(*) AS c FROM purchases WHERE DATE(purchase_date) = ?');
$st->execute([$today]);
$purchToday = $st->fetch();

// Today's profit (uses the cost saved on each sale line)
$st = $conn->prepare(
    "SELECT COALESCE(SUM(i.quantity * (i.unit_price - i.unit_cost)),0)
     FROM sale_items i JOIN sales s ON s.sale_id = i.sale_id
     WHERE s.status = 'completed' AND DATE(s.sale_date) = ?"
);
$st->execute([$today]);
$profitToday = $st->fetchColumn();

// Stock
$stock = get_stock_map($conn);
$products = $conn->query(
    'SELECT p.product_id, p.product_name, s.code AS size_code
     FROM products p LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE p.is_active = 1 ORDER BY p.product_name'
)->fetchAll();

$totalUnits = 0;
$low = [];
foreach ($products as $p) {
    $q = $stock[(int)$p['product_id']] ?? 0;
    $totalUnits += max($q, 0);
    if ($q <= $lowLimit) {
        $low[] = ['name' => $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : ''), 'qty' => $q];
    }
}
usort($low, fn($a, $b) => $a['qty'] <=> $b['qty']);

// Recent sales
$recent = $conn->query(
    "SELECT s.sale_id, s.sale_date, s.total_amount, s.payment_method,
            (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id = s.sale_id) AS item_count
     FROM sales s WHERE s.status = 'completed'
     ORDER BY s.sale_date DESC, s.sale_id DESC LIMIT 5"
)->fetchAll();
?>

<p class="muted" style="margin-top:0">Today, <?= e(date('l, d F Y')) ?></p>

<div class="stat-grid">
    <div class="stat-card green">
        <div class="label">Sales today</div>
        <div class="value"><?= money($salesToday['t']) ?></div>
        <div class="sub"><?= (int)$salesToday['c'] ?> sale(s)</div>
    </div>

    <div class="stat-card amber">
        <div class="label">Purchases today</div>
        <div class="value"><?= money($purchToday['t']) ?></div>
        <div class="sub"><?= (int)$purchToday['c'] ?> purchase(s)</div>
    </div>

    <?php if (is_admin()): ?>
    <div class="stat-card violet">
        <div class="label">Profit today</div>
        <div class="value good"><?= money($profitToday) ?></div>
        <div class="sub">Sales minus cost of goods</div>
    </div>
    <?php endif; ?>

    <div class="stat-card">
        <div class="label">Units in stock</div>
        <div class="value"><?= number_format($totalUnits) ?></div>
        <div class="sub"><?= count($products) ?> active products</div>
    </div>
</div>

<div class="dash-grid">
    <div class="card flush">
        <h3>Low stock <span class="muted" style="font-weight:400">(<?= (int)$lowLimit ?> or fewer)</span></h3>
        <?php if ($low): ?>
            <table>
                <thead><tr><th>Product</th><th>In stock</th></tr></thead>
                <tbody>
                <?php foreach ($low as $l): ?>
                    <tr>
                        <td><?= e($l['name']) ?></td>
                        <td><span class="badge <?= $l['qty'] <= 0 ? 'badge-off' : '' ?>"><?= (int)$l['qty'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty">Everything is well stocked.</div>
        <?php endif; ?>
    </div>

    <div class="card flush">
        <h3>Recent sales</h3>
        <?php if ($recent): ?>
            <table>
                <thead><tr><th>#</th><th>Date</th><th>Items</th><th>Total</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= str_pad($r['sale_id'], 5, '0', STR_PAD_LEFT) ?></td>
                        <td><?= e(date('d-M H:i', strtotime($r['sale_date']))) ?></td>
                        <td><?= (int)$r['item_count'] ?></td>
                        <td><strong><?= money($r['total_amount']) ?></strong></td>
                        <td><a href="sale_view.php?id=<?= (int)$r['sale_id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty">No sales yet. <a href="sale_add.php">Record the first one</a>.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>