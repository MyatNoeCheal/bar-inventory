<?php
$pageTitle = 'Current Stock';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/stock.php';

$stock = get_stock_map($conn);

$products = $conn->query(
    'SELECT p.product_id, p.product_name, p.is_active, c.category_name, s.code AS size_code
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     ORDER BY c.category_name, p.product_name, s.code'
)->fetchAll();
?>

<table>
    <thead><tr><th>Product</th><th>Category</th><th>Size</th><th>In Stock</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): $qty = $stock[(int)$p['product_id']] ?? 0; ?>
        <tr>
            <td>
                <strong><?= e($p['product_name']) ?></strong>
                <?php if (!$p['is_active']): ?><span class="badge badge-off">Inactive</span><?php endif; ?>
            </td>
            <td><?= e($p['category_name']) ?></td>
            <td><?= e($p['size_code'] ?? '—') ?></td>
            <td><strong style="<?= $qty <= 0 ? 'color:#991b1b' : '' ?>"><?= $qty ?></strong></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>