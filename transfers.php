<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

function tname(array $p): string
{
    return $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : '');
}

/* ---------------- Delete a transfer (admin only) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_admin();
    csrf_check();
    $tid = (int)($_POST['id'] ?? 0);

    $conn->beginTransaction();
    try {
        $st = $conn->prepare(
            'SELECT t.*, p.product_name, sz.code AS size_code
             FROM stock_transfers t JOIN products p ON p.product_id = t.product_id
             LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
             WHERE t.transfer_id = ? FOR UPDATE'
        );
        $st->execute([$tid]);
        $t = $st->fetch();

        if (!$t) {
            $conn->rollBack();
            flash('error', 'Transfer not found (already deleted?).');
        } else {
            $pid = (int)$t['product_id'];
            $conn->prepare('SELECT product_id FROM products WHERE product_id = ? FOR UPDATE')->execute([$pid]);
            $day = date('Y-m-d', strtotime($t['transfer_date']));
            $label = tname($t);

            // A transfer touches both stocks, so it can't be undone once either has been counted.
            $closed = get_closed_products($conn, [$pid], $day);
            $back = check_reversal($conn, $pid, 'sale', -(int)$t['quantity'], $t['transfer_date']);

            if ($closed) {
                $conn->rollBack();
                flash('error', $label . ': already counted on ' . date('d-M-Y', strtotime($closed[$pid])) . ', so this transfer can no longer be deleted.');
            } elseif (!$back['ok']) {
                $conn->rollBack();
                flash('error', $label . ': ' . $back['message'] . '.');
            } else {
                $conn->prepare('DELETE FROM stock_transfers WHERE transfer_id = ?')->execute([$tid]);
                $conn->commit();
                flash('success', 'Transfer deleted: ' . (int)$t['quantity'] . ' x ' . $label . ' went back to WH.');
            }
        }
    } catch (Throwable $ex) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $ex;
    }
    header('Location: ' . BASE_URL . '/transfers.php');
    exit;
}

/* ---------------- Products ---------------- */
$products = $conn->query(
    'SELECT p.product_id, p.product_name, sz.code AS size_code, c.category_name
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
     WHERE p.is_active = 1
     ORDER BY c.category_name, p.product_name, sz.code'
)->fetchAll();
$validProducts = array_column($products, null, 'product_id');

$wh   = get_stock_map($conn, 'wh');
$sale = get_stock_map($conn, 'sale');

function transfer_options(array $products, array $wh, array $sale, $selected = ''): string
{
    $html = '<option value="">-- Select product --</option>';
    foreach ($products as $p) {
        $id  = (int)$p['product_id'];
        $sel = ((string)$selected === (string)$id) ? ' selected' : '';
        $html .= '<option value="' . $id . '" data-wh="' . (int)($wh[$id] ?? 0) . '"' . $sel . '>'
               . e(tname($p)) . ' — WH: ' . (int)($wh[$id] ?? 0) . ' | Sale: ' . (int)($sale[$id] ?? 0) . '</option>';
    }
    return $html;
}

/* ---------------- Save a new transfer ---------------- */
$errors  = [];
$dateVal = date('Y-m-d\TH:i');
$note    = '';
$rows    = [['product_id' => '', 'quantity' => '']];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    csrf_check();

    $dateVal = $_POST['transfer_date'] ?? '';
    $note    = trim($_POST['description'] ?? '');
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $dateVal);
    if (!$dt) $errors[] = 'Please enter a valid date.';

    $pids = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];

    $rows = []; $items = []; $wanted = [];
    foreach ($pids as $i => $pidRaw) {
        $pid  = (int)$pidRaw;
        $qtyR = trim($qtys[$i] ?? '');
        if ($pid === 0 && $qtyR === '') continue;

        $rows[] = ['product_id' => $pid ?: '', 'quantity' => $qtyR];
        $line = count($rows);

        if (!isset($validProducts[$pid]))          { $errors[] = "Row $line: choose a product."; continue; }
        if (!ctype_digit($qtyR) || (int)$qtyR < 1) { $errors[] = "Row $line: quantity must be a whole number of 1 or more."; continue; }

        $items[]      = ['product_id' => $pid, 'quantity' => (int)$qtyR];
        $wanted[$pid] = ($wanted[$pid] ?? 0) + (int)$qtyR;
    }
    if (!$items && !$errors) $errors[] = 'Add at least one product.';
    if (!$rows) $rows = [['product_id' => '', 'quantity' => '']];

    if (!$errors) {
        $conn->beginTransaction();
        try {
            $ids = array_keys($wanted);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $conn->prepare("SELECT product_id FROM products WHERE product_id IN ($in) FOR UPDATE")->execute($ids);

            foreach (get_closed_products($conn, $ids, $dt->format('Y-m-d')) as $pid => $d) {
                $errors[] = closed_error(tname($validProducts[$pid]), $d);
            }
            if (!$errors) {
                foreach ($wanted as $pid => $qty) {
                    $have = get_stock($conn, $pid, 'wh');
                    if ($qty > $have) {
                        $errors[] = tname($validProducts[$pid]) . ": only $have in WH (you entered $qty).";
                    }
                }
            }

            if ($errors) {
                $conn->rollBack();
            } else {
                $ins = $conn->prepare('INSERT INTO stock_transfers (product_id, quantity, transfer_date, description, created_by) VALUES (?, ?, ?, ?, ?)');
                foreach ($items as $it) {
                    $ins->execute([$it['product_id'], $it['quantity'], $dt->format('Y-m-d H:i:s'), $note !== '' ? $note : null, $_SESSION['user_id']]);
                }
                $conn->commit();

                flash('success', 'Moved ' . array_sum($wanted) . ' unit(s) from WH to Sale (' . count($wanted) . ' product(s)).');
                header('Location: ' . BASE_URL . '/transfers.php');
                exit;
            }
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
        // stock may have changed; refresh the dropdown numbers
        $wh   = get_stock_map($conn, 'wh');
        $sale = get_stock_map($conn, 'sale');
    }
}

/* ---------------- History ---------------- */
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');

$st = $conn->prepare(
    'SELECT t.*, p.product_name, sz.code AS size_code, u.full_name
     FROM stock_transfers t
     JOIN products p ON p.product_id = t.product_id
     LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
     JOIN users u ON u.user_id = t.created_by
     WHERE DATE(t.transfer_date) BETWEEN ? AND ?
     ORDER BY t.transfer_date DESC, t.transfer_id DESC'
);
$st->execute([$from, $to]);
$history = $st->fetchAll();
$totalQty = array_sum(array_column($history, 'quantity'));

$pageTitle = 'Transfer WH → Sale';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <h3>Move stock from WH to the Sale section</h3>
        <p class="muted" style="margin-top:-8px">Enter only the quantity the Sale section needs. WH goes down and Sale goes up automatically.</p>
        <div class="form-grid">
            <div>
                <label>Date</label>
                <input type="datetime-local" name="transfer_date" required value="<?= e($dateVal) ?>">
            </div>
            <div>
                <label>Note</label>
                <input type="text" name="description" placeholder="optional" value="<?= e($note) ?>">
            </div>
        </div>
    </div>

    <table>
        <thead><tr><th style="width:60%">Product</th><th>Quantity to move</th><th></th></tr></thead>
        <tbody id="items">
        <?php foreach ($rows as $r): ?>
            <tr class="item-row">
                <td><select name="product_id[]"><?= transfer_options($products, $wh, $sale, $r['product_id']) ?></select></td>
                <td><input type="number" name="quantity[]" min="1" step="1" value="<?= e($r['quantity']) ?>"></td>
                <td><button type="button" class="link-btn remove">Remove</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <template id="rowTpl">
        <tr class="item-row">
            <td><select name="product_id[]"><?= transfer_options($products, $wh, $sale) ?></select></td>
            <td><input type="number" name="quantity[]" min="1" step="1"></td>
            <td><button type="button" class="link-btn remove">Remove</button></td>
        </tr>
    </template>

    <div class="actions" style="margin-top:16px">
        <button type="button" class="btn btn-secondary" id="addRow">+ Add Row</button>
        <button type="submit" class="btn">Move to Sale</button>
    </div>
</form>

<h3 style="margin:32px 0 12px">Transfer history</h3>

<form method="get" class="toolbar">
    <input type="date" name="from" value="<?= e($from) ?>">
    <input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<table>
    <thead><tr><th>Date</th><th>Product</th><th class="num">Qty</th><th>Note</th><th>By</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr>
            <td><?= e(date('d-M-Y H:i', strtotime($h['transfer_date']))) ?></td>
            <td><strong><?= e(tname($h)) ?></strong></td>
            <td class="num"><?= (int)$h['quantity'] ?></td>
            <td><?= e($h['description']) ?></td>
            <td><?= e($h['full_name']) ?></td>
            <?php if (is_admin()): ?>
            <td class="actions">
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this transfer? The quantity goes back to WH.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$h['transfer_id'] ?>">
                    <button class="link-btn" type="submit" style="color:#b91c1c">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$history): ?>
        <tr><td colspan="6" class="muted">No transfers in this period.</td></tr>
    <?php else: ?>
        <tr><th colspan="2" style="text-align:right">Total units</th><th class="num"><?= (int)$totalQty ?></th><th colspan="<?= is_admin() ? 3 : 2 ?>"></th></tr>
    <?php endif; ?>
    </tbody>
</table>

<script>
const body = document.getElementById('items');
const tpl  = document.getElementById('rowTpl');

document.getElementById('addRow').addEventListener('click', () => body.appendChild(tpl.content.cloneNode(true)));

// Cap the quantity at what WH has
body.addEventListener('change', e => {
    if (e.target.name !== 'product_id[]') return;
    const qty = e.target.closest('tr').querySelector('[name="quantity[]"]');
    const opt = e.target.selectedOptions[0];
    if (opt && opt.value) qty.max = opt.dataset.wh; else qty.removeAttribute('max');
});

body.addEventListener('click', e => {
    if (e.target.classList.contains('remove') && body.querySelectorAll('.item-row').length > 1) {
        e.target.closest('tr').remove();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
