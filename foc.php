<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$products = $conn->query(
    'SELECT p.product_id, p.product_name, s.code AS size_code
     FROM products p LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE p.is_active = 1 ORDER BY p.product_name, s.code'
)->fetchAll();
$validProducts = array_column($products, null, 'product_id');

function pname(array $p): string
{
    return $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : '');
}

$errors = [];
$form = ['foc_date' => date('Y-m-d\TH:i'), 'product_id' => '', 'quantity' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $form = [
        'foc_date'    => $_POST['foc_date'] ?? '',
        'product_id'  => (int)($_POST['product_id'] ?? 0),
        'quantity'    => trim($_POST['quantity'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
    ];

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $form['foc_date']);
    if (!$dt) $errors[] = 'Please enter a valid date.';
    if (!isset($validProducts[$form['product_id']])) $errors[] = 'Choose a product.';
    if (!ctype_digit($form['quantity']) || (int)$form['quantity'] < 1) $errors[] = 'Quantity must be a whole number of 1 or more.';

    if (!$errors) {
        $pid = $form['product_id'];
        $qty = (int)$form['quantity'];

        $conn->beginTransaction();
        try {
            $conn->prepare('SELECT product_id FROM products WHERE product_id = ? FOR UPDATE')->execute([$pid]);
            $have = get_stock($conn, $pid);

            if ($qty > $have) {
                $conn->rollBack();
                $errors[] = pname($validProducts[$pid]) . ": only $have in stock (you entered $qty).";
            } else {
                $conn->prepare('INSERT INTO foc_records (product_id, quantity, foc_date, description, created_by) VALUES (?, ?, ?, ?, ?)')
                     ->execute([$pid, $qty, $dt->format('Y-m-d H:i:s'), $form['description'] !== '' ? $form['description'] : null, $_SESSION['user_id']]);
                $conn->commit();

                flash('success', 'FOC recorded: ' . $qty . ' x ' . pname($validProducts[$pid]) . '.');
                header('Location: ' . BASE_URL . '/foc.php');
                exit;
            }
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
    }
}

// ---- History ----
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

$st = $conn->prepare(
    'SELECT f.*, p.product_name, s.code AS size_code, u.full_name
     FROM foc_records f
     JOIN products p ON p.product_id = f.product_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     JOIN users u ON u.user_id = f.created_by
     WHERE DATE(f.foc_date) BETWEEN ? AND ?
     ORDER BY f.foc_date DESC, f.foc_id DESC'
);
$st->execute([$from, $to]);
$rows = $st->fetchAll();
$totalQty = array_sum(array_column($rows, 'quantity'));

$stock = get_stock_map($conn);

$pageTitle = 'FOC (Free of Charge)';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Record FOC</h3>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div>
            <label>Date</label>
            <input type="datetime-local" name="foc_date" required value="<?= e($form['foc_date']) ?>">
        </div>
        <div>
            <label>Product</label>
            <select name="product_id" required>
                <option value="">-- Select product --</option>
                <?php foreach ($products as $p): $id = (int)$p['product_id']; ?>
                    <option value="<?= $id ?>" <?= (int)$form['product_id'] === $id ? 'selected' : '' ?>>
                        <?= e(pname($p)) ?> — stock: <?= (int)($stock[$id] ?? 0) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Quantity</label>
            <input type="number" name="quantity" min="1" step="1" required value="<?= e($form['quantity']) ?>">
        </div>
        <div>
            <label>Reason / Note</label>
            <input type="text" name="description" placeholder="e.g. Given to customer as promotion" value="<?= e($form['description']) ?>">
        </div>
        <div class="actions"><button class="btn" type="submit">Save FOC</button></div>
    </form>
</div>

<form method="get" class="toolbar">
    <input type="date" name="from" value="<?= e($from) ?>">
    <input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<table>
    <thead><tr><th>Date</th><th>Product</th><th>Qty</th><th>Note</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e(date('d-M-Y H:i', strtotime($r['foc_date']))) ?></td>
            <td><strong><?= e(pname($r)) ?></strong></td>
            <td><?= (int)$r['quantity'] ?></td>
            <td><?= e($r['description']) ?></td>
            <td><?= e($r['full_name']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">No FOC records in this period.</td></tr>
    <?php else: ?>
        <tr><th colspan="2" style="text-align:right">Total units</th><th><?= (int)$totalQty ?></th><th colspan="2"></th></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>