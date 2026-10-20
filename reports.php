<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';

$reports = [
    'daily'     => 'Daily Inventory',
    'purchases' => 'Purchases',
    'sales'     => 'Sales',
    'stock'     => 'Remaining Stock',
    'foc'       => 'FOC',
    'adjust'    => 'Damaged / Lost',
    'profit'    => 'Profit',
    'monthly'   => 'Monthly Summary',
];
if (!is_admin()) unset($reports['profit']);

$r = $_GET['r'] ?? 'daily';
if (!isset($reports[$r])) $r = 'daily';

function ymd(?string $s, string $default): string
{
    $dt = $s ? DateTime::createFromFormat('Y-m-d', $s) : false;
    return ($dt && $dt->format('Y-m-d') === $s) ? $s : $default;
}

$today = date('Y-m-d');
$from  = ymd($_GET['from'] ?? null, date('Y-m-01'));
$to    = ymd($_GET['to'] ?? null, $today);
if ($from > $to) { [$from, $to] = [$to, $from]; }
$date  = ymd($_GET['date'] ?? null, $today);
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

// Which filter does each report use?
$filterType = ['daily' => 'date', 'stock' => 'none', 'monthly' => 'month'][$r] ?? 'range';

/* Column types: t=text, i=int, si=signed int, m=money, d=datetime, no=document no., p=percent */
$cols = [];
$rows = [];
$totals = null;
$period = '';

$pname = "CONCAT(p.product_name, IFNULL(CONCAT(' ', sz.code), ''))";

switch ($r) {

case 'daily':
    $period = date('l, d F Y', strtotime($date));
    $fig = get_daily_figures($conn, $date);
    $st = $conn->prepare(
        "SELECT p.product_id, $pname AS nm, di.physical_closing, di.difference
         FROM products p
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         LEFT JOIN daily_inventory di ON di.product_id = p.product_id AND di.inventory_date = ?
         WHERE p.is_active = 1 OR di.daily_inventory_id IS NOT NULL
         ORDER BY p.product_name, sz.code"
    );
    $st->execute([$date]);
    $cols = [['Product','t'],['Opening','i'],['+ Purchases','i'],['- Sales','i'],['- FOC','i'],['+/- Adjust','si'],['Expected','i'],['Physical','i'],['Difference','si']];
    $t = array_fill(0, 8, 0);
    foreach ($st->fetchAll() as $p) {
        $f    = $fig[(int)$p['product_id']];
        $phys = $p['physical_closing'] === null ? null : (int)$p['physical_closing'];
        $diff = $p['difference'] === null ? null : (int)$p['difference'];
        $rows[] = [$p['nm'], $f['opening'], $f['purchases'], $f['sales'], $f['foc'], $f['adjustments'], $f['expected'], $phys, $diff];
        $t[0] += $f['opening']; $t[1] += $f['purchases']; $t[2] += $f['sales']; $t[3] += $f['foc'];
        $t[4] += $f['adjustments']; $t[5] += $f['expected']; $t[6] += (int)$phys; $t[7] += (int)$diff;
    }
    $totals = ['Total', $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6], $t[7]];
    break;

case 'purchases':
    $period = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) . ' (completed purchases only)';
    $st = $conn->prepare(
        "SELECT pu.purchase_date, pu.purchase_id, COALESCE(s.supplier_name, '-'), $pname, i.quantity, i.unit_price, i.subtotal
         FROM purchase_items i
         JOIN purchases pu ON pu.purchase_id = i.purchase_id
         LEFT JOIN suppliers s ON s.supplier_id = pu.supplier_id
         JOIN products p ON p.product_id = i.product_id
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         WHERE pu.status = 'completed' AND DATE(pu.purchase_date) BETWEEN ? AND ?
         ORDER BY pu.purchase_date, pu.purchase_id, i.purchase_item_id"
    );
    $st->execute([$from, $to]);
    $cols = [['Date','d'],['Purchase #','no'],['Supplier','t'],['Product','t'],['Qty','i'],['Unit cost','m'],['Subtotal','m']];
    $rows = $st->fetchAll(PDO::FETCH_NUM);
    $totals = ['Total', null, null, null, array_sum(array_column($rows, 4)), null, array_sum(array_column($rows, 6))];
    break;

case 'sales':
    $period = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) . ' (completed sales only)';
    $st = $conn->prepare(
        "SELECT s.sale_date, s.sale_id, $pname, i.quantity, i.unit_price, i.subtotal, s.payment_method
         FROM sale_items i
         JOIN sales s ON s.sale_id = i.sale_id AND s.status = 'completed'
         JOIN products p ON p.product_id = i.product_id
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         WHERE DATE(s.sale_date) BETWEEN ? AND ?
         ORDER BY s.sale_date, s.sale_id, i.sale_item_id"
    );
    $st->execute([$from, $to]);
    $cols = [['Date','d'],['Sale #','no'],['Product','t'],['Qty','i'],['Price','m'],['Subtotal','m'],['Payment','t']];
    $rows = $st->fetchAll(PDO::FETCH_NUM);
    $totals = ['Total', null, null, array_sum(array_column($rows, 3)), null, array_sum(array_column($rows, 5)), null];
    break;

case 'stock':
    $period = 'As of now';
    $map = get_stock_map($conn);
    $st = $conn->query(
        "SELECT p.product_id, $pname AS nm, c.category_name,
                (SELECT pi.unit_price FROM purchase_items pi JOIN purchases pu ON pu.purchase_id = pi.purchase_id
                 WHERE pi.product_id = p.product_id AND pu.status = 'completed' ORDER BY pu.purchase_date DESC, pi.purchase_item_id DESC LIMIT 1) AS cost
         FROM products p
         JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         WHERE p.is_active = 1
         ORDER BY c.category_name, p.product_name, sz.code"
    );
    $cols = [['Product','t'],['Category','t'],['In stock','i']];
    if (is_admin()) { $cols[] = ['Latest cost','m']; $cols[] = ['Stock value','m']; }
    $units = 0; $value = 0;
    foreach ($st->fetchAll() as $p) {
        $q = $map[(int)$p['product_id']] ?? 0;
        $row = [$p['nm'], $p['category_name'], $q];
        if (is_admin()) {
            $cost = $p['cost'] === null ? null : (float)$p['cost'];
            $row[] = $cost;
            $row[] = $cost === null ? null : $cost * max($q, 0);
            $value += (float)$cost * max($q, 0);
        }
        $units += $q;
        $rows[] = $row;
    }
    $totals = is_admin() ? ['Total', null, $units, null, $value] : ['Total', null, $units];
    break;

case 'foc':
    $period = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));
    $st = $conn->prepare(
        "SELECT f.foc_date, $pname, f.quantity, COALESCE(f.description, ''), u.full_name
         FROM foc_records f
         JOIN products p ON p.product_id = f.product_id
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         JOIN users u ON u.user_id = f.created_by
         WHERE DATE(f.foc_date) BETWEEN ? AND ?
         ORDER BY f.foc_date, f.foc_id"
    );
    $st->execute([$from, $to]);
    $cols = [['Date','d'],['Product','t'],['Qty','i'],['Note','t'],['By','t']];
    $rows = $st->fetchAll(PDO::FETCH_NUM);
    $totals = ['Total', null, array_sum(array_column($rows, 2)), null, null];
    break;

case 'adjust':
    $period = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));
    $st = $conn->prepare(
        "SELECT a.adjustment_date, $pname, a.adjustment_type, a.quantity, COALESCE(a.description, ''), u.full_name
         FROM stock_adjustments a
         JOIN products p ON p.product_id = a.product_id
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         JOIN users u ON u.user_id = a.created_by
         WHERE DATE(a.adjustment_date) BETWEEN ? AND ?
         ORDER BY a.adjustment_date, a.adjustment_id"
    );
    $st->execute([$from, $to]);
    $cols = [['Date','d'],['Product','t'],['Type','t'],['Change','si'],['Note','t'],['By','t']];
    $rows = $st->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as &$row) { $row[2] = ucfirst($row[2]); }
    unset($row);
    $totals = ['Net change', null, null, array_sum(array_column($rows, 3)), null, null];
    break;

case 'profit':
    require_admin();
    $period = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) . ' (completed sales; uses the cost saved on each sale)';
    $st = $conn->prepare(
        "SELECT $pname AS nm, SUM(i.quantity) AS qty,
                SUM(i.quantity * i.unit_price) AS revenue,
                SUM(i.quantity * i.unit_cost)  AS cost
         FROM sale_items i
         JOIN sales s ON s.sale_id = i.sale_id AND s.status = 'completed'
         JOIN products p ON p.product_id = i.product_id
         LEFT JOIN size_types sz ON sz.size_type_id = p.size_type_id
         WHERE DATE(s.sale_date) BETWEEN ? AND ?
         GROUP BY p.product_id, nm
         ORDER BY (SUM(i.quantity * i.unit_price) - SUM(i.quantity * i.unit_cost)) DESC"
    );
    $st->execute([$from, $to]);
    $cols = [['Product','t'],['Qty sold','i'],['Revenue','m'],['Cost','m'],['Profit','m'],['Margin','p']];
    $q = $rev = $cost = 0;
    foreach ($st->fetchAll() as $p) {
        $profit = $p['revenue'] - $p['cost'];
        $rows[] = [$p['nm'], (int)$p['qty'], (float)$p['revenue'], (float)$p['cost'], $profit, $p['revenue'] > 0 ? $profit / $p['revenue'] * 100 : null];
        $q += $p['qty']; $rev += $p['revenue']; $cost += $p['cost'];
    }
    $totals = ['Total', $q, $rev, $cost, $rev - $cost, $rev > 0 ? ($rev - $cost) / $rev * 100 : null];
    break;

case 'monthly':
    $start = $month . '-01';
    $end   = date('Y-m-t', strtotime($start));
    $period = date('F Y', strtotime($start));
    $days = [];
    $blank = ['sales' => 0, 'purch' => 0, 'profit' => 0, 'foc' => 0, 'loss' => 0];

    $q = $conn->prepare("SELECT DATE(sale_date) d, SUM(total_amount) t FROM sales WHERE status = 'completed' AND DATE(sale_date) BETWEEN ? AND ? GROUP BY d");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $x) { $days[$x['d']] = ($days[$x['d']] ?? $blank); $days[$x['d']]['sales'] = (float)$x['t']; }

    $q = $conn->prepare('SELECT DATE(purchase_date) d, SUM(total_amount) t FROM purchases WHERE status = \'completed\' AND DATE(purchase_date) BETWEEN ? AND ? GROUP BY d');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $x) { $days[$x['d']] = ($days[$x['d']] ?? $blank); $days[$x['d']]['purch'] = (float)$x['t']; }

    $q = $conn->prepare("SELECT DATE(s.sale_date) d, SUM(i.quantity * (i.unit_price - i.unit_cost)) t
                         FROM sale_items i JOIN sales s ON s.sale_id = i.sale_id AND s.status = 'completed'
                         WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY d");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $x) { $days[$x['d']] = ($days[$x['d']] ?? $blank); $days[$x['d']]['profit'] = (float)$x['t']; }

    $q = $conn->prepare('SELECT DATE(foc_date) d, SUM(quantity) t FROM foc_records WHERE DATE(foc_date) BETWEEN ? AND ? GROUP BY d');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $x) { $days[$x['d']] = ($days[$x['d']] ?? $blank); $days[$x['d']]['foc'] = (int)$x['t']; }

    $q = $conn->prepare("SELECT DATE(adjustment_date) d, SUM(ABS(quantity)) t FROM stock_adjustments
                         WHERE adjustment_type IN ('damaged','lost') AND DATE(adjustment_date) BETWEEN ? AND ? GROUP BY d");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $x) { $days[$x['d']] = ($days[$x['d']] ?? $blank); $days[$x['d']]['loss'] = (int)$x['t']; }

    ksort($days);
    $cols = [['Date','t'],['Sales','m'],['Purchases','m']];
    if (is_admin()) $cols[] = ['Profit','m'];
    $cols[] = ['FOC units','i'];
    $cols[] = ['Damaged/Lost units','i'];

    $tot = $blank;
    foreach ($days as $d => $v) {
        $row = [date('D, d M', strtotime($d)), $v['sales'], $v['purch']];
        if (is_admin()) $row[] = $v['profit'];
        $row[] = $v['foc']; $row[] = $v['loss'];
        $rows[] = $row;
        foreach ($tot as $k => $_) $tot[$k] += $v[$k];
    }
    $totals = ['Total', $tot['sales'], $tot['purch']];
    if (is_admin()) $totals[] = $tot['profit'];
    $totals[] = $tot['foc']; $totals[] = $tot['loss'];
    break;
}

/* ---------- Formatting ---------- */
function fmt($v, string $t): string
{
    if ($v === null) return '—';
    switch ($t) {
        case 'm':  return money($v);
        case 'i':  return number_format((int)$v);
        case 'si': $v = (int)$v; return ($v > 0 ? '+' : '') . $v;
        case 'd':  return date('d-M-Y H:i', strtotime($v));
        case 'no': return str_pad((string)$v, 5, '0', STR_PAD_LEFT);
        case 'p':  return number_format((float)$v, 1) . '%';
        default:   return (string)$v;
    }
}
function raw($v, string $t): string
{
    if ($v === null) return '';
    switch ($t) {
        case 'm':  return number_format((float)$v, 2, '.', '');
        case 'd':  return date('Y-m-d H:i', strtotime($v));
        case 'no': return str_pad((string)$v, 5, '0', STR_PAD_LEFT);
        case 'p':  return number_format((float)$v, 1, '.', '');
        default:   return (string)$v;
    }
}

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $r . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
    fputcsv($out, array_column($cols, 0));
    foreach ($rows as $row) {
        $line = [];
        foreach ($cols as $i => $c) $line[] = raw($row[$i] ?? null, $c[1]);
        fputcsv($out, $line);
    }
    if ($totals) {
        $line = [];
        foreach ($cols as $i => $c) $line[] = $i === 0 ? $totals[0] : raw($totals[$i] ?? null, $c[1]);
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Reports';
require_once __DIR__ . '/includes/header.php';

$qs = $_GET; unset($qs['export']);
$csvUrl = 'reports.php?' . http_build_query($qs + ['r' => $r]) . '&export=csv';
?>

<div class="tabs">
    <?php foreach ($reports as $key => $label): ?>
        <a class="tab <?= $r === $key ? 'active' : '' ?>" href="reports.php?r=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($filterType !== 'none'): ?>
<form method="get" class="toolbar">
    <input type="hidden" name="r" value="<?= e($r) ?>">
    <?php if ($filterType === 'date'): ?>
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>">
    <?php elseif ($filterType === 'month'): ?>
        <input type="month" name="month" value="<?= e($month) ?>">
    <?php else: ?>
        <input type="date" name="from" value="<?= e($from) ?>">
        <input type="date" name="to" value="<?= e($to) ?>">
    <?php endif; ?>
    <button class="btn" type="submit">Show</button>
</form>
<?php endif; ?>

<div class="report-head">
    <div>
        <h3><?= e($reports[$r]) ?></h3>
        <div class="muted"><?= e($period) ?></div>
    </div>
    <div class="actions no-print">
        <a class="btn btn-secondary" href="<?= e($csvUrl) ?>">Export CSV</a>
        <button class="btn btn-secondary" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<table>
    <thead>
        <tr>
            <?php foreach ($cols as [$label, $t]): ?>
                <th class="<?= in_array($t, ['i', 'si', 'm', 'p'], true) ? 'num' : '' ?>"><?= e($label) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($cols as $i => [$label, $t]):
                $v = $row[$i] ?? null;
                $num = in_array($t, ['i', 'si', 'm', 'p'], true);
                $style = '';
                if ($t === 'si' && $v !== null && (int)$v !== 0) $style = 'font-weight:700;color:' . ((int)$v < 0 ? '#b91c1c' : '#166534');
                if ($t === 'm' && $label === 'Profit' && $v !== null && $v < 0) $style = 'color:#b91c1c;font-weight:700';
            ?>
                <td class="<?= $num ? 'num' : '' ?>" style="<?= $style ?>"><?= e(fmt($v, $t)) ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="<?= count($cols) ?>" class="muted">No data for this selection.</td></tr>
    <?php elseif ($totals): ?>
        <tr>
            <?php foreach ($cols as $i => [$label, $t]):
                $num = in_array($t, ['i', 'si', 'm', 'p'], true); ?>
                <th class="<?= $num ? 'num' : '' ?>"><?= $i === 0 ? e($totals[0]) : e(fmt($totals[$i] ?? null, $t)) ?></th>
            <?php endforeach; ?>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/includes/footer.php'; ?>