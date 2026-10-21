<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$types = [
    'damaged'    => ['Damaged',    -1],
    'lost'       => ['Lost',       -1],
    'found'      => ['Found',      +1],
    'correction' => ['Correction',  0],   // user chooses direction
    'other'      => ['Other',       0],
];

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


/* ---------------- Delete a record (admin only) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_admin();
    csrf_check();
    $aid = (int)($_POST['id'] ?? 0);

    $conn->beginTransaction();
    try {
        $st = $conn->prepare(
            'SELECT a.*, p.product_name, sz.code AS size_code
             FROM stock_adjustments a JOIN products p ON p.product_id = a.product_id
             LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
             WHERE a.adjustment_id = ? FOR UPDATE'
        );
        $st->execute([$aid]);
        $a = $st->fetch();

        if (!$a) {
            $conn->rollBack();
            flash('error', 'Record not found (already deleted?).');
        } else {
            $pid = (int)$a['product_id'];
            $loc = $a['location'];
            $change = (int)$a['quantity'];              // what this record did to stock (negative = removed stock)
            $conn->prepare('SELECT product_id FROM products WHERE product_id = ? FOR UPDATE')->execute([$pid]);

            // Deleting undoes it: a damaged record (-1) gives stock back (+1).
            $rev = check_reversal($conn, $pid, $loc, -$change, $a['adjustment_date']);

            if (!$rev['ok']) {
                $conn->rollBack();
                flash('error', pname($a) . ': ' . $rev['message'] . '.');
            } else {
                $conn->prepare('DELETE FROM stock_adjustments WHERE adjustment_id = ?')->execute([$aid]);
                $conn->commit();

                if ($rev['counted']) {
                    flash('success', 'Record deleted. That day was already counted, so current ' . location_label($loc) . ' stock is unchanged; reports are corrected and that day\'s saved count is now out of date.');
                } else {
                    flash('success', 'Record deleted. ' . location_label($loc) . ' stock ' . (-$change > 0 ? '+' : '−') . abs($change) . ' for ' . pname($a) . '.');
                }
            }
        }
    } catch (Throwable $ex) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $ex;
    }
    header('Location: ' . BASE_URL . '/adjustments.php');
    exit;
}

$errors = [];
$form = [
    'adjustment_date' => date('Y-m-d\TH:i'), 'product_id' => '', 'quantity' => '',
    'adjustment_type' => 'damaged', 'location' => 'sale', 'direction' => 'decrease', 'description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    csrf_check();

    $form = [
        'adjustment_date' => $_POST['adjustment_date'] ?? '',
        'product_id'      => (int)($_POST['product_id'] ?? 0),
        'quantity'        => trim($_POST['quantity'] ?? ''),
        'adjustment_type' => $_POST['adjustment_type'] ?? '',
        'location'        => $_POST['location'] ?? '',
        'direction'       => ($_POST['direction'] ?? '') === 'increase' ? 'increase' : 'decrease',
        'description'     => trim($_POST['description'] ?? ''),
    ];

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $form['adjustment_date']);
    if (!$dt) $errors[] = 'Please enter a valid date.';
    if (!isset($validProducts[$form['product_id']])) $errors[] = 'Choose a product.';
    if (!isset($types[$form['adjustment_type']]))     $errors[] = 'Choose an adjustment type.';
    if (!isset(STOCK_LOCATIONS[$form['location']]))   $errors[] = 'Choose WH or Sale.';
    if (!ctype_digit($form['quantity']) || (int)$form['quantity'] < 1) $errors[] = 'Quantity must be a whole number of 1 or more.';

    if (!$errors) {
        $type = $form['adjustment_type'];
        $sign = $types[$type][1];
        if ($sign === 0) {
            $sign = $form['direction'] === 'increase' ? +1 : -1;
            if ($form['description'] === '') $errors[] = 'Please give a reason for a Correction / Other adjustment.';
        }
    }

    if (!$errors) {
        $pid    = $form['product_id'];
        $amount = $sign * (int)$form['quantity'];

        $conn->beginTransaction();
        try {
            $conn->prepare('SELECT product_id FROM products WHERE product_id = ? FOR UPDATE')->execute([$pid]);
            $loc  = $form['location'];
            $have = get_stock($conn, $pid, $loc);
            $closed = get_closed_products($conn, [$pid], $dt->format('Y-m-d'), [$loc]);

            if ($closed) {
                $conn->rollBack();
                $errors[] = closed_error(pname($validProducts[$pid]), $closed[$pid]);
            } elseif ($amount < 0 && abs($amount) > $have) {
                $conn->rollBack();
                $errors[] = pname($validProducts[$pid]) . ": only $have in " . location_label($loc) . " stock (you entered " . abs($amount) . ').';
            } else {
                $conn->prepare('INSERT INTO stock_adjustments (product_id, quantity, adjustment_type, location, description, adjustment_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                     ->execute([$pid, $amount, $type, $loc, $form['description'] !== '' ? $form['description'] : null, $dt->format('Y-m-d H:i:s'), $_SESSION['user_id']]);
                $conn->commit();

                flash('success', $types[$type][0] . ' recorded in ' . location_label($loc) . ': ' . ($amount > 0 ? '+' : '') . $amount . ' x ' . pname($validProducts[$pid]) . '.');
                header('Location: ' . BASE_URL . '/adjustments.php');
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
$ftype = $_GET['type'] ?? '';
$floc  = $_GET['location'] ?? '';

$sql = 'SELECT a.*, p.product_name, s.code AS size_code, u.full_name
        FROM stock_adjustments a
        JOIN products p ON p.product_id = a.product_id
        LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
        JOIN users u ON u.user_id = a.created_by
        WHERE DATE(a.adjustment_date) BETWEEN ? AND ?';
$params = [$from, $to];
if (isset($types[$ftype])) { $sql .= ' AND a.adjustment_type = ?'; $params[] = $ftype; }
if (isset(STOCK_LOCATIONS[$floc])) { $sql .= ' AND a.location = ?'; $params[] = $floc; }
$sql .= ' ORDER BY a.adjustment_date DESC, a.adjustment_id DESC';

$st = $conn->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$wh   = get_stock_map($conn, 'wh');
$sale = get_stock_map($conn, 'sale');

$pageTitle = 'Damaged / Lost / Adjustments';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Record adjustment</h3>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div>
            <label>Date</label>
            <input type="datetime-local" name="adjustment_date" required value="<?= e($form['adjustment_date']) ?>">
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
            <label>Location</label>
            <select name="location">
                <?php foreach (STOCK_LOCATIONS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $form['location'] === $key ? 'selected' : '' ?>><?= e($label) ?> stock</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Type</label>
            <select name="adjustment_type" id="atype">
                <?php foreach ($types as $key => [$label]): ?>
                    <option value="<?= e($key) ?>" <?= $form['adjustment_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="dirWrap" style="display:none">
            <label>Direction</label>
            <select name="direction">
                <option value="decrease" <?= $form['direction'] === 'decrease' ? 'selected' : '' ?>>Decrease stock (&minus;)</option>
                <option value="increase" <?= $form['direction'] === 'increase' ? 'selected' : '' ?>>Increase stock (+)</option>
            </select>
        </div>
        <div>
            <label>Quantity</label>
            <input type="number" name="quantity" min="1" step="1" required value="<?= e($form['quantity']) ?>">
        </div>
        <div>
            <label>Reason / Note</label>
            <input type="text" name="description" placeholder="e.g. Bottle broken" value="<?= e($form['description']) ?>">
        </div>
        <div class="actions"><button class="btn" type="submit">Save</button></div>
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
    <select name="location">
        <option value="">WH and Sale</option>
        <?php foreach (STOCK_LOCATIONS as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $floc === $key ? 'selected' : '' ?>><?= e($label) ?> only</option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<table>
    <thead><tr><th>Date</th><th>Product</th><th>Location</th><th>Type</th><th>Change</th><th>Note</th><th>By</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $neg = $r['quantity'] < 0; ?>
        <tr>
            <td><?= e(date('d-M-Y H:i', strtotime($r['adjustment_date']))) ?></td>
            <td><strong><?= e(pname($r)) ?></strong></td>
            <td><?= e(location_label($r['location'])) ?></td>
            <td><span class="badge <?= in_array($r['adjustment_type'], ['damaged', 'lost'], true) ? 'badge-off' : ($r['adjustment_type'] === 'found' ? '' : 'badge-role') ?>"><?= e(ucfirst($r['adjustment_type'])) ?></span></td>
            <td><strong style="color:<?= $neg ? '#991b1b' : '#166534' ?>"><?= ($neg ? '' : '+') . (int)$r['quantity'] ?></strong></td>
            <td><?= e($r['description']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <?php if (is_admin()): ?>
            <td class="actions">
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this record? The stock change is reversed.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['adjustment_id'] ?>">
                    <button class="link-btn" type="submit" style="color:#b91c1c">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">No adjustments in this period.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<script>
// Direction only matters for Correction / Other; the other types are fixed.
const atype = document.getElementById('atype');
const dirWrap = document.getElementById('dirWrap');
function syncDir() { dirWrap.style.display = (atype.value === 'correction' || atype.value === 'other') ? '' : 'none'; }
atype.addEventListener('change', syncDir);
syncDir();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>