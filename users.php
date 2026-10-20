<?php
require_once __DIR__ . '/auth/check_auth.php';
require_admin();

$roles = ['admin' => 'Admin', 'staff' => 'Staff'];
$me    = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    try {
        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $full     = trim($_POST['full_name'] ?? '');
            $role     = $_POST['role'] ?? 'staff';
            $pw       = $_POST['password'] ?? '';

            $err = [];
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) $err[] = 'Username must be 3-50 characters (letters, numbers, . _ -).';
            if ($full === '')                                      $err[] = 'Full name is required.';
            if (!isset($roles[$role]))                             $err[] = 'Choose a valid role.';
            if (strlen($pw) < 8)                                   $err[] = 'Password must be at least 8 characters.';

            if ($err) {
                flash('error', implode(' ', $err));
            } else {
                $conn->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)')
                     ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $full, $role]);
                flash('success', 'User added.');
            }

        } elseif ($action === 'edit') {
            $st = $conn->prepare('SELECT * FROM users WHERE user_id = ?');
            $st->execute([$id]);
            $target = $st->fetch();
            if (!$target) {
                flash('error', 'User not found.');
                header('Location: ' . BASE_URL . '/users.php');
                exit;
            }

            $full = trim($_POST['full_name'] ?? '');
            $pw   = $_POST['password'] ?? '';
            // You can't change your own role or status (prevents locking out the last admin).
            if ($id === $me) {
                $role   = $target['role'];
                $active = 1;
            } else {
                $role   = $_POST['role'] ?? '';
                $active = isset($_POST['is_active']) ? 1 : 0;
            }

            $err = [];
            if ($full === '')                          $err[] = 'Full name is required.';
            if (!isset($roles[$role]))                 $err[] = 'Choose a valid role.';
            if ($pw !== '' && strlen($pw) < 8)         $err[] = 'New password must be at least 8 characters.';

            if ($err) {
                flash('error', implode(' ', $err));
                header('Location: ' . BASE_URL . '/users.php?edit=' . $id);
                exit;
            }

            if ($pw !== '') {
                $conn->prepare('UPDATE users SET full_name = ?, role = ?, is_active = ?, password_hash = ? WHERE user_id = ?')
                     ->execute([$full, $role, $active, password_hash($pw, PASSWORD_DEFAULT), $id]);
            } else {
                $conn->prepare('UPDATE users SET full_name = ?, role = ?, is_active = ? WHERE user_id = ?')
                     ->execute([$full, $role, $active, $id]);
            }
            if ($id === $me) $_SESSION['full_name'] = $full;
            flash('success', 'User updated.');

        } elseif ($action === 'toggle') {
            if ($id === $me) {
                flash('error', 'You cannot deactivate your own account.');
            } else {
                $conn->prepare('UPDATE users SET is_active = NOT is_active WHERE user_id = ?')->execute([$id]);
                flash('success', 'Status changed.');
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash('error', 'That username already exists.');
        } else {
            throw $e;
        }
    }
    header('Location: ' . BASE_URL . '/users.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = $conn->prepare('SELECT * FROM users WHERE user_id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}
$editingSelf = $editRow && (int)$editRow['user_id'] === $me;

$rows = $conn->query('SELECT user_id, username, full_name, role, is_active, created_at FROM users ORDER BY is_active DESC, role, full_name')->fetchAll();

$pageTitle = 'Users';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h3><?= $editRow ? 'Edit User' : 'Add User' ?></h3>
    <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <input type="hidden" name="id" value="<?= (int)($editRow['user_id'] ?? 0) ?>">

        <div>
            <label>Username</label>
            <?php if ($editRow): ?>
                <input type="text" value="<?= e($editRow['username']) ?>" disabled>
            <?php else: ?>
                <input type="text" name="username" maxlength="50" required autocomplete="off">
            <?php endif; ?>
        </div>
        <div>
            <label>Full Name</label>
            <input type="text" name="full_name" maxlength="100" required value="<?= e($editRow['full_name'] ?? '') ?>">
        </div>
        <div>
            <label>Role</label>
            <select name="role" <?= $editingSelf ? 'disabled' : '' ?>>
                <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($editRow['role'] ?? 'staff') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?= $editRow ? 'New Password (leave blank to keep)' : 'Password (min 8 characters)' ?></label>
            <input type="password" name="password" minlength="8" autocomplete="new-password" <?= $editRow ? '' : 'required' ?>>
        </div>

        <?php if ($editRow): ?>
            <label class="check">
                <input type="checkbox" name="is_active" <?= $editRow['is_active'] ? 'checked' : '' ?> <?= $editingSelf ? 'disabled' : '' ?>> Active
            </label>
        <?php endif; ?>

        <div class="actions">
            <button class="btn" type="submit"><?= $editRow ? 'Save' : 'Add User' ?></button>
            <?php if ($editRow): ?><a class="btn btn-secondary" href="users.php">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<table>
    <thead><tr><th>Full Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $self = (int)$r['user_id'] === $me; ?>
        <tr>
            <td><strong><?= e($r['full_name']) ?></strong><?= $self ? ' <span class="muted">(you)</span>' : '' ?></td>
            <td><?= e($r['username']) ?></td>
            <td><span class="badge badge-role"><?= e($roles[$r['role']] ?? $r['role']) ?></span></td>
            <td><span class="badge <?= $r['is_active'] ? '' : 'badge-off' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td><?= e(date('d-M-Y', strtotime($r['created_at']))) ?></td>
            <td class="actions">
                <a href="users.php?edit=<?= (int)$r['user_id'] ?>">Edit</a>
                <?php if (!$self): ?>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?> this user?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['user_id'] ?>">
                    <button class="link-btn" type="submit"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>