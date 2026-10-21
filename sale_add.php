<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$paymentMethods = ['Cash', 'Card', 'Mobile Pay', 'Other'];

$stock = get_stock_map($conn, 'sale');

$products = $conn->query(
    'SELECT p.product_id, p.product_name, s.code AS size_code, c.category_name
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     WHERE p.is_active = 1
     ORDER BY c.category_name, p.product_name, s.code'
)->fetchAll();
$validProducts = array_column($products, null, 'product_id');

// Last selling price used per product (to prefill the price box)
$lastPrice = [];
foreach ($conn->query('SELECT product_id, unit_price FROM sale_items WHERE sale_item_id IN (SELECT MAX(sale_item_id) FROM sale_items GROUP BY product_id)')->fetchAll() as $r) {
    $lastPrice[(int)$r['product_id']] = $r['unit_price'];
}

function product_label(array $p): string
{
    return $p['product_name'] . ($p['size_code'] ? ' ' . $p['size_code'] : '');
}

function sale_options(array $products, array $stock, array $lastPrice, $selected = ''): string
{
    $html = '<option value="">-- Select product --</option>';
    foreach ($products as $p) {
        $id  = (int)$p['product_id'];
        $qty = $stock[$id] ?? 0;
        $sel = ((string)$selected === (string)$id) ? ' selected' : '';
        $html .= '<option value="' . $id . '" data-stock="' . $qty . '" data-price="' . e($lastPrice[$id] ?? '') . '"' . $sel . '>'
               . e(product_label($p)) . ' — Sale stock: ' . $qty . '</option>';
    }
    return $html;
}

$errors  = [];
$dateVal = date('Y-m-d\TH:i');
$payment = 'Cash';
$rows    = [['product_id' => '', 'quantity' => '', 'unit_price' => '']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $payment = $_POST['payment_method'] ?? 'Cash';
    if (!in_array($payment, $paymentMethods, true)) $payment = 'Cash';

    $dateVal = $_POST['sale_date'] ?? '';
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $dateVal);
    if (!$dt) $errors[] = 'Please enter a valid sale date.';

    $pids   = $_POST['product_id'] ?? [];
    $qtys   = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $rows = []; $items = []; $total = 0; $wanted = [];

    foreach ($pids as $i => $pidRaw) {
        $pid  = (int)$pidRaw;
        $qtyR = trim($qtys[$i] ?? '');
        $prcR = trim($prices[$i] ?? '');
        if ($pid === 0 && $qtyR === '' && $prcR === '') continue;

        $rows[] = ['product_id' => $pid ?: '', 'quantity' => $qtyR, 'unit_price' => $prcR];
        $line = count($rows);

        if (!isset($validProducts[$pid]))              { $errors[] = "Row $line: choose a product."; continue; }
        if (!ctype_digit($qtyR) || (int)$qtyR < 1)     { $errors[] = "Row $line: quantity must be a whole number of 1 or more."; continue; }
        if (!is_numeric($prcR) || (float)$prcR < 0)    { $errors[] = "Row $line: selling price must be 0 or more."; continue; }

        $q = (int)$qtyR; $p = round((float)$prcR, 2);
        $items[]       = ['product_id' => $pid, 'quantity' => $q, 'unit_price' => $p];
        $wanted[$pid]  = ($wanted[$pid] ?? 0) + $q;
        $total        += $q * $p;
    }

    if (!$items && !$errors) $errors[] = 'Add at least one product.';
    if (!$rows) $rows = [['product_id' => '', 'quantity' => '', 'unit_price' => '']];

    if (!$errors) {
        $conn->beginTransaction();
        try {
            // Lock the product rows so two sales at once can't oversell
            $ids = array_keys($wanted);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $lock = $conn->prepare("SELECT product_id FROM products WHERE product_id IN ($in) FOR UPDATE");
            $lock->execute($ids);

            // A day that has already been counted is closed for that product
            foreach (get_closed_products($conn, $ids, $dt->format('Y-m-d'), ['sale']) as $pid => $d) {
                $errors[] = product_label($validProducts[$pid]) . ': already counted on ' . date('d-M-Y', strtotime($d)) . ', so that date is closed. Use a later date.';
            }

            foreach ($wanted as $pid => $qty) {
                $have = get_stock($conn, $pid, 'sale');
                if ($qty > $have) {
                    $errors[] = product_label($validProducts[$pid]) . ": only $have in Sale stock (you entered $qty). Move more from WH on the Transfer page.";
                }
            }

            if ($errors) {
                $conn->rollBack();
            } else {
                $conn->prepare('INSERT INTO sales (sale_date, total_amount, payment_method, created_by) VALUES (?, ?, ?, ?)')
                     ->execute([$dt->format('Y-m-d H:i:s'), $total, $payment, $_SESSION['user_id']]);
                $saleId = (int)$conn->lastInsertId();

                $ins = $conn->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, unit_cost) VALUES (?, ?, ?, ?, ?)');
                foreach ($items as $it) {
                    $ins->execute([$saleId, $it['product_id'], $it['quantity'], $it['unit_price'], get_latest_cost($conn, $it['product_id'])]);
                }
                $conn->commit();

                flash('success', 'Sale recorded. Total: ' . money($total));
                header('Location: ' . BASE_URL . '/sale_view.php?id=' . $saleId);
                exit;
            }
        } catch (Throwable $ex) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
    }

    // Stock may have changed; refresh labels
    $stock = get_stock_map($conn, 'sale');
}

$pageTitle = 'New Sale';
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
                <label>Sale Date</label>
                <input type="datetime-local" name="sale_date" required value="<?= e($dateVal) ?>">
            </div>
            <div>
                <label>Payment</label>
                <select name="payment_method">
                    <?php foreach ($paymentMethods as $m): ?>
                        <option <?= $payment === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr><th style="width:45%">Product</th><th>Qty</th><th>Selling Price</th><th>Line Total</th><th></th></tr>
        </thead>
        <tbody id="items">
        <?php foreach ($rows as $r): ?>
            <tr class="item-row">
                <td><select name="product_id[]"><?= sale_options($products, $stock, $lastPrice, $r['product_id']) ?></select></td>
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
            <td><select name="product_id[]"><?= sale_options($products, $stock, $lastPrice) ?></select></td>
            <td><input type="number" name="quantity[]" min="1" step="1"></td>
            <td><input type="number" name="unit_price[]" min="0" step="0.01"></td>
            <td class="line-total">0</td>
            <td><button type="button" class="link-btn remove">Remove</button></td>
        </tr>
    </template>

    <div class="actions" style="margin-top:16px">
        <button type="button" class="btn btn-secondary" id="addRow">+ Add Row</button>
        <button type="submit" class="btn">Save Sale</button>
        <a class="btn btn-secondary" href="sales.php">Cancel</a>
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
        r.querySelector('.line-total').textContent = (q * p).toLocaleString();
        total += q * p;
    });
    document.getElementById('grand').textContent = total.toLocaleString();
}

document.getElementById('addRow').addEventListener('click', () => {
    body.appendChild(tpl.content.cloneNode(true));
    recalc();
});

body.addEventListener('input', recalc);

// When a product is picked: prefill last selling price, cap quantity at stock
body.addEventListener('change', e => {
    if (e.target.name !== 'product_id[]') return;
    const row = e.target.closest('tr');
    const opt = e.target.selectedOptions[0];
    const price = row.querySelector('[name="unit_price[]"]');
    const qty   = row.querySelector('[name="quantity[]"]');
    if (opt && opt.value) {
        if (!price.value && opt.dataset.price) price.value = opt.dataset.price;
        qty.max = opt.dataset.stock;
    } else {
        qty.removeAttribute('max');
    }
    recalc();
});

body.addEventListener('click', e => {
    if (e.target.classList.contains('remove') && body.querySelectorAll('.item-row').length > 1) {
        e.target.closest('tr').remove();
        recalc();
    }
});
recalc();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>