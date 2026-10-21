<?php
// TEST DATA LOADER. Log in as admin, open once, type RESET, then DELETE this file.
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/includes/stock.php';
require_admin();

function run_seed(PDO $conn, int $userId): array
{
    mt_srand(2026); // same data every time

    /* ---------- 1. Clear old transaction + master data ---------- */
    $tables = ['sale_items', 'sales', 'purchase_items', 'purchases', 'foc_records', 'stock_adjustments', 'stock_transfers', 'daily_inventory', 'products', 'suppliers'];
    $conn->beginTransaction();
    foreach ($tables as $t) $conn->exec("DELETE FROM $t");
    $conn->commit();
    foreach ($tables as $t) $conn->exec("ALTER TABLE $t AUTO_INCREMENT = 1");

    /* ---------- 2. Lookups ---------- */
    $catId = function (string $n) use ($conn): int {
        $id = (int)$conn->query('SELECT category_id FROM categories WHERE category_name = ' . $conn->quote($n))->fetchColumn();
        if (!$id) throw new RuntimeException("Category '$n' not found. Add it on the Categories page first.");
        return $id;
    };
    $sizeId = function (string $c) use ($conn): int {
        $id = (int)$conn->query('SELECT size_type_id FROM size_types WHERE code = ' . $conn->quote($c))->fetchColumn();
        if (!$id) throw new RuntimeException("Size '$c' not found. Add it on the Sizes page first.");
        return $id;
    };

    $conn->beginTransaction();
    try {
        /* ---------- 3. Suppliers ---------- */
        $sup = [];
        $ins = $conn->prepare('INSERT INTO suppliers (supplier_name, phone, address) VALUES (?, ?, ?)');
        foreach ([
            ['Golden Beverage Co.',   '09-400100100', 'Insein, Yangon'],
            ['Yangon Wine & Spirits', '09-400200200', 'Kamayut, Yangon'],
            ['Daily Mart Wholesale',  '09-400300300', 'Mingalar Taung Nyunt, Yangon'],
        ] as [$n, $ph, $ad]) {
            $ins->execute([$n, $ph, $ad]);
            $sup[$n] = (int)$conn->lastInsertId();
        }

        /* ---------- 4. Products: name => [category, size, selling price, kind, description, Sale-section par level] ---------- */
        $defs = [
            'Chivas 18'            => ['Whisky',     '1L',   70000,  'spirit', '18 Years',          3],
            'Johnnie Walker Black' => ['Whisky',     '70CL', 60000,  'spirit', 'Black Label',       3],
            'Smirnoff'             => ['Vodka',      '70CL', 40000,  'spirit', null,                3],
            'Hennessy VS'          => ['Brandy',     '1L',   130000, 'spirit', null,                2],
            'Bacardi'              => ['Rum',        '70CL', 38000,  'spirit', 'White rum',         3],
            'House Red Wine'       => ['Wine',       '70CL', 45000,  'spirit', null,                3],
            'Myanmar Beer'         => ['Beer',       'B',    3000,   'fast',   'Large bottle',      24],
            'Tiger Beer'           => ['Beer',       'C-S',  2500,   'fast',   'Small can',         24],
            'Heineken'             => ['Beer',       'C-S',  2800,   'fast',   'Small can',         24],
            'Coca-Cola'            => ['Soft Drink', 'C-S',  1200,   'fast',   null,                24],
        ];
        $prod = [];
        $ins = $conn->prepare('INSERT INTO products (product_name, category_id, size_type_id, description) VALUES (?, ?, ?, ?)');
        foreach ($defs as $name => [$cat, $size, $price, $kind, $desc, $par]) {
            $ins->execute([$name, $catId($cat), $sizeId($size), $desc]);
            $prod[$name] = ['id' => (int)$conn->lastInsertId(), 'price' => $price, 'kind' => $kind, 'par' => $par];
        }
        // price changes: product => [applies when "days ago" <= N, new price]
        $priceChange = ['Myanmar Beer' => [3, 3200]];

        /* ---------- 5. Plans ---------- */
        // PURCHASES go into WH. days-ago => list of [supplier, time, [[product, qty, unit cost], ...]]
        $planBuy = [
            7 => [
                ['Golden Beverage Co.',   '10:30', [['Myanmar Beer', 60, 1800], ['Tiger Beer', 48, 1500], ['Heineken', 48, 1700], ['Coca-Cola', 48, 700]]],
                ['Yangon Wine & Spirits', '11:15', [['Chivas 18', 6, 50000], ['Johnnie Walker Black', 6, 42000], ['Smirnoff', 8, 28000], ['House Red Wine', 6, 30000], ['Hennessy VS', 3, 90000], ['Bacardi', 6, 26000]]],
            ],
            4 => [
                ['Golden Beverage Co.', '10:20', [['Myanmar Beer', 36, 1900], ['Tiger Beer', 24, 1500], ['Heineken', 24, 1700], ['Coca-Cola', 24, 750]]],
                ['Daily Mart Wholesale', '11:00', [['Smirnoff', 4, 29000], ['House Red Wine', 4, 31000]]],
            ],
            2 => [
                ['Yangon Wine & Spirits', '10:45', [['Chivas 18', 4, 52000], ['Johnnie Walker Black', 3, 43000], ['Hennessy VS', 2, 92000]]],
            ],
            1 => [
                ['Golden Beverage Co.', '10:10', [['Myanmar Beer', 24, 1900], ['Heineken', 24, 1750]]],
            ],
            0 => [
                ['Daily Mart Wholesale', '10:30', [['Bacardi', 3, 27000], ['Coca-Cola', 24, 750]]],
            ],
        ];
        // FOC received from suppliers (WH +): days-ago => [product, qty, note, time]
        $planFocIn = [
            7 => [['Myanmar Beer', 6, 'Free cases with order', '10:40']],
            4 => [['Tiger Beer', 4, 'Supplier promotion', '10:30']],
        ];
        // FOC given to customers (Sale -): days-ago => [product, qty, note, time]
        $planFocOut = [
            6 => [['Myanmar Beer', 2, 'Promotion', '20:10']],
            3 => [['Tiger Beer', 2, 'Regular customer', '21:00']],
            1 => [['Johnnie Walker Black', 1, "Owner's guest", '22:15']],
            0 => [['Coca-Cola', 3, 'Staff drinks', '14:00']],
        ];
        // Adjustments: days-ago => [product, type, location, signed qty, note]
        $planAdj = [
            6 => [['Bacardi', 'damaged', 'wh', -1, 'Broken while unloading']],
            5 => [['House Red Wine', 'damaged', 'sale', -1, 'Bottle broken']],
            3 => [['Heineken', 'lost', 'wh', -2, 'Lost in storage']],
            2 => [['Coca-Cola', 'found', 'sale', 1, 'Found behind fridge']],
            1 => [['Myanmar Beer', 'correction', 'sale', -1, 'Count correction']],
            0 => [['Tiger Beer', 'damaged', 'sale', -1, 'Dropped and broken']],
        ];
        // End-of-day count differences: days-ago => location => product => units physical differs from expected
        $deviation = [
            6 => ['sale' => ['Smirnoff' => -1]],
            5 => ['sale' => ['Myanmar Beer' => -2]],
            4 => ['wh'   => ['Myanmar Beer' => -2]],
            3 => ['sale' => ['Tiger Beer' => +1], 'wh' => ['Heineken' => -1]],
            2 => ['sale' => ['Coca-Cola' => -1]],
            1 => ['sale' => ['Chivas 18' => -1]],
        ];

        /* ---------- 6. Helpers ---------- */
        $stock = ['wh' => [], 'sale' => []];
        $stats = ['purchases' => 0, 'sales' => 0, 'voided' => 0, 'foc_in' => 0, 'foc_out' => 0, 'transfers' => 0, 'adjustments' => 0, 'counts' => 0];

        $insPur  = $conn->prepare('INSERT INTO purchases (supplier_id, purchase_date, total_amount, description, created_by) VALUES (?, ?, ?, ?, ?)');
        $insPI   = $conn->prepare('INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
        $insSale = $conn->prepare('INSERT INTO sales (sale_date, total_amount, payment_method, status, created_by) VALUES (?, ?, ?, ?, ?)');
        $insSI   = $conn->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, unit_cost) VALUES (?, ?, ?, ?, ?)');
        $insFoc  = $conn->prepare('INSERT INTO foc_records (product_id, quantity, foc_type, foc_date, description, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $insAdj  = $conn->prepare('INSERT INTO stock_adjustments (product_id, quantity, adjustment_type, location, description, adjustment_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insTr   = $conn->prepare('INSERT INTO stock_transfers (product_id, quantity, transfer_date, description, created_by) VALUES (?, ?, ?, ?, ?)');
        $insCnt  = $conn->prepare('INSERT INTO daily_inventory (product_id, inventory_date, location, opening_quantity, physical_closing, calculated_closing, difference, description, counted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $costQ   = $conn->prepare('SELECT pi.unit_price FROM purchase_items pi JOIN purchases pu ON pu.purchase_id = pi.purchase_id
                                   WHERE pi.product_id = ? AND pu.purchase_date <= ? ORDER BY pu.purchase_date DESC, pi.purchase_item_id DESC LIMIT 1');

        $costAt = function (int $pid, string $dt) use ($costQ): float {
            $costQ->execute([$pid, $dt]);
            return (float)($costQ->fetchColumn() ?: 0);
        };
        // random time between two clock times
        $rt = function (int $h1, int $m1, int $h2, int $m2): string {
            $t = mt_rand($h1 * 60 + $m1, $h2 * 60 + $m2);
            return sprintf('%02d:%02d:00', intdiv($t, 60), $t % 60);
        };

        $buy = function (string $supplier, string $dt, array $lines) use ($conn, $sup, $prod, $userId, $insPur, $insPI, &$stock, &$stats) {
            $total = 0;
            foreach ($lines as [, $q, $p]) $total += $q * $p;
            $insPur->execute([$sup[$supplier], $dt, $total, null, $userId]);
            $id = (int)$conn->lastInsertId();
            foreach ($lines as [$name, $q, $p]) {
                $pid = $prod[$name]['id'];
                $insPI->execute([$id, $pid, $q, $p]);
                $stock['wh'][$pid] = ($stock['wh'][$pid] ?? 0) + $q;
            }
            $stats['purchases']++;
        };

        $sell = function (string $dt, array $lines, string $pay, string $status = 'completed') use ($conn, $userId, $insSale, $insSI, $costAt, &$stock, &$stats) {
            $total = 0;
            foreach ($lines as [, $q, $p]) $total += $q * $p;
            $insSale->execute([$dt, $total, $pay, $status, $userId]);
            $id = (int)$conn->lastInsertId();
            foreach ($lines as [$pid, $q, $p]) {
                $insSI->execute([$id, $pid, $q, $p, $costAt($pid, $dt)]);
                if ($status === 'completed') $stock['sale'][$pid] -= $q;
            }
            $stats[$status === 'completed' ? 'sales' : 'voided']++;
        };

        // weighted pick bag: fast movers 3x, spirits 1x
        $bag = [];
        foreach ($prod as $name => $p) {
            for ($i = 0; $i < ($p['kind'] === 'fast' ? 3 : 1); $i++) $bag[] = $name;
        }
        $payments = ['Cash', 'Cash', 'Cash', 'Card', 'Mobile Pay'];

        /* ---------- 7. Day by day (oldest first) ---------- */
        for ($off = 7; $off >= 0; $off--) {
            $date  = date('Y-m-d', strtotime("-$off days"));
            $stock = ['wh' => get_stock_map($conn, 'wh'), 'sale' => get_stock_map($conn, 'sale')];   // start from the last counts

            // purchases (into WH)
            foreach ($planBuy[$off] ?? [] as [$supplier, $time, $lines]) {
                $buy($supplier, "$date $time:00", $lines);
            }

            // FOC received from suppliers (into WH)
            foreach ($planFocIn[$off] ?? [] as [$name, $qty, $note, $time]) {
                $pid = $prod[$name]['id'];
                $insFoc->execute([$pid, $qty, 'received', "$date $time:00", $note, $userId]);
                $stock['wh'][$pid] = ($stock['wh'][$pid] ?? 0) + $qty;
                $stats['foc_in']++;
            }

            // WH adjustments (damaged in the warehouse etc.)
            foreach ($planAdj[$off] ?? [] as [$name, $type, $loc, $qty, $note]) {
                if ($loc !== 'wh') continue;
                $pid = $prod[$name]['id'];
                if ($qty < 0 && ($stock['wh'][$pid] ?? 0) < abs($qty)) continue;
                $insAdj->execute([$pid, $qty, $type, 'wh', $note, "$date 12:00:00", $userId]);
                $stock['wh'][$pid] += $qty;
                $stats['adjustments']++;
            }

            // morning top-up: WH -> Sale up to each product's par level
            foreach ($prod as $name => $p) {
                $pid  = $p['id'];
                $need = $p['par'] - ($stock['sale'][$pid] ?? 0);
                $q    = min($need, $stock['wh'][$pid] ?? 0);
                if ($q > 0) {
                    $insTr->execute([$pid, $q, "$date 13:00:00", 'Daily top-up for Sale section', $userId]);
                    $stock['wh'][$pid]   -= $q;
                    $stock['sale'][$pid] = ($stock['sale'][$pid] ?? 0) + $q;
                    $stats['transfers']++;
                }
            }

            // sales (from Sale stock only)
            $count = 6 + mt_rand(0, 4) + (in_array((int)date('N', strtotime($date)), [5, 6], true) ? 3 : 0);
            for ($s = 0; $s < $count; $s++) {
                $lines = []; $used = [];
                for ($j = mt_rand(1, 3); $j > 0; $j--) {
                    $name = $bag[mt_rand(0, count($bag) - 1)];
                    $pid  = $prod[$name]['id'];
                    if (isset($used[$pid]) || ($stock['sale'][$pid] ?? 0) <= 0) continue;
                    $max   = $prod[$name]['kind'] === 'spirit' ? 2 : 6;
                    $qty   = mt_rand(1, min($max, $stock['sale'][$pid]));
                    $price = $prod[$name]['price'];
                    if (isset($priceChange[$name]) && $off <= $priceChange[$name][0]) $price = $priceChange[$name][1];
                    $lines[] = [$pid, $qty, $price];
                    $used[$pid] = true;
                }
                if (!$lines) continue;
                $time = $off === 0 ? $rt(14, 0, 16, 30) : $rt(18, 0, 23, 30);
                $sell("$date $time", $lines, $payments[mt_rand(0, 4)]);
            }

            // one voided sale (should be ignored everywhere)
            if ($off === 5) {
                $pid = $prod['Chivas 18']['id'];
                $stockBefore = $stock['sale'][$pid] ?? 0;
                $sell("$date 21:40:00", [[$pid, 1, 70000]], 'Cash', 'voided');
                $stock['sale'][$pid] = $stockBefore;   // a voided sale does not change stock
            }

            // FOC given to customers (Sale stock)
            foreach ($planFocOut[$off] ?? [] as [$name, $qty, $note, $time]) {
                $pid = $prod[$name]['id'];
                if (($stock['sale'][$pid] ?? 0) < $qty) continue;
                $insFoc->execute([$pid, $qty, 'given', "$date $time:00", $note, $userId]);
                $stock['sale'][$pid] -= $qty;
                $stats['foc_out']++;
            }

            // Sale-section adjustments
            foreach ($planAdj[$off] ?? [] as [$name, $type, $loc, $qty, $note]) {
                if ($loc !== 'sale') continue;
                $pid = $prod[$name]['id'];
                if ($qty < 0 && ($stock['sale'][$pid] ?? 0) < abs($qty)) continue;
                $insAdj->execute([$pid, $qty, $type, 'sale', $note, "$date 22:30:00", $userId]);
                $stock['sale'][$pid] += $qty;
                $stats['adjustments']++;
            }

            // end-of-day physical counts, both locations (all days except today)
            if ($off > 0) {
                foreach (['wh', 'sale'] as $loc) {
                    $fig = get_daily_figures($conn, $date, $loc);
                    foreach ($prod as $name => $p) {
                        $f    = $fig[$p['id']];
                        $dev  = $deviation[$off][$loc][$name] ?? 0;
                        $phys = max($f['expected'] + $dev, 0);
                        $diff = $phys - $f['expected'];
                        $insCnt->execute([$p['id'], $date, $loc, $f['opening'], $phys, $f['expected'], $diff, $diff !== 0 ? 'Recount confirmed' : null, $userId]);
                        $stats['counts']++;
                    }
                }
            }
        }

        $conn->commit();
    } catch (Throwable $ex) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $ex;
    }
    return $stats;
}

/* ------------------------- request handling ------------------------- */
$done = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (trim($_POST['confirm'] ?? '') !== 'RESET') {
        $error = 'Type RESET (capital letters) to confirm.';
    } else {
        try {
            $done = run_seed($conn, (int)$_SESSION['user_id']);
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

$pageTitle = 'Load Test Data';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($done): ?>
    <div class="alert alert-success">Test data loaded. Now delete <code>seed_test_data.php</code>.</div>
    <div class="card">
        <h3>Created</h3>
        <ul>
            <li><?= (int)$done['purchases'] ?> purchases</li>
            <li><?= (int)$done['sales'] ?> completed sales + <?= (int)$done['voided'] ?> voided sale</li>
            <li><?= (int)$done['transfers'] ?> WH &rarr; Sale transfers</li>
            <li><?= (int)$done['foc_in'] ?> FOC received (WH), <?= (int)$done['foc_out'] ?> FOC given (Sale), <?= (int)$done['adjustments'] ?> adjustments</li>
            <li><?= (int)$done['counts'] ?> daily count rows (7 days &times; 10 products &times; WH and Sale)</li>
        </ul>
        <div class="actions">
            <a class="btn" href="dashboard.php">Dashboard</a>
            <a class="btn btn-secondary" href="reports.php">Reports</a>
            <a class="btn btn-secondary" href="daily_inventory.php">Daily Inventory</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <h3>Replace all data with test data</h3>
        <p>This <strong>deletes all products, suppliers, purchases, sales, FOC records, adjustments and daily counts</strong>, then loads an 8-day WH / Sale test scenario. Users, categories and sizes are kept.</p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <div>
                <label>Type RESET to confirm</label>
                <input type="text" name="confirm" autocomplete="off" required>
            </div>
            <div class="actions"><button class="btn btn-danger" type="submit">Load test data</button></div>
        </form>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>