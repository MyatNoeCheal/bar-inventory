<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$suppliers = $conn->query('SELECT supplier_id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll();
$products  = $conn->query(
    'SELECT p.product_id, p.product_name, s.code AS size_code, c.category_name
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE p.is_active = 1
     ORDER BY c.category_name, p.product_name, s.code'
)->fetchAll();

$validProducts = array_column($products, 'product_id', 'product_id');

$errors   = [];
$supplierId = '';
$dateVal  = date('Y-m-d\TH:i');
$desc     = '';
$rows     = [['product_id' => '', 'quantity' => '', 'unit_price' => '']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $desc       = trim($_POST['description'] ?? '');
    $dateVal    = $_POST['purchase_date'] ?? '';

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $dateVal);
    if (!$dt) $errors[] = 'Please enter a valid purchase date.';

    if ($supplierId > 0) {
        $chk = $conn->prepare('SELECT COUNT(*) FROM suppliers WHERE supplier_id = ?');
        $chk->execute([$supplierId]);
        if (!$chk->fetchColumn()) $errors[] = 'Selected supplier does not exist.';
    }

    $pids   = $_POST['product_id'] ?? [];
    $qtys   = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $rows  = [];
    $items = [];
    $total = 0;

    foreach ($pids as $i => $pidRaw) {
        $pid   = (int)$pidRaw;
        $qtyR  = trim($qtys[$i] ?? '');
        $prcR  = trim($prices[$i] ?? '');

        if ($pid === 0 && $qtyR === '' && $prcR === '') continue; // blank row

        $rows[] = ['product_id' => $pid ?: '', 'quantity' => $qtyR, 'unit_price' => $prcR];
        $line = $i + 1;

        if (!isset($validProducts[$pid]))                       { $errors[] = "Row $line: choose a product."; continue; }
        if (!ctype_digit($qtyR) || (int)$qtyR < 1)              { $errors[] = "Row $line: quantity must be a whole number of 1 or more."; continue; }
        if (!is_numeric($prcR) || (float)$prcR < 0)             { $errors[] = "Row $line: unit cost must be 0 or more."; continue; }

        $items[] = ['product_id' => $pid, 'quantity' => (int)$qtyR, 'unit_price' => round((float)$prcR, 2)];
        $total  += (int)$qtyR * round((float)$prcR, 2);
    }

    if (!$items && !$errors) $errors[] = 'Add at least one product.';
    if (!$rows) $rows = [['product_id' => '', 'quantity' => '', 'unit_price' => '']];

    // A day that has already been counted is closed for that product
    if (!$errors) {
        $names = [];
        foreach ($products as $p) {
            $names[(int)$p['product_id']] = $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : '');
        }
        foreach (get_closed_products($conn, array_column($items, 'product_id'), $dt->format('Y-m-d'), ['wh']) as $pid => $d) {
            $errors[] = $names[$pid] . ': already counted on ' . date('d-M-Y', strtotime($d)) . ', so that date is closed. Use a later date.';
        }
    }

    if (!$errors) {
        $conn->beginTransaction();
        try {
            $conn->prepare('INSERT INTO purchases (supplier_id, purchase_date, total_amount, description, created_by) VALUES (?, ?, ?, ?, ?)')
                 ->execute([
                     $supplierId ?: null,
                     $dt->format('Y-m-d H:i:s'),
                     $total,
                     $desc !== '' ? $desc : null,
                     $_SESSION['user_id'],
                 ]);
            $purchaseId = (int)$conn->lastInsertId();

            $ins = $conn->prepare('INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
            foreach ($items as $it) {
                $ins->execute([$purchaseId, $it['product_id'], $it['quantity'], $it['unit_price']]);
            }
            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollBack();
            throw $ex;
        }

        flash('success', 'Purchase recorded. Total: ' . money($total));
        header('Location: ' . BASE_URL . '/purchase_view.php?id=' . $purchaseId);
        exit;
    }
}

function product_options(array $products, $selected = ''): string
{
    $html = '<option value="">-- Select product --</option>';
    foreach ($products as $p) {
        $label = $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : '') . ' (' . $p['category_name'] . ')';
        $sel   = ((string)$selected === (string)$p['product_id']) ? ' selected' : '';
        $html .= '<option value="' . (int)$p['product_id'] . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

$pageTitle = 'New Purchase (into WH)';
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
        <div class="form-grid">
            <div>
                <label>Purchase Date</label>
                <input type="datetime-local" name="purchase_date" required value="<?= e($dateVal) ?>">
            </div>
            <div>
                <label>Supplier</label>
                <select name="supplier_id">
                    <option value="">-- None --</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['supplier_id'] ?>" <?= (int)$supplierId === (int)$s['supplier_id'] ? 'selected' : '' ?>>
                            <?= e($s['supplier_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Note</label>
                <input type="text" name="description" value="<?= e($desc) ?>">
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr><th style="width:45%">Product</th><th>Quantity</th><th>Unit Cost</th><th>Line Total</th><th></th></tr>
        </thead>
        <tbody id="items">
        <?php foreach ($rows as $r): ?>
            <tr class="item-row">
                <td><select name="product_id[]"><?= product_options($products, $r['product_id']) ?></select></td>
                <td><input type="number" name="quantity[]" min="1" step="1" value="<?= e($r['quantity']) ?>"></td>
                <td><input type="number" name="unit_price[]" min="0" step="0.01" value="<?= e($r['unit_price']) ?>"></td>
                <td class="line-total">0</td>
                <td><button type="button" class="link-btn remove">Remove</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right"><strong>Total</strong></td>
                <td colspan="2"><strong id="grand">0</strong></td>
            </tr>
        </tfoot>
    </table>

    <template id="rowTpl">
        <tr class="item-row">
            <td><select name="product_id[]"><?= product_options($products) ?></select></td>
            <td><input type="number" name="quantity[]" min="1" step="1"></td>
            <td><input type="number" name="unit_price[]" min="0" step="0.01"></td>
            <td class="line-total">0</td>
            <td><button type="button" class="link-btn remove">Remove</button></td>
        </tr>
    </template>

    <div class="actions" style="margin-top:16px">
        <button type="button" class="btn btn-secondary" id="addRow">+ Add Row</button>
        <button type="submit" class="btn">Save Purchase</button>
        <a class="btn btn-secondary" href="purchases.php">Cancel</a>
    </div>
</form>

<script>
const body = document.getElementById('items');
const tpl  = document.getElementById('rowTpl');

function recalc() {
    let total = 0;
    body.querySelectorAll('.item-row').forEach(r => {
        const q = parseFloat(r.querySelector('[name="quantity[]"]').value) || 0;
        const p = parseFloat(r.querySelector('[name="unit_price[]"]').value) || 0;
        const line = q * p;
        r.querySelector('.line-total').textContent = line.toLocaleString();
        total += line;
    });
    document.getElementById('grand').textContent = total.toLocaleString();
}

document.getElementById('addRow').addEventListener('click', () => {
    body.appendChild(tpl.content.cloneNode(true));
    recalc();
});
body.addEventListener('input', recalc);
body.addEventListener('click', e => {
    if (e.target.classList.contains('remove') && body.querySelectorAll('.item-row').length > 1) {
        e.target.closest('tr').remove();
        recalc();
    }
});
recalc();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>