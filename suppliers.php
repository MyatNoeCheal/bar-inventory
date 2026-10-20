<?php
require_once __DIR__ . '/auth/check_auth.php';

// Quick activate/deactivate (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $conn->prepare('UPDATE suppliers SET is_active = NOT is_active WHERE supplier_id = ?')->execute([$id]);
    flash('success', 'Supplier status changed.');
    header('Location: ' . BASE_URL . '/suppliers.php');
    exit;
}

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM suppliers';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE supplier_name LIKE ? OR phone LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY supplier_name';
$st = $conn->prepare($sql);
$st->execute($params);
$suppliers = $st->fetchAll();

$pageTitle = 'Suppliers';
require_once __DIR__ . '/includes/header.php';
?>

<form method="get" class="toolbar">
    <input type="text" name="q" placeholder="Search name or phone..." value="<?= e($q) ?>">
    <button class="btn" type="submit">Search</button>
    <?php if (is_admin()): ?>
        <a class="btn" href="supplier_add.php">+ Add Supplier</a>
    <?php endif; ?>
</form>

<table>
    <thead>
        <tr><th>Name</th><th>Phone</th><th>Address</th><th>Description</th><th>Status</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
        <tr>
            <td><strong><?= e($s['supplier_name']) ?></strong></td>
            <td><?= e($s['phone']) ?></td>
            <td><?= e($s['address']) ?></td>
            <td><?= e($s['description']) ?></td>
            <td><span class="badge <?= $s['is_active'] ? '' : 'badge-off' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <?php if (is_admin()): ?>
            <td class="actions">
                <a href="supplier_edit.php?id=<?= (int)$s['supplier_id'] ?>">Edit</a>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$s['supplier_id'] ?>">
                    <button class="link-btn" type="submit"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$suppliers): ?>
        <tr><td colspan="6" class="muted">No suppliers found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>