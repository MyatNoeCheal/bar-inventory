<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

// type => [label, stock location it changes, direction]
$types = [
    'received' => ['Received from supplier', 'wh',   +1],
    'given'    => ['Given to customer',      'sale', -1],
];

function pname(array $p): string
{
    return $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : '');
}

/* ---------------- Delete a record (admin only) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_admin();
    csrf_check();
    $fid = (int)($_POST['id'] ?? 0);

    $conn->beginTransaction();
    try {
        $st = $conn->prepare(
            'SELECT f.*, p.product_name, sz.code AS size_code
             FROM foc_records f JOIN products p ON p.product_id = f.product_id
             LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
             WHERE f.foc_id = ? FOR UPDATE'
        );
        $st->execute([$fid]);
        $f = $st->fetch();

        if (!$f) {
            $conn->rollBack();
            flash('error', 'FOC record not found (already deleted?).');
        } else {
            $pid = (int)$f['product_id'];
            $qty = (int)$f['quantity'];
            [, $loc, $dir] = $types[$f['foc_type']];
            $conn->prepare('SELECT product_id FROM products WHERE product_id = ? FOR UPDATE')->execute([$pid]);

            // Deleting reverses the record: received FOC leaves WH again, given FOC goes back to Sale.
            $rev = check_reversal($conn, $pid, $loc, -$dir * $qty, $f['foc_date']);

            if (!$rev['ok']) {
                $conn->rollBack();
                flash('error', pname($f) . ': ' . $rev['message'] . '.');
            } else {
                $conn->prepare('DELETE FROM foc_records WHERE foc_id = ?')->execute([$fid]);
                $conn->commit();

                if ($rev['counted']) {
                    flash('success', 'FOC record deleted. That day was already counted, so current ' . location_label($loc) . ' stock is unchanged; reports are corrected and that day\'s saved count is now out of date.');
                } else {
                    flash('success', 'FOC record deleted. ' . location_label($loc) . ' stock ' . (-$dir > 0 ? '+' : '−') . $qty . ' for ' . pname($f) . '.');
                }
            }
        }
    } catch (Throwable $ex) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $ex;
    }
    header('Location: ' . BASE_URL . '/foc.php');
    exit;
}

/* ---------------- Products ---------------- */
$products = $conn->query(
    'SELECT p.product_id, p.product_name, s.code AS size_code
     FROM products p LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE p.is_active = 1 ORDER BY p.product_name, s.code'
)->fetchAll();
$validProducts = array_column($products, null, 'product_id');

/* ---------------- Save a new record ---------------- */
$errors = [];
$form = ['foc_date' => date('Y-m-d\TH:i'), 'foc_type' => 'given', 'product_id' => '', 'quantity' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    csrf_check();

    $form = [
        'foc_date'    => $_POST['foc_date'] ?? '',
        'foc_type'    => $_POST['foc_type'] ?? '',
        'product_id'  => (int)($_POST['product_id'] ?? 0),
        'quantity'    => trim($_POST['quantity'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
    ];

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $form['foc_date']);
    if (!$dt) $errors[] = 'Please enter a valid date.';
    if (!isset($types[$form['foc_type']])) $errors[] = 'Choose the FOC type.';
    if (!isset($validProducts[$form['product_id']])) $errors[] = 'Choose a product.';
    if (!ctype_digit($form['quantity']) || (int)$form['quantity'] < 1) $errors[] = 'Quantity must be a whole number of 1 or more.';

    if (!$errors) {
        $pid = $form['product_id'];
        $qty = (int)$form['quantity'];
        [, $loc, $dir] = $types[$form['foc_type']];

        $conn->beginTransaction();
        try {
            $conn->prepare('SELECT product_id FROM products WHERE product_id = ? FOR UPDATE')->execute([$pid]);

            $closed = get_closed_products($conn, [$pid], $dt->format('Y-m-d'), [$loc]);
            if ($closed) {
                $errors[] = closed_error(pname($validProducts[$pid]), $closed[$pid]);
            } elseif ($dir < 0) {
                $have = get_stock($conn, $pid, $loc);
                if ($qty > $have) {
                    $errors[] = pname($validProducts[$pid]) . ": only $have in " . location_label($loc) . " stock (you entered $qty)."
                              . ($loc === 'sale' ? ' Move more from WH on the Transfer page.' : '');
                }
            }

            if ($errors) {
                $conn->rollBack();
            } else {
                $conn->prepare('INSERT INTO foc_records (product_id, quantity, foc_type, foc_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                     ->execute([$pid, $qty, $form['foc_type'], $dt->format('Y-m-d H:i:s'), $form['description'] !== '' ? $form['description'] : null, $_SESSION['user_id']]);
                $conn->commit();

                flash('success', 'FOC recorded: ' . $qty . ' x ' . pname($validProducts[$pid]) . ' (' . $types[$form['foc_type']][0] . ', ' . location_label($loc) . ' stock ' . ($dir > 0 ? '+' : '−') . $qty . ').');
                header('Location: ' . BASE_URL . '/foc.php');
                exit;
            }
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
    }
}

/* ---------------- History ---------------- */
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-d');
$ftype = $_GET['type'] ?? '';

$sql = 'SELECT f.*, p.product_name, s.code AS size_code, u.full_name
        FROM foc_records f
        JOIN products p ON p.product_id = f.product_id
        LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
        JOIN users u ON u.user_id = f.created_by
        WHERE DATE(f.foc_date) BETWEEN ? AND ?';
$params = [$from, $to];
if (isset($types[$ftype])) { $sql .= ' AND f.foc_type = ?'; $params[] = $ftype; }
$sql .= ' ORDER BY f.foc_date DESC, f.foc_id DESC';
$st = $conn->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$recv = 0; $give = 0;
foreach ($rows as $r) { if ($r['foc_type'] === 'received') $recv += $r['quantity']; else $give += $r['quantity']; }

$wh   = get_stock_map($conn, 'wh');
$sale = get_stock_map($conn, 'sale');

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
            <label>Type</label>
            <select name="foc_type">
                <option value="given"    <?= $form['foc_type'] === 'given'    ? 'selected' : '' ?>>Given to customer (Sale stock −)</option>
                <option value="received" <?= $form['foc_type'] === 'received' ? 'selected' : '' ?>>Received from supplier (WH stock +)</option>
            </select>
        </div>
        <div>
            <label>Product</label>
            <select name="product_id" required>
                <option value="">-- Select product --</option>
                <?php foreach ($products as $p): $id = (int)$p['product_id']; ?>
                    <option value="<?= $id ?>" <?= (int)$form['product_id'] === $id ? 'selected' : '' ?>>
                        <?= e(pname($p)) ?> — WH: <?= (int)($wh[$id] ?? 0) ?> | Sale: <?= (int)($sale[$id] ?? 0) ?>
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
            <input type="text" name="description" placeholder="e.g. Promotion, or free bottles from supplier" value="<?= e($form['description']) ?>">
        </div>
        <div class="actions"><button class="btn" type="submit">Save FOC</button></div>
    </form>
</div>

<form method="get" class="toolbar">
    <input type="date" name="from" value="<?= e($from) ?>">
    <input type="date" name="to" value="<?= e($to) ?>">
    <select name="type">
        <option value="">All types</option>
        <?php foreach ($types as $key => [$label]): ?>
            <option value="<?= e($key) ?>" <?= $ftype === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<table>
    <thead><tr><th>Date</th><th>Type</th><th>Product</th><th class="num">Qty</th><th>Note</th><th>By</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $recvType = $r['foc_type'] === 'received'; ?>
        <tr>
            <td><?= e(date('d-M-Y H:i', strtotime($r['foc_date']))) ?></td>
            <td><span class="badge <?= $recvType ? '' : 'badge-role' ?>"><?= e($types[$r['foc_type']][0]) ?></span></td>
            <td><strong><?= e(pname($r)) ?></strong></td>
            <td class="num"><?= (int)$r['quantity'] ?></td>
            <td><?= e($r['description']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <?php if (is_admin()): ?>
            <td class="actions">
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this FOC record? The stock change is reversed.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['foc_id'] ?>">
                    <button class="link-btn" type="submit" style="color:#b91c1c">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">No FOC records in this period.</td></tr>
    <?php else: ?>
        <tr>
            <th colspan="3" style="text-align:right">Received <?= (int)$recv ?> &middot; Given <?= (int)$give ?></th>
            <th class="num"><?= (int)($recv + $give) ?></th>
            <th colspan="<?= is_admin() ? 3 : 2 ?>"></th>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
