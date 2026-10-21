<?php
// Shared stock and cost helpers.
//
// Stock lives in two places:
//   WH   (warehouse): + purchases, + FOC received from suppliers, - transfers to Sale, +/- WH adjustments
//   Sale (sale section): + transfers from WH, - sales, - FOC given to customers, +/- Sale adjustments
//
// Each location has its own daily physical count. Current stock = latest count (the baseline)
// + movements dated AFTER that count date.

const STOCK_LOCATIONS = ['wh' => 'WH', 'sale' => 'Sale'];

// location => [component => [output name, sign]]
const STOCK_FORMULA = [
    'wh' => [
        'purchases'   => ['purchases',    +1],
        'foc_in'      => ['foc_in',       +1],
        'transfers'   => ['transfer_out', -1],
        'adjustments' => ['adjustments',  +1],
    ],
    'sale' => [
        'transfers'   => ['transfer_in',  +1],
        'sales'       => ['sales',        -1],
        'foc_out'     => ['foc_out',      -1],
        'adjustments' => ['adjustments',  +1],
    ],
];

function check_location(string $location): string
{
    if (!isset(STOCK_LOCATIONS[$location])) {
        throw new InvalidArgumentException('Bad stock location');
    }
    return $location;
}

function location_label(string $location): string
{
    return STOCK_LOCATIONS[$location] ?? $location;
}

/**
 * SQL for one stock movement (a summed scalar subquery for product alias p).
 * $c builds the date condition from a date expression.
 */
function stock_expr(string $component, callable $c, string $location): string
{
    switch ($component) {
        case 'purchases':
            return "COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi
                        JOIN purchases pu ON pu.purchase_id = pi.purchase_id
                        WHERE pi.product_id = p.product_id AND pu.status = 'completed'
                          AND " . $c('DATE(pu.purchase_date)') . "), 0)";
        case 'foc_in':
            return "COALESCE((SELECT SUM(f.quantity) FROM foc_records f
                        WHERE f.product_id = p.product_id AND f.foc_type = 'received'
                          AND " . $c('DATE(f.foc_date)') . "), 0)";
        case 'foc_out':
            return "COALESCE((SELECT SUM(f.quantity) FROM foc_records f
                        WHERE f.product_id = p.product_id AND f.foc_type = 'given'
                          AND " . $c('DATE(f.foc_date)') . "), 0)";
        case 'transfers':
            return "COALESCE((SELECT SUM(t.quantity) FROM stock_transfers t
                        WHERE t.product_id = p.product_id
                          AND " . $c('DATE(t.transfer_date)') . "), 0)";
        case 'sales':
            return "COALESCE((SELECT SUM(si.quantity) FROM sale_items si
                        JOIN sales s ON s.sale_id = si.sale_id
                        WHERE si.product_id = p.product_id AND s.status = 'completed'
                          AND " . $c('DATE(s.sale_date)') . "), 0)";
        case 'adjustments':
            return "COALESCE((SELECT SUM(a.quantity) FROM stock_adjustments a
                        WHERE a.product_id = p.product_id AND a.location = '" . check_location($location) . "'
                          AND " . $c('DATE(a.adjustment_date)') . "), 0)";
    }
    throw new InvalidArgumentException('Bad stock component');
}

/**
 * Current stock of one location for all products (or one). Returns [product_id => int stock].
 */
function get_stock_map(PDO $conn, string $location, ?int $productId = null): array
{
    check_location($location);
    $L = $conn->quote($location);

    $after = fn($x) => "(d.inventory_date IS NULL OR $x > d.inventory_date)";

    $expr = 'COALESCE(d.physical_closing, 0)';
    foreach (STOCK_FORMULA[$location] as $comp => [, $sign]) {
        $expr .= ($sign > 0 ? ' + ' : ' - ') . stock_expr($comp, $after, $location);
    }

    $sql = "SELECT p.product_id, $expr AS stock
            FROM products p
            LEFT JOIN daily_inventory d ON d.product_id = p.product_id AND d.location = $L
                 AND d.inventory_date = (SELECT MAX(d2.inventory_date) FROM daily_inventory d2
                                         WHERE d2.product_id = p.product_id AND d2.location = $L)";
    $params = [];
    if ($productId !== null) {
        $sql .= ' WHERE p.product_id = ?';
        $params[] = $productId;
    }

    $st = $conn->prepare($sql);
    $st->execute($params);

    $map = [];
    foreach ($st->fetchAll() as $row) {
        $map[(int)$row['product_id']] = (int)$row['stock'];
    }
    return $map;
}

function get_stock(PDO $conn, int $productId, string $location): int
{
    return get_stock_map($conn, $location, $productId)[$productId] ?? 0;
}

/** Unit cost from the most recent completed purchase of this product (0 if never purchased). */
function get_latest_cost(PDO $conn, int $productId): float
{
    $st = $conn->prepare(
        'SELECT pi.unit_price FROM purchase_items pi
         JOIN purchases pu ON pu.purchase_id = pi.purchase_id
         WHERE pi.product_id = ? AND pu.status = \'completed\'
         ORDER BY pu.purchase_date DESC, pi.purchase_item_id DESC
         LIMIT 1'
    );
    $st->execute([$productId]);
    return (float)($st->fetchColumn() ?: 0);
}

/**
 * Figures for one inventory day and one location, for every product: [product_id => [...]].
 *  opening     = last physical count of that location before $date + movements between that count and $date
 *                (with no earlier count: everything before $date)
 *  purchases, foc_in, transfer_in, transfer_out, sales, foc_out, adjustments = movements ON $date
 *                (keys that don't apply to the location are 0)
 *  expected    = opening + (the location's movements, signed)
 * $date must be 'Y-m-d'.
 */
function get_daily_figures(PDO $conn, string $date, string $location): array
{
    check_location($location);
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Bad date');
    }
    $d = $conn->quote($date);
    $L = $conn->quote($location);

    $before = fn($x) => "$x < $d AND (b.inventory_date IS NULL OR $x > b.inventory_date)";
    $on     = fn($x) => "$x = $d";

    $openExpr = 'COALESCE(b.physical_closing, 0)';
    $selects  = [];
    foreach (STOCK_FORMULA[$location] as $comp => [$alias, $sign]) {
        $openExpr .= ($sign > 0 ? ' + ' : ' - ') . stock_expr($comp, $before, $location);
        $selects[] = stock_expr($comp, $on, $location) . " AS $alias";
    }

    $sql = "SELECT p.product_id, $openExpr AS opening, " . implode(', ', $selects) . "
            FROM products p
            LEFT JOIN daily_inventory b ON b.product_id = p.product_id AND b.location = $L
                 AND b.inventory_date = (SELECT MAX(b2.inventory_date) FROM daily_inventory b2
                                         WHERE b2.product_id = p.product_id AND b2.location = $L
                                           AND b2.inventory_date < $d)";

    $out = [];
    foreach ($conn->query($sql)->fetchAll() as $r) {
        $row = ['opening' => (int)$r['opening'], 'purchases' => 0, 'foc_in' => 0, 'transfer_in' => 0,
                'transfer_out' => 0, 'sales' => 0, 'foc_out' => 0, 'adjustments' => 0];
        $expected = $row['opening'];
        foreach (STOCK_FORMULA[$location] as [$alias, $sign]) {
            $row[$alias] = (int)$r[$alias];
            $expected   += $sign * $row[$alias];
        }
        $row['expected'] = $expected;
        $out[(int)$r['product_id']] = $row;
    }
    return $out;
}

/**
 * Products whose latest daily count (in any of $locations) is on or after $date ('Y-m-d').
 * A counted day is closed: stock is rebuilt from the count, so a movement dated
 * on or before it would be saved but never change stock. Callers must refuse it.
 * Returns [product_id => 'Y-m-d' latest count date].
 */
function get_closed_products(PDO $conn, array $productIds, string $date, array $locations = ['wh', 'sale']): array
{
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if (!$productIds) return [];
    $locs = implode(',', array_map(fn($l) => $conn->quote(check_location($l)), $locations));
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $st = $conn->prepare(
        "SELECT product_id, MAX(inventory_date) AS d
         FROM daily_inventory WHERE product_id IN ($in) AND location IN ($locs)
         GROUP BY product_id HAVING MAX(inventory_date) >= ?"
    );
    $st->execute(array_merge($productIds, [$date]));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['product_id']] = $r['d'];
    return $out;
}

/** Standard "closed day" error message. */
function closed_error(string $label, string $countDate): string
{
    return $label . ': already counted on ' . date('d-M-Y', strtotime($countDate)) . ', so that date is closed. Use a later date.';
}

/**
 * Deleting a record reverses its effect on stock. Is that reversal safe?
 *  $delta = change to the location's stock caused by deleting it (e.g. deleting FOC received of 5 => -5).
 * Returns ['ok' => bool, 'counted' => bool, 'message' => string].
 *  counted = the record's date is on/before the latest count, so the count already reflects
 *            reality and current stock does NOT change (only reports and that day's saved difference do).
 */
function check_reversal(PDO $conn, int $productId, string $location, int $delta, string $recordDate): array
{
    $counted = (bool)get_closed_products($conn, [$productId], date('Y-m-d', strtotime($recordDate)), [$location]);
    if ($counted) {
        return ['ok' => true, 'counted' => true, 'message' => ''];
    }
    if ($delta < 0) {
        $have = get_stock($conn, $productId, $location);
        if ($have + $delta < 0) {
            return ['ok' => false, 'counted' => false,
                    'message' => 'only ' . $have . ' in ' . location_label($location) . ' stock, so removing this record (' . $delta . ') would make stock negative'];
        }
    }
    return ['ok' => true, 'counted' => false, 'message' => ''];
}
