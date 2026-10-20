<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $name   = trim($_POST['name'] ?? '');
    $unit   = trim($_POST['unit'] ?? '') ?: 'piece';
    $desc   = trim($_POST['description'] ?? '');

    try {
        if (in_array($action, ['add', 'edit'], true) && ($code === '' || $name === '')) {
            flash('error', 'Code and name are required.');
            header('Location: ' . BASE_URL . '/sizes.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        if ($action === 'add') {
            $conn->prepare('INSERT INTO size_types (code, name, unit, description) VALUES (?, ?, ?, ?)')
                 ->execute([$code, $name, $unit, $desc !== '' ? $desc : null]);
            flash('success', 'Size added.');

        } elseif ($action === 'edit') {
            $active = isset($_POST['is_active']) ? 1 : 0;
            $conn->prepare('UPDATE size_types SET code = ?, name = ?, unit = ?, description = ?, is_active = ? WHERE size_type_id = ?')
                 ->execute([$code, $name, $unit, $desc !== '' ? $desc : null, $active, $id]);
            flash('success', 'Size updated.');

        } elseif ($action === 'toggle') {
            $conn->prepare('UPDATE size_types SET is_active = NOT is_active WHERE size_type_id = ?')
                 ->execute([$id]);
            flash('success', 'Status changed.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That size code already exists.');
        } else {
            throw $e;
        }
    }
    header('Location: ' . BASE_URL . '/sizes.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = $conn->prepare('SELECT * FROM size_types WHERE size_type_id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}

$rows = $conn->query(
    'SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.size_type_id = s.size_type_id) AS product_count
     FROM size_types s ORDER BY s.code'
)->fetchAll();

$pageTitle = 'Sizes';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h3><?= $editRow ? 'Edit Size' : 'Add Size' ?></h3>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <input type="hidden" name="id" value="<?= (int)($editRow['size_type_id'] ?? 0) ?>">

        <div><label>Code (e.g. 1L, B-S)</label>
            <input type="text" name="code" maxlength="20" required value="<?= e($editRow['code'] ?? '') ?>"></div>
        <div><label>Name</label>
            <input type="text" name="name" maxlength="50" required value="<?= e($editRow['name'] ?? '') ?>"></div>
        <div><label>Unit</label>
            <input type="text" name="unit" maxlength="20" value="<?= e($editRow['unit'] ?? 'piece') ?>"></div>
        <div><label>Description</label>
            <input type="text" name="description" maxlength="255" value="<?= e($editRow['description'] ?? '') ?>"></div>

        <?php if ($editRow): ?>
            <label class="check"><input type="checkbox" name="is_active" <?= $editRow['is_active'] ? 'checked' : '' ?>> Active</label>
        <?php endif; ?>
        <div class="actions">
            <button class="btn" type="submit"><?= $editRow ? 'Save' : 'Add' ?></button>
            <?php if ($editRow): ?><a class="btn btn-secondary" href="sizes.php">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<table>
    <thead><tr><th>Code</th><th>Name</th><th>Unit</th><th>Products</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= e($r['code']) ?></strong></td>
            <td><?= e($r['name']) ?></td>
            <td><?= e($r['unit']) ?></td>
            <td><?= (int)$r['product_count'] ?></td>
            <td><span class="badge <?= $r['is_active'] ? '' : 'badge-off' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="actions">
                <a href="sizes.php?edit=<?= (int)$r['size_type_id'] ?>">Edit</a>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['size_type_id'] ?>">
                    <button class="link-btn" type="submit"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>