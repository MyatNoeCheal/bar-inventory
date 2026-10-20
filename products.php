<?php
$pageTitle = 'Products';
require_once __DIR__ . '/includes/header.php';

$q   = trim($_GET['q'] ?? '');
$cat = (int)($_GET['category'] ?? 0);

$sql = 'SELECT p.*, c.category_name, s.code AS size_code, s.name AS size_name
        FROM products p
        JOIN categories c ON c.category_id = p.category_id
        LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
        WHERE 1=1';
$params = [];
if ($q !== '')  { $sql .= ' AND p.product_name LIKE ?'; $params[] = "%$q%"; }
if ($cat > 0)   { $sql .= ' AND p.category_id = ?';     $params[] = $cat; }
$sql .= ' ORDER BY c.category_name, p.product_name, s.code';

$st = $conn->prepare($sql);
$st->execute($params);
$products = $st->fetchAll();

$categories = $conn->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll();
?>

<form method="get" class="toolbar">
    <input type="text" name="q" placeholder="Search product name..." value="<?= e($q) ?>">
    <select name="category">
        <option value="0">All categories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['category_id'] ?>" <?= $cat === (int)$c['category_id'] ? 'selected' : '' ?>>
                <?= e($c['category_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
    <?php if (is_admin()): ?>
        <a class="btn" href="product_add.php">+ Add Product</a>
    <?php endif; ?>
</form>

<table>
    <thead>
        <tr><th>Product</th><th>Category</th><th>Size</th><th>Description</th><th>Status</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <tr>
            <td><strong><?= e($p['product_name']) ?></strong></td>
            <td><?= e($p['category_name']) ?></td>
            <td><?= $p['size_code'] ? e($p['size_name'] . ' (' . $p['size_code'] . ')') : '—' ?></td>
            <td><?= e($p['description']) ?></td>
            <td><span class="badge <?= $p['is_active'] ? '' : 'badge-off' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <?php if (is_admin()): ?>
            <td class="actions">
                <a href="product_edit.php?id=<?= (int)$p['product_id'] ?>">Edit</a>
                <form method="post" action="product_delete.php" style="display:inline"
                      onsubmit="return confirm('<?= $p['is_active'] ? 'Deactivate' : 'Activate' ?> this product?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$p['product_id'] ?>">
                    <button class="link-btn" type="submit"><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?>
        <tr><td colspan="6" class="muted">No products found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>