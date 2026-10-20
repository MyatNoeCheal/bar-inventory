<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$today = date('Y-m-d');

function valid_date(?string $s, string $today): string
{
    $dt = $s ? DateTime::createFromFormat('Y-m-d', $s) : false;
    if (!$dt || $dt->format('Y-m-d') !== $s || $s > $today) return $today;
    return $s;
}

$date   = valid_date($_POST['date'] ?? $_GET['date'] ?? null, $today);
$errors = [];
$postedPhys = null;
$postedNote = [];

// Display names
$names = [];
foreach ($conn->query('SELECT p.product_id, p.product_name, s.code AS size_code FROM products p LEFT JOIN size_types s ON s.size_type_id = p.size_type_id')->fetchAll() as $r) {
    $names[(int)$r['product_id']] = $r['product_name'] . ($r['size_code'] ? ' ' . $r['size_code'] : '');
}

/* ------------------------- SAVE ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $phys  = $_POST['physical'] ?? [];
    $notes = $_POST['note'] ?? [];
    $postedPhys = $phys;
    $postedNote = $notes;

    $figures = get_daily_figures($conn, $date);

    // Latest count date per product: a date can't be (re)counted once a later date has been counted
    $last = [];
    foreach ($conn->query('SELECT product_id, MAX(inventory_date) AS d FROM daily_inventory GROUP BY product_id')->fetchAll() as $r) {
        $last[(int)$r['product_id']] = $r['d'];
    }

    $queue = [];
    foreach ($phys as $pidRaw => $val) {
        $pid = (int)$pidRaw;
        $val = trim((string)$val);
        if ($val === '' || !isset($figures[$pid])) continue;

        $label = $names[$pid] ?? ('#' . $pid);
        if (!ctype_digit($val)) {
            $errors[] = "$label: physical count must be a whole number (0 or more).";
            continue;
        }
        if (isset($last[$pid]) && $last[$pid] > $date) {
            $errors[] = "$label: already counted on " . date('d-M-Y', strtotime($last[$pid])) . ", so this earlier date is closed.";
            continue;
        }
        $queue[$pid] = (int)$val;
    }
    if (!$errors && !$queue) $errors[] = 'Enter at least one physical count.';

    if (!$errors) {
        $conn->beginTransaction();
        try {
            $up = $conn->prepare(
                'INSERT INTO daily_inventory
                    (product_id, inventory_date, opening_quantity, physical_closing, calculated_closing, difference, description, counted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    opening_quantity   = VALUES(opening_quantity),
                    physical_closing   = VALUES(physical_closing),
                    calculated_closing = VALUES(calculated_closing),
                    difference         = VALUES(difference),
                    description        = VALUES(description),
                    counted_by         = VALUES(counted_by)'
            );
            $diffCount = 0;
            foreach ($queue as $pid => $physical) {
                $f    = $figures[$pid];                       // recalculated on the server, not trusted from the form
                $diff = $physical - $f['expected'];
                if ($diff !== 0) $diffCount++;
                $note = trim($notes[$pid] ?? '');
                $up->execute([$pid, $date, $f['opening'], $physical, $f['expected'], $diff, $note !== '' ? $note : null, $_SESSION['user_id']]);
            }
            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollBack();
            throw $ex;
        }

        flash('success', 'Count saved for ' . date('d-M-Y', strtotime($date)) . ': ' . count($queue) . ' product(s), ' . $diffCount . ' with a difference.');
        header('Location: ' . BASE_URL . '/daily_inventory.php?date=' . $date);
        exit;
    }
}

/* ------------------------- DISPLAY ------------------------- */
$figures = get_daily_figures($conn, $date);

$st = $conn->prepare(
    'SELECT p.product_id, p.product_name, c.category_name, s.code AS size_code,
            di.physical_closing, di.description AS note
     FROM products p
     JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN size_types s ON s.size_type_id = p.size_type_id
     LEFT JOIN daily_inventory di ON di.product_id = p.product_id AND di.inventory_date = ?
     WHERE p.is_active = 1 OR di.daily_inventory_id IS NOT NULL
     ORDER BY c.category_name, p.product_name, s.code'
);
$st->execute([$date]);
$products = $st->fetchAll();

$sum = $conn->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(difference <> 0), 0) AS d, COALESCE(SUM(difference), 0) AS n FROM daily_inventory WHERE inventory_date = ?');
$sum->execute([$date]);
$summary = $sum->fetch();

$pageTitle = 'Daily Inventory';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="get" class="toolbar">
    <input type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>">
    <button class="btn btn-secondary" type="submit">Go</button>
    <?php if ($date !== $today): ?><a href="daily_inventory.php">Back to today</a><?php endif; ?>
</form>

<div class="stat-grid">
    <div class="stat-card">
        <div class="label">Counted</div>
        <div class="value"><?= (int)$summary['c'] ?> / <?= count($products) ?></div>
        <div class="sub"><?= e(date('l, d M Y', strtotime($date))) ?></div>
    </div>
    <div class="stat-card <?= (int)$summary['d'] > 0 ? 'amber' : 'green' ?>">
        <div class="label">Products with a difference</div>
        <div class="value"><?= (int)$summary['d'] ?></div>
        <div class="sub">Saved counts only</div>
    </div>
    <div class="stat-card <?= (int)$summary['n'] < 0 ? 'amber' : 'green' ?>">
        <div class="label">Net difference (units)</div>
        <div class="value"><?= ((int)$summary['n'] > 0 ? '+' : '') . (int)$summary['n'] ?></div>
        <div class="sub">Physical minus expected</div>
    </div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="date" value="<?= e($date) ?>">

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th class="num">Opening</th>
                <th class="num">+ Purchases</th>
                <th class="num">&minus; Sales</th>
                <th class="num">&minus; FOC</th>
                <th class="num">&plusmn; Adjust</th>
                <th class="num">Expected</th>
                <th class="num">Physical</th>
                <th class="num">Diff</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody id="rows">
        <?php foreach ($products as $p):
            $id = (int)$p['product_id'];
            $f  = $figures[$id] ?? ['opening' => 0, 'purchases' => 0, 'sales' => 0, 'foc' => 0, 'adjustments' => 0, 'expected' => 0];

            if ($postedPhys !== null)            $physVal = $postedPhys[$id] ?? '';
            elseif ($p['physical_closing'] !== null) $physVal = (string)$p['physical_closing'];
            else                                  $physVal = '';

            $noteVal = $postedPhys !== null ? ($postedNote[$id] ?? '') : ($p['note'] ?? '');
        ?>
            <tr data-expected="<?= (int)$f['expected'] ?>">
                <td>
                    <strong><?= e($names[$id] ?? $p['product_name']) ?></strong>
                    <div class="muted" style="font-size:12px"><?= e($p['category_name']) ?></div>
                </td>
                <td class="num"><?= $f['opening'] ?></td>
                <td class="num"><?= $f['purchases'] ?></td>
                <td class="num"><?= $f['sales'] ?></td>
                <td class="num"><?= $f['foc'] ?></td>
                <td class="num"><?= ($f['adjustments'] > 0 ? '+' : '') . $f['adjustments'] ?></td>
                <td class="num"><strong><?= $f['expected'] ?></strong></td>
                <td class="num"><input class="count-input phys" type="number" min="0" step="1" name="physical[<?= $id ?>]" value="<?= e($physVal) ?>"></td>
                <td class="diff">&mdash;</td>
                <td><input class="note-input" type="text" name="note[<?= $id ?>]" maxlength="200" value="<?= e($noteVal) ?>" placeholder="optional"></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?>
            <tr><td colspan="10" class="muted">No products yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="actions" style="margin-top:16px">
        <button class="btn" type="submit">Save count</button>
        <button class="btn btn-secondary" type="button" id="fillBtn">Fill empty boxes with expected</button>
        <span class="muted">Leave a box empty to skip that product.</span>
    </div>
</form>

<script>
function refreshRow(tr) {
    const input = tr.querySelector('.phys');
    const cell  = tr.querySelector('.diff');
    if (input.value === '') {
        cell.textContent = '\u2014';
        cell.className = 'diff';
        tr.classList.remove('row-diff');
        return;
    }
    const diff = parseInt(input.value, 10) - parseInt(tr.dataset.expected, 10);
    cell.textContent = (diff > 0 ? '+' : '') + diff;
    cell.className = 'diff ' + (diff === 0 ? 'zero' : (diff < 0 ? 'neg' : 'pos'));
    tr.classList.toggle('row-diff', diff !== 0);
}
document.querySelectorAll('#rows tr[data-expected]').forEach(tr => {
    tr.querySelector('.phys').addEventListener('input', () => refreshRow(tr));
    refreshRow(tr);
});
document.getElementById('fillBtn').addEventListener('click', () => {
    document.querySelectorAll('#rows tr[data-expected]').forEach(tr => {
        const input = tr.querySelector('.phys');
        if (input.value === '') input.value = Math.max(parseInt(tr.dataset.expected, 10), 0);
        refreshRow(tr);
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>