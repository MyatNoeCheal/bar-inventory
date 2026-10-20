<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['category_name'] ?? '');
    $desc   = trim($_POST['description'] ?? '');

    try {
        if (in_array($action, ['add', 'edit'], true) && $name === '') {
            flash('error', 'Category name is required.');
            header('Location: ' . BASE_URL . '/categories.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        if ($action === 'add') {
            $conn->prepare('INSERT INTO categories (category_name, description) VALUES (?, ?)')
                 ->execute([$name, $desc !== '' ? $desc : null]);
            flash('success', 'Category added.');

        } elseif ($action === 'edit') {
            $active = isset($_POST['is_active']) ? 1 : 0;
            $conn->prepare('UPDATE categories SET category_name = ?, description = ?, is_active = ? WHERE category_id = ?')
                 ->execute([$name, $desc !== '' ? $desc : null, $active, $id]);
            flash('success', 'Category updated.');

        } elseif ($action === 'toggle') {
            $conn->prepare('UPDATE categories SET is_active = NOT is_active WHERE category_id = ?')
                 ->execute([$id]);
            flash('success', 'Status changed.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That category name already exists.');
        } else {
            throw $e;
        }
    }
    header('Location: ' . BASE_URL . '/categories.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = $conn->prepare('SELECT * FROM categories WHERE category_id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}

$rows = $conn->query(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id) AS product_count
     FROM categories c ORDER BY c.category_name'
)->fetchAll();

$pageTitle = 'Categories';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h3><?= $editRow ? 'Edit Category' : 'Add Category' ?></h3>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <input type="hidden" name="id" value="<?= (int)($editRow['category_id'] ?? 0) ?>">

        <div>
            <label>Category Name</label>
            <input type="text" name="category_name" maxlength="50" required
                   value="<?= e($editRow['category_name'] ?? '') ?>">
        </div>
        <div>
            <label>Description</label>
            <input type="text" name="description" maxlength="255"
                   value="<?= e($editRow['description'] ?? '') ?>">
        </div>
        <?php if ($editRow): ?>
            <label class="check"><input type="checkbox" name="is_active" <?= $editRow['is_active'] ? 'checked' : '' ?>> Active</label>
        <?php endif; ?>
        <div class="actions">
            <button class="btn" type="submit"><?= $editRow ? 'Save' : 'Add' ?></button>
            <?php if ($editRow): ?><a class="btn btn-secondary" href="categories.php">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<table>
    <thead><tr><th>Name</th><th>Description</th><th>Products</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['category_name']) ?></td>
            <td><?= e($r['description']) ?></td>
            <td><?= (int)$r['product_count'] ?></td>
            <td><span class="badge <?= $r['is_active'] ? '' : 'badge-off' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="actions">
                <a href="categories.php?edit=<?= (int)$r['category_id'] ?>">Edit</a>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['category_id'] ?>">
                    <button class="link-btn" type="submit"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>