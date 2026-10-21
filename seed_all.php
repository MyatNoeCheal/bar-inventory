<?php
/**
 * seed_all.php - fills EVERY table so you can test the whole system.
 *
 *   users, categories, size_types, products, suppliers,
 *   purchases, purchase_items, sales, sale_items,
 *   foc_records, stock_adjustments, daily_inventory
 *
 * HOW TO USE
 *   1. Run create_table.php first if the tables do not exist yet.
 *   2. Put this file in the project root and open it in the browser.
 *      - Empty database (no users): anyone can open it, you choose the admin login.
 *      - Database has users: you must be logged in as admin.
 *   3. Choose the two logins, tick/untick "sample transactions", click Load.
 *   4. Read the check table. Every line should say PASS.
 *   5. DELETE THIS FILE.
 *
 * It WIPES all 12 tables first (TRUNCATE) and then loads fresh data.
 * Sample prices and costs are made-up test numbers. Change them in the arrays below.
 */

require_once __DIR__ . '/includes/auth.php';   // db connection, session, e(), csrf helpers
require_once __DIR__ . '/includes/stock.php';  // get_stock_map(), get_daily_figures()

/* =====================================================================
   1. THE DATA  (edit here if you want different names or prices)
   ===================================================================== */

$TABLES = [
    'users', 'categories', 'size_types', 'products', 'suppliers',
    'purchases', 'purchase_items', 'sales', 'sale_items',
    'foc_records', 'stock_adjustments', 'daily_inventory',
];

// category => description
$CATEGORIES = [
    'Label'      => 'Johnnie Walker labels',
    'Whisky'     => 'Whisky and other spirits',
    'Vodka'      => 'Vodka (flavours)',
    'Wine'       => 'Wine',
    'Soda'       => 'Soda',
    'Soju'       => 'Soju',
    'Soft Drink' => 'Soft drinks',
    'Beer'       => 'Beer',
    'Cigarettes' => 'Cigarettes',
    'Others'     => 'Water, towels and other items',
];

// [code, name, unit, description]
$SIZES = [
    ['1L',    '1 Liter',      'bottle', null],
    ['70CL',  '70 CL',        'bottle', null],
    ['35CL',  '35 CL',        'bottle', null],
    ['SB',    'Short Bottle', 'bottle', null],
    ['B',     'Bottle',       'bottle', null],
    ['C-S',   'Small Can',    'can',    null],
    ['C-L',   'Large Can',    'can',    null],
    ['C',     'Can',          'can',    null],
    ['LBOX',  'Large Box',    'box',    '1 large box = 10 boxes'],
    ['BOX',   'Box',          'box',    '1 box = 20 pcs'],
    ['PLATE', 'Plate',        'plate',  '1 plate = 2 pcs'],
];

// [product name, category, size code or null (pick later in the Edit Product page), description]
$PRODUCTS = [];
$add = function (string $cat, ?string $size, array $names, ?string $desc = null) use (&$PRODUCTS) {
    foreach ($names as $n) $PRODUCTS[] = [$n, $cat, $size, $desc];
};
$add('Label', null, [
    'Johnnie Walker Blue', 'Johnnie Walker Green', 'Johnnie Walker Gold', 'Johnnie Walker Black',
    'Johnnie Walker Double Black', 'Johnnie Walker Red', 'Johnnie Walker A Song of Fire',
    'Johnnie Walker A Song of Ice', 'Johnnie Walker Black Icon', 'Johnnie Walker Red Icon',
    'Johnnie Walker Black Limited Edition',
]);
$add('Whisky', null, [
    'Glenfiddich', 'Hennessy', 'Chivas', "Jack Daniel's", "Ballantine's", 'J&B', 'Jagermeister',
    "Sir Edward's Red", "Sir Edward's Blue", 'Dunfife Blue', 'Dunfife Red', 'Dunfife Green',
    'Shwe Cherry', 'Grand Blue', 'Grand Black',
]);
$add('Vodka', '1L', ['Vodka Orange', 'Vodka Strawberry']);
$add('Wine', null, ['Fullmoon Red', 'Fullmoon White', 'Fullmoon Black', 'Fullmoon Rose', 'Spy Red', 'Spy Black']);
$add('Soda', 'C', ['POP']);
$add('Soju', 'B', ['Chingu', 'Jinro', 'Rice Soju']);
$add('Soft Drink', 'C', [
    'Shark', 'Carabao', 'Jasper', 'Lipo', 'Sprite', 'Coke',
    'Sunkist Grape', 'Sunkist Lime', 'Sunkist Orange', 'Sunkist Lemonade',
]);
$add('Beer', null, [
    'Myanmar', 'Myanmar Premium', 'Myanmar Stout', 'Black Shield', 'Tiger',
    'Tiger Crystal', 'Tiger Soju', 'Heineken', 'ABC', 'Bavaria',
]);
$add('Cigarettes', null, ['Ruby', 'Mervius', 'Tobacco']);
$add('Others', '1L', ['Water']);
$add('Others', null, ['Snow Towel', 'Hand Towel']);

// [name, phone, address]
$SUPPLIERS = [
    ['Golden Beverage Co.',   '09-400100100', 'Insein, Yangon'],
    ['Yangon Wine & Spirits', '09-400200200', 'Kamayut, Yangon'],
    ['Daily Mart Wholesale',  '09-400300300', 'Mingalar Taung Nyunt, Yangon'],
];

// Sample transactions use only these products: name => [selling price, kind ('spirit' sells slowly, 'fast' sells quickly)]
$SAMPLE = [
    'Johnnie Walker Black' => [120000, 'spirit'],
    'Johnnie Walker Blue'  => [450000, 'spirit'],
    'Chivas'               => [90000,  'spirit'],
    "Jack Daniel's"        => [85000,  'spirit'],
    'Dunfife Red'          => [40000,  'spirit'],
    'Vodka Orange'         => [45000,  'spirit'],
    'Fullmoon Red'         => [35000,  'spirit'],
    'Chingu'               => [9000,   'fast'],
    'Myanmar'              => [3000,   'fast'],
    'Tiger'                => [3200,   'fast'],
    'Heineken'             => [3500,   'fast'],
    'Coke'                 => [1200,   'fast'],
    'Sprite'               => [1200,   'fast'],
    'Water'                => [800,    'fast'],
];
$PRICE_CHANGE = ['Myanmar' => [3, 3300]];   // product => [applies when days-ago <= N, new price]

// days-ago => list of [supplier, time, status, [[product, qty, unit cost], ...]]
$PLAN_BUY = [
    7 => [
        ['Golden Beverage Co.', '10:30', 'completed', [['Myanmar', 60, 1900], ['Tiger', 48, 2100], ['Heineken', 48, 2400], ['Coke', 48, 750], ['Sprite', 48, 750], ['Water', 48, 400]]],
        ['Yangon Wine & Spirits', '11:15', 'completed', [['Johnnie Walker Black', 6, 90000], ['Johnnie Walker Blue', 2, 380000], ['Chivas', 6, 65000], ["Jack Daniel's", 6, 60000], ['Dunfife Red', 8, 28000], ['Vodka Orange', 6, 32000], ['Fullmoon Red', 6, 24000], ['Chingu', 24, 6500]]],
    ],
    6 => [
        // a voided purchase: must NOT add any stock
        ['Daily Mart Wholesale', '14:00', 'voided', [['Chingu', 12, 6500]]],
    ],
    4 => [
        ['Golden Beverage Co.', '10:20', 'completed', [['Myanmar', 36, 1950], ['Tiger', 24, 2100], ['Heineken', 24, 2450], ['Coke', 24, 750]]],
        ['Daily Mart Wholesale', '11:00', 'completed', [['Dunfife Red', 4, 28500], ['Fullmoon Red', 4, 24500]]],
    ],
    2 => [
        ['Yangon Wine & Spirits', '10:45', 'completed', [['Chivas', 4, 66000], ['Johnnie Walker Black', 3, 91000], ["Jack Daniel's", 2, 61000]]],
    ],
    1 => [
        ['Golden Beverage Co.', '10:10', 'completed', [['Myanmar', 24, 1950], ['Heineken', 24, 2450], ['Water', 24, 400]]],
    ],
    0 => [
        ['Daily Mart Wholesale', '10:30', 'completed', [['Vodka Orange', 3, 32500], ['Coke', 24, 750], ['Sprite', 12, 750]]],
    ],
];
// days-ago => list of [product, qty, note, time]
$PLAN_FOC = [
    6 => [['Myanmar', 2, 'Promotion', '20:10']],
    3 => [['Tiger', 2, 'Regular customer', '21:00']],
    1 => [['Johnnie Walker Black', 1, "Owner's guest", '22:15']],
    0 => [['Coke', 3, 'Staff drinks', '14:00']],
];
// days-ago => list of [product, type, signed qty, note]
$PLAN_ADJ = [
    5 => [['Fullmoon Red', 'damaged', -1, 'Bottle broken']],
    3 => [['Heineken', 'lost', -2, 'Lost in storage']],
    2 => [['Coke', 'found', 1, 'Found behind fridge']],
    1 => [['Myanmar', 'correction', -1, 'Count correction']],
    0 => [['Tiger', 'damaged', -1, 'Dropped and broken']],
];
// days-ago => product => units the physical count differs from the expected figure
$DEVIATION = [
    6 => ['Dunfife Red' => -1],
    5 => ['Myanmar' => -2],
    3 => ['Tiger' => +1],
    2 => ['Coke' => -1],
    1 => ['Chivas' => -1],
];

/* =====================================================================
   2. SMALL HELPERS
   ===================================================================== */

function page_open(string $title): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Bar Inventory</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body>
<main class="content" style="margin:0 auto; max-width:1000px">
<h2><?= e($title) ?></h2>
<?php
}

function page_close(): void
{
    echo "</main>\n</body>\n</html>";
}

function rand_time(int $fromMin, int $toMin): string
{
    $t = mt_rand($fromMin, $toMin);
    return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
}

function wipe_all(PDO $conn, array $tables): void
{
    $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($tables as $t) $conn->exec("TRUNCATE TABLE `$t`");
    } finally {
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/* =====================================================================
   3. THE LOADER
   ===================================================================== */

function seed_everything(PDO $conn, array $in, bool $withTx, array $D): array
{
    mt_srand(2026);   // same sample data every time
    $stats = ['purchases' => 0, 'voided_purchases' => 0, 'sales' => 0, 'voided_sales' => 0, 'foc' => 0, 'adjustments' => 0, 'counts' => 0];

    $conn->beginTransaction();
    try {
        /* ---- users ---- */
        $insU = $conn->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
        $insU->execute([$in['admin_user'], password_hash($in['admin_pass'], PASSWORD_DEFAULT), 'Administrator', 'admin']);
        $adminId = (int)$conn->lastInsertId();
        $insU->execute([$in['staff_user'], password_hash($in['staff_pass'], PASSWORD_DEFAULT), 'Bar Staff', 'staff']);
        $staffId = (int)$conn->lastInsertId();

        /* ---- categories ---- */
        $catId = [];
        $ins = $conn->prepare('INSERT INTO categories (category_name, description) VALUES (?, ?)');
        foreach ($D['categories'] as $name => $desc) {
            $ins->execute([$name, $desc]);
            $catId[$name] = (int)$conn->lastInsertId();
        }

        /* ---- sizes ---- */
        $sizeId = [];
        $ins = $conn->prepare('INSERT INTO size_types (code, name, unit, description) VALUES (?, ?, ?, ?)');
        foreach ($D['sizes'] as [$code, $name, $unit, $desc]) {
            $ins->execute([$code, $name, $unit, $desc]);
            $sizeId[$code] = (int)$conn->lastInsertId();
        }

        /* ---- products ---- */
        $prodId = [];   // name => id
        $ins = $conn->prepare('INSERT INTO products (product_name, category_id, size_type_id, description) VALUES (?, ?, ?, ?)');
        foreach ($D['products'] as [$name, $cat, $size, $desc]) {
            if (!isset($catId[$cat]))                  throw new RuntimeException("Unknown category '$cat' for product '$name'.");
            if ($size !== null && !isset($sizeId[$size])) throw new RuntimeException("Unknown size '$size' for product '$name'.");
            $ins->execute([$name, $catId[$cat], $size === null ? null : $sizeId[$size], $desc]);
            $prodId[$name] = (int)$conn->lastInsertId();
        }

        /* ---- suppliers ---- */
        $supId = [];
        $ins = $conn->prepare('INSERT INTO suppliers (supplier_name, phone, address) VALUES (?, ?, ?)');
        foreach ($D['suppliers'] as [$name, $phone, $addr]) {
            $ins->execute([$name, $phone, $addr]);
            $supId[$name] = (int)$conn->lastInsertId();
        }

        /* ---- sample transactions ---- */
        if ($withTx) {
            $prod = [];
            foreach ($D['sample'] as $name => [$price, $kind]) {
                if (!isset($prodId[$name])) throw new RuntimeException("Sample product '$name' is not in the product list.");
                $prod[$name] = ['id' => $prodId[$name], 'price' => $price, 'kind' => $kind];
            }

            $todayStr = date('Y-m-d');
            $nowMin   = (int)date('G') * 60 + (int)date('i');
            // never create a movement in the future: today's times are cut back to "now"
            $at = function (string $date, string $hhmm) use ($todayStr, $nowMin): string {
                [$h, $m] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
                $t = $h * 60 + $m;
                if ($date === $todayStr && $t > $nowMin - 2) $t = max(1, $nowMin - 2);
                return sprintf('%s %02d:%02d:00', $date, intdiv($t, 60), $t % 60);
            };

            $insPur  = $conn->prepare('INSERT INTO purchases (supplier_id, purchase_date, total_amount, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $insPI   = $conn->prepare('INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
            $insSale = $conn->prepare('INSERT INTO sales (sale_date, total_amount, payment_method, status, created_by) VALUES (?, ?, ?, ?, ?)');
            $insSI   = $conn->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, unit_cost) VALUES (?, ?, ?, ?, ?)');
            $insFoc  = $conn->prepare('INSERT INTO foc_records (product_id, quantity, foc_date, description, created_by) VALUES (?, ?, ?, ?, ?)');
            $insAdj  = $conn->prepare('INSERT INTO stock_adjustments (product_id, quantity, adjustment_type, description, adjustment_date, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $insCnt  = $conn->prepare('INSERT INTO daily_inventory (product_id, inventory_date, opening_quantity, physical_closing, calculated_closing, difference, description, counted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            // cost of a sale = latest COMPLETED purchase at that time (same rule as the Sales page)
            $costQ   = $conn->prepare("SELECT pi.unit_price FROM purchase_items pi JOIN purchases pu ON pu.purchase_id = pi.purchase_id
                                        WHERE pi.product_id = ? AND pu.status = 'completed' AND pu.purchase_date <= ?
                                        ORDER BY pu.purchase_date DESC, pi.purchase_item_id DESC LIMIT 1");
            $costAt = function (int $pid, string $dt) use ($costQ): float {
                $costQ->execute([$pid, $dt]);
                return (float)($costQ->fetchColumn() ?: 0);
            };

            $avail = [];

            $buy = function (string $supplier, string $dt, string $status, array $lines) use ($conn, $supId, $prod, $adminId, $insPur, $insPI, &$avail, &$stats) {
                $total = 0;
                foreach ($lines as [, $q, $p]) $total += $q * $p;
                $note = $status === 'voided' ? 'Voided: wrong delivery' : null;
                $insPur->execute([$supId[$supplier], $dt, $total, $note, $status, $adminId]);
                $id = (int)$conn->lastInsertId();
                foreach ($lines as [$name, $q, $p]) {
                    $pid = $prod[$name]['id'];
                    $insPI->execute([$id, $pid, $q, $p]);
                    if ($status === 'completed') $avail[$pid] = ($avail[$pid] ?? 0) + $q;
                }
                $stats[$status === 'completed' ? 'purchases' : 'voided_purchases']++;
            };

            $sell = function (string $dt, array $lines, string $pay, int $by, string $status = 'completed') use ($conn, $insSale, $insSI, $costAt, &$avail, &$stats) {
                $total = 0;
                foreach ($lines as [, $q, $p]) $total += $q * $p;
                $insSale->execute([$dt, $total, $pay, $status, $by]);
                $id = (int)$conn->lastInsertId();
                foreach ($lines as [$pid, $q, $p]) {
                    $insSI->execute([$id, $pid, $q, $p, $costAt($pid, $dt)]);
                    if ($status === 'completed') $avail[$pid] -= $q;
                }
                $stats[$status === 'completed' ? 'sales' : 'voided_sales']++;
            };

            // weighted pick list: fast sellers 3x, spirits 1x
            $bag = [];
            foreach ($prod as $name => $p) {
                for ($i = 0; $i < ($p['kind'] === 'fast' ? 3 : 1); $i++) $bag[] = $name;
            }
            $payments = ['Cash', 'Cash', 'Cash', 'Card', 'Mobile Pay'];

            // day by day, oldest first (a counted day is closed, so order matters)
            for ($off = 7; $off >= 0; $off--) {
                $date  = date('Y-m-d', strtotime("-$off days"));
                $avail = get_stock_map($conn);   // starts from the last physical count

                foreach ($D['plan_buy'][$off] ?? [] as [$supplier, $time, $status, $lines]) {
                    $buy($supplier, $at($date, $time), $status, $lines);
                }

                $count = 6 + mt_rand(0, 4) + (in_array((int)date('N', strtotime($date)), [5, 6], true) ? 3 : 0);
                for ($s = 0; $s < $count; $s++) {
                    $lines = []; $used = [];
                    for ($j = mt_rand(1, 3); $j > 0; $j--) {
                        $name = $bag[mt_rand(0, count($bag) - 1)];
                        $pid  = $prod[$name]['id'];
                        if (isset($used[$pid]) || ($avail[$pid] ?? 0) <= 0) continue;
                        $max   = $prod[$name]['kind'] === 'spirit' ? 2 : 6;
                        $qty   = mt_rand(1, min($max, $avail[$pid]));
                        $price = $prod[$name]['price'];
                        if (isset($D['price_change'][$name]) && $off <= $D['price_change'][$name][0]) $price = $D['price_change'][$name][1];
                        $lines[]     = [$pid, $qty, $price];
                        $used[$pid]  = true;
                    }
                    if (!$lines) continue;
                    $time = $off === 0 ? rand_time(12 * 60 + 30, 15 * 60 + 30) : rand_time(18 * 60, 23 * 60 + 30);
                    $by   = mt_rand(0, 2) === 0 ? $adminId : $staffId;
                    $sell($at($date, $time), $lines, $payments[mt_rand(0, 4)], $by);
                }

                if ($off === 5) {   // one voided sale: must be ignored everywhere
                    $sell($at($date, '21:40'), [[$prod['Chivas']['id'], 1, 90000]], 'Cash', $staffId, 'voided');
                }

                foreach ($D['plan_foc'][$off] ?? [] as [$name, $qty, $note, $time]) {
                    $pid = $prod[$name]['id'];
                    if (($avail[$pid] ?? 0) < $qty) continue;
                    $insFoc->execute([$pid, $qty, $at($date, $time), $note, $adminId]);
                    $avail[$pid] -= $qty;
                    $stats['foc']++;
                }

                foreach ($D['plan_adj'][$off] ?? [] as [$name, $type, $qty, $note]) {
                    $pid = $prod[$name]['id'];
                    if ($qty < 0 && ($avail[$pid] ?? 0) < abs($qty)) continue;
                    $insAdj->execute([$pid, $qty, $type, $note, $at($date, '22:30'), $adminId]);
                    $avail[$pid] += $qty;
                    $stats['adjustments']++;
                }

                // end-of-day physical count for every day except today
                if ($off > 0) {
                    $fig = get_daily_figures($conn, $date);
                    foreach ($prod as $name => $p) {
                        $f    = $fig[$p['id']];
                        $dev  = $D['deviation'][$off][$name] ?? 0;
                        $phys = max($f['expected'] + $dev, 0);
                        $diff = $phys - $f['expected'];
                        $insCnt->execute([$p['id'], $date, $f['opening'], $phys, $f['expected'], $diff, $diff !== 0 ? 'Recount confirmed' : null, $adminId]);
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

/* =====================================================================
   4. THE CHECKS  (each returns [label, pass?, detail])
   ===================================================================== */

function run_checks(PDO $conn, array $in, array $stats, array $D, bool $withTx): array
{
    $n = fn(string $sql) => (int)$conn->query($sql)->fetchColumn();
    $checks = [];
    $add = function (string $label, bool $pass, string $detail) use (&$checks) { $checks[] = [$label, $pass, $detail]; };

    // master data row counts
    foreach ([
        ['users', 2], ['categories', count($D['categories'])], ['size_types', count($D['sizes'])],
        ['products', count($D['products'])], ['suppliers', count($D['suppliers'])],
    ] as [$table, $want]) {
        $got = $n("SELECT COUNT(*) FROM `$table`");
        $add("$table: $want rows", $got === $want, "found $got");
    }

    // logins
    $ok = true;
    foreach ([[$in['admin_user'], $in['admin_pass'], 'admin'], [$in['staff_user'], $in['staff_pass'], 'staff']] as [$u, $p, $role]) {
        $st = $conn->prepare('SELECT password_hash, role, is_active FROM users WHERE username = ?');
        $st->execute([$u]);
        $r = $st->fetch();
        if (!$r || !password_verify($p, $r['password_hash']) || $r['role'] !== $role || !$r['is_active']) $ok = false;
    }
    $add('Both logins verify (password, role, active)', $ok, $ok ? 'admin and staff can sign in' : 'a login did not verify');

    // products: foreign keys resolve, sizes present where expected
    $bad = $n('SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.category_id = p.category_id WHERE c.category_id IS NULL');
    $add('Every product has a valid category', $bad === 0, "$bad orphan(s)");
    $withSize = $n('SELECT COUNT(*) FROM products WHERE size_type_id IS NOT NULL');
    $wantSize = count(array_filter($D['products'], fn($p) => $p[2] !== null));
    $add("Products with a size: $wantSize (the rest are left blank for the dropdown)", $withSize === $wantSize, "found $withSize");

    if (!$withTx) return $checks;

    // transaction row counts against what the loader did
    foreach ([
        ['purchases',         $stats['purchases'] + $stats['voided_purchases'], 'SELECT COUNT(*) FROM purchases'],
        ['sales',             $stats['sales'] + $stats['voided_sales'],         'SELECT COUNT(*) FROM sales'],
        ['foc_records',       $stats['foc'],                                    'SELECT COUNT(*) FROM foc_records'],
        ['stock_adjustments', $stats['adjustments'],                            'SELECT COUNT(*) FROM stock_adjustments'],
        ['daily_inventory',   $stats['counts'],                                 'SELECT COUNT(*) FROM daily_inventory'],
    ] as [$label, $want, $sql]) {
        $got = $n($sql);
        $add("$label: $want rows", $got === $want && $got > 0, "found $got");
    }
    $add('purchase_items and sale_items have rows', $n('SELECT COUNT(*) FROM purchase_items') > 0 && $n('SELECT COUNT(*) FROM sale_items') > 0,
        $n('SELECT COUNT(*) FROM purchase_items') . ' purchase lines, ' . $n('SELECT COUNT(*) FROM sale_items') . ' sale lines');

    // totals match their lines (also proves the generated subtotal columns work)
    $bad = $n('SELECT COUNT(*) FROM purchases p WHERE p.total_amount <> COALESCE((SELECT SUM(subtotal) FROM purchase_items WHERE purchase_id = p.purchase_id), 0)');
    $add('Each purchase total = sum of its lines', $bad === 0, "$bad mismatch(es)");
    $bad = $n('SELECT COUNT(*) FROM sales s WHERE s.total_amount <> COALESCE((SELECT SUM(subtotal) FROM sale_items WHERE sale_id = s.sale_id), 0)');
    $add('Each sale total = sum of its lines', $bad === 0, "$bad mismatch(es)");

    // every sale line has a cost taken from a purchase
    $bad = $n('SELECT COUNT(*) FROM sale_items WHERE unit_cost <= 0');
    $add('Every sale line has a unit cost', $bad === 0, "$bad line(s) with no cost");

    // voided records exist and are ignored
    $add('One voided purchase and one voided sale exist',
        $n("SELECT COUNT(*) FROM purchases WHERE status = 'voided'") === 1 && $n("SELECT COUNT(*) FROM sales WHERE status = 'voided'") === 1,
        'they are left out of stock (see next check)');

    // THE main check: stock the app shows = independent arithmetic from the raw tables
    $now = get_stock_map($conn);
    $rows = $conn->query(
        "SELECT p.product_id, p.product_name,
            COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi JOIN purchases pu ON pu.purchase_id = pi.purchase_id WHERE pi.product_id = p.product_id AND pu.status = 'completed'), 0)
          - COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE si.product_id = p.product_id AND s.status = 'completed'), 0)
          - COALESCE((SELECT SUM(f.quantity) FROM foc_records f WHERE f.product_id = p.product_id), 0)
          + COALESCE((SELECT SUM(a.quantity) FROM stock_adjustments a WHERE a.product_id = p.product_id), 0)
          + COALESCE((SELECT SUM(d.difference) FROM daily_inventory d WHERE d.product_id = p.product_id), 0) AS expected
         FROM products p"
    )->fetchAll();
    $wrong = []; $negative = 0; $withStock = 0;
    foreach ($rows as $r) {
        $have = $now[(int)$r['product_id']] ?? 0;
        if ($have !== (int)$r['expected']) $wrong[] = $r['product_name'] . " (app $have, raw " . (int)$r['expected'] . ')';
        if ($have < 0) $negative++;
        if ($have > 0) $withStock++;
    }
    $add('Stock shown by the app = purchases - sales - FOC + adjustments + count differences', !$wrong,
        $wrong ? implode('; ', array_slice($wrong, 0, 3)) : "all " . count($rows) . " products agree");
    $add('No product has negative stock', $negative === 0, "$negative negative; $withStock products currently in stock");

    // daily counts
    $bad = $n('SELECT COUNT(*) FROM daily_inventory WHERE difference <> physical_closing - calculated_closing');
    $add('Daily count: difference = physical - expected', $bad === 0, "$bad bad row(s)");
    $bad = 0;
    foreach ($conn->query('SELECT DISTINCT inventory_date FROM daily_inventory')->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $fig = get_daily_figures($conn, $d);
        $st = $conn->prepare('SELECT product_id, opening_quantity, calculated_closing FROM daily_inventory WHERE inventory_date = ?');
        $st->execute([$d]);
        foreach ($st->fetchAll() as $r) {
            $f = $fig[(int)$r['product_id']];
            if ($f['opening'] !== (int)$r['opening_quantity'] || $f['expected'] !== (int)$r['calculated_closing']) $bad++;
        }
    }
    $add('Daily count: stored opening/expected can be recalculated', $bad === 0, "$bad row(s) differ");
    $diffRows = $n('SELECT COUNT(*) FROM daily_inventory WHERE difference <> 0');
    $add('Some counts have a difference (to test the report)', $diffRows > 0, "$diffRows row(s)");

    return $checks;
}

/* =====================================================================
   5. REQUEST HANDLING
   ===================================================================== */

// Are the tables there, and what is in them?
$counts = []; $missing = [];
foreach ($TABLES as $t) {
    try {
        $counts[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    } catch (PDOException $ex) {
        $missing[] = $t;
    }
}
if ($missing) {
    page_open('Load all data');
    echo '<div class="alert alert-error">These tables do not exist yet: <strong>' . e(implode(', ', $missing)) . '</strong>.<br>Open <code>create_table.php</code> once, then reload this page.</div>';
    page_close();
    exit;
}

// Once the database has users, only an admin may use this page.
if ($counts['users'] > 0) {
    require_once __DIR__ . '/auth/check_auth.php';
    require_admin();
}
$existingRows = array_sum($counts);

$D = [
    'categories' => $CATEGORIES, 'sizes' => $SIZES, 'products' => $PRODUCTS, 'suppliers' => $SUPPLIERS,
    'sample' => $SAMPLE, 'price_change' => $PRICE_CHANGE, 'plan_buy' => $PLAN_BUY,
    'plan_foc' => $PLAN_FOC, 'plan_adj' => $PLAN_ADJ, 'deviation' => $DEVIATION,
];

$errors  = [];
$result  = null;
$form    = ['admin_user' => 'admin', 'staff_user' => 'staff', 'with_tx' => true];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $in = [
        'admin_user' => trim($_POST['admin_user'] ?? ''),
        'admin_pass' => $_POST['admin_pass'] ?? '',
        'staff_user' => trim($_POST['staff_user'] ?? ''),
        'staff_pass' => $_POST['staff_pass'] ?? '',
    ];
    $withTx = isset($_POST['with_tx']);
    $form = ['admin_user' => $in['admin_user'], 'staff_user' => $in['staff_user'], 'with_tx' => $withTx];

    foreach (['admin_user' => 'Admin', 'staff_user' => 'Staff'] as $k => $label) {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $in[$k])) $errors[] = "$label username must be 3-50 characters (letters, numbers, . _ -).";
    }
    foreach (['admin_pass' => 'Admin', 'staff_pass' => 'Staff'] as $k => $label) {
        if (strlen($in[$k]) < 8) $errors[] = "$label password must be at least 8 characters.";
    }
    if (strcasecmp($in['admin_user'], $in['staff_user']) === 0) $errors[] = 'Admin and staff usernames must be different.';
    if ($existingRows > 0 && trim($_POST['confirm'] ?? '') !== 'RESET') $errors[] = 'The database is not empty. Type RESET (capital letters) to confirm the wipe.';

    if (!$errors) {
        try {
            wipe_all($conn, $TABLES);
            $stats  = seed_everything($conn, $in, $withTx, $D);
            $checks = run_checks($conn, $in, $stats, $D, $withTx);
            $rows   = [];
            foreach ($TABLES as $t) $rows[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $result = ['rows' => $rows, 'checks' => $checks, 'admin' => $in['admin_user']];
            // the old login no longer exists in the database, so sign out of the old session
            foreach (['user_id', 'username', 'full_name', 'role'] as $k) unset($_SESSION[$k]);
        } catch (Throwable $ex) {
            $errors[] = 'Load failed and was rolled back: ' . $ex->getMessage();
        }
    }
}

/* =====================================================================
   6. THE PAGE
   ===================================================================== */

page_open('Load all data');

foreach ($errors as $err) echo '<div class="alert alert-error">' . e($err) . '</div>';

if ($result):
    $allPass = !in_array(false, array_column($result['checks'], 1), true);
    ?>
    <div class="alert <?= $allPass ? 'alert-success' : 'alert-error' ?>">
        <?= $allPass ? 'Everything loaded and every check passed.' : 'Data loaded, but at least one check FAILED. Read the table below.' ?>
        Now <strong>delete <code>seed_all.php</code></strong> and <a href="login.php">log in</a> as <strong><?= e($result['admin']) ?></strong>.
    </div>

    <div class="card flush">
        <h3>Checks</h3>
        <table>
            <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($result['checks'] as [$label, $pass, $detail]): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td><span class="badge <?= $pass ? '' : 'badge-off' ?>"><?= $pass ? 'PASS' : 'FAIL' ?></span></td>
                    <td class="muted"><?= e($detail) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card flush" style="margin-top:20px">
        <h3>Rows now in each table</h3>
        <table>
            <thead><tr><th>Table</th><th class="num">Rows</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $t => $c): ?>
                <tr><td><?= e($t) ?></td><td class="num"><?= (int)$c ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
else:
    $byCat = [];
    foreach ($PRODUCTS as [$name, $cat, $size]) $byCat[$cat][] = $name . ($size ? " ($size)" : '');
    ?>
    <div class="card">
        <h3>What this will load</h3>
        <p><strong><?= count($CATEGORIES) ?></strong> categories, <strong><?= count($SIZES) ?></strong> sizes, <strong><?= count($PRODUCTS) ?></strong> products, <strong><?= count($SUPPLIERS) ?></strong> suppliers and <strong>2</strong> users.
           Optionally 8 days of sample purchases, sales, FOC, adjustments and daily counts for <?= count($SAMPLE) ?> products, followed by automatic checks.</p>
        <p class="muted">Products listed without a size in brackets (Label, Whisky, Wine, Beer, Cigarettes, towels) get their size later, from the Size dropdown in Edit Product.</p>
        <?php foreach ($byCat as $cat => $list): ?>
            <details style="margin:6px 0">
                <summary><strong><?= e($cat) ?></strong> <span class="muted">(<?= count($list) ?>)</span></summary>
                <div class="muted" style="padding:6px 0 4px 18px"><?= e(implode(', ', $list)) ?></div>
            </details>
        <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:20px">
        <h3>Current database</h3>
        <?php if ($existingRows > 0): ?>
            <div class="alert alert-error">There are <strong><?= (int)$existingRows ?></strong> rows in the tables. Loading will <strong>delete all of them first</strong>, including users.</div>
        <?php else: ?>
            <p>All tables are empty. Nothing will be deleted.</p>
        <?php endif; ?>

        <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?>
            <div><label>Admin username</label><input type="text" name="admin_user" maxlength="50" required value="<?= e($form['admin_user']) ?>"></div>
            <div><label>Admin password (min 8)</label><input type="password" name="admin_pass" minlength="8" required autocomplete="new-password"></div>
            <div><label>Staff username</label><input type="text" name="staff_user" maxlength="50" required value="<?= e($form['staff_user']) ?>"></div>
            <div><label>Staff password (min 8)</label><input type="password" name="staff_pass" minlength="8" required autocomplete="new-password"></div>
            <?php if ($existingRows > 0): ?>
                <div><label>Type RESET to confirm</label><input type="text" name="confirm" required></div>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="with_tx" <?= $form['with_tx'] ? 'checked' : '' ?>> Also load sample transactions</label>
            <div class="actions"><button class="btn <?= $existingRows > 0 ? 'btn-danger' : '' ?>" type="submit">Load data</button></div>
        </form>
    </div>
    <?php
endif;

page_close();