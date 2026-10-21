<?php
$pageTitle = 'Current Stock';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/stock.php';

$wh   = get_stock_map($conn, 'wh');
$sale = get_stock_map($conn, 'sale');

$products = $conn->query(
    'SELECT p.product_id, p.product_name, p.is_active, c.category_name, s.code AS size_code
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     ORDER BY c.category_name, p.product_name, s.code'
)->fetchAll();

$totWh = 0; $totSale = 0;
?>

<table>
    <thead>
        <tr><th>Product</th><th>Category</th><th>Size</th><th class="num">WH</th><th class="num">Sale</th><th class="num">Total</th></tr>
    </thead>
    <tbody>
    <?php foreach ($products as $p):
        $id = (int)$p['product_id'];
        $w  = $wh[$id] ?? 0;
        $s  = $sale[$id] ?? 0;
        $totWh += $w; $totSale += $s;
    ?>
        <tr>
            <td>
                <strong><?= e($p['product_name']) ?></strong>
                <?php if (!$p['is_active']): ?><span class="badge badge-off">Inactive</span><?php endif; ?>
            </td>
            <td><?= e($p['category_name']) ?></td>
            <td><?= e($p['size_code'] ?? '—') ?></td>
            <td class="num"><strong style="<?= $w < 0 ? 'color:#991b1b' : '' ?>"><?= $w ?></strong></td>
            <td class="num"><strong style="<?= $s <= 0 ? 'color:#991b1b' : '' ?>"><?= $s ?></strong></td>
            <td class="num"><?= $w + $s ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?>
        <tr><td colspan="6" class="muted">No products yet.</td></tr>
    <?php else: ?>
        <tr>
            <th colspan="3" style="text-align:right">Total units</th>
            <th class="num"><?= $totWh ?></th>
            <th class="num"><?= $totSale ?></th>
            <th class="num"><?= $totWh + $totSale ?></th>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<p class="muted" style="margin-top:12px">Sale stock = what is available to sell. Move more from WH on the <a href="transfers.php">Transfer</a> page.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
