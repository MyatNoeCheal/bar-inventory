<?php
// Shared stock and cost helpers.

/**
 * Current stock for all products (or one). Returns [product_id => int stock].
 * Baseline = latest physical closing count (daily_inventory) if any, then movements dated AFTER that count date.
 */
function get_stock_map(PDO $conn, ?int $productId = null): array
{
    $sql = "SELECT p.product_id,
        COALESCE(d.physical_closing, 0)
        + COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi
                    JOIN purchases pu ON pu.purchase_id = pi.purchase_id
                    WHERE pi.product_id = p.product_id
                      AND (d.inventory_date IS NULL OR DATE(pu.purchase_date) > d.inventory_date)), 0)
        - COALESCE((SELECT SUM(si.quantity) FROM sale_items si
                    JOIN sales s ON s.sale_id = si.sale_id
                    WHERE si.product_id = p.product_id AND s.status = 'completed'
                      AND (d.inventory_date IS NULL OR DATE(s.sale_date) > d.inventory_date)), 0)
        - COALESCE((SELECT SUM(f.quantity) FROM foc_records f
                    WHERE f.product_id = p.product_id
                      AND (d.inventory_date IS NULL OR DATE(f.foc_date) > d.inventory_date)), 0)
        + COALESCE((SELECT SUM(a.quantity) FROM stock_adjustments a
                    WHERE a.product_id = p.product_id
                      AND (d.inventory_date IS NULL OR DATE(a.adjustment_date) > d.inventory_date)), 0)
        AS stock
        FROM products p
        LEFT JOIN daily_inventory d ON d.product_id = p.product_id
             AND d.inventory_date = (SELECT MAX(d2.inventory_date) FROM daily_inventory d2 WHERE d2.product_id = p.product_id)";
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

function get_stock(PDO $conn, int $productId): int
{
    return get_stock_map($conn, $productId)[$productId] ?? 0;
}

/** Unit cost from the most recent purchase of this product (0 if never purchased). */
function get_latest_cost(PDO $conn, int $productId): float
{
    $st = $conn->prepare(
        'SELECT pi.unit_price FROM purchase_items pi
         JOIN purchases pu ON pu.purchase_id = pi.purchase_id
         WHERE pi.product_id = ?
         ORDER BY pu.purchase_date DESC, pi.purchase_item_id DESC
         LIMIT 1'
    );
    $st->execute([$productId]);
    return (float)($st->fetchColumn() ?: 0);
}

/**
 * Figures for one inventory day, for every product: [product_id => [...]].
 *  opening     = last physical count before $date + any movements between that count and $date
 *                (with no earlier count: everything before $date)
 *  purchases / sales / foc / adjustments = movements ON $date
 *  expected    = opening + purchases - sales - foc + adjustments
 * $date must be 'Y-m-d'.
 */
function get_daily_figures(PDO $conn, string $date): array
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Bad date');
    }
    $d = $conn->quote($date);

    $before = fn($x) => "$x < $d AND (b.inventory_date IS NULL OR $x > b.inventory_date)";
    $on     = fn($x) => "$x = $d";

    $purch = fn($c) => "COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi
                JOIN purchases pu ON pu.purchase_id = pi.purchase_id
                WHERE pi.product_id = p.product_id AND " . $c('DATE(pu.purchase_date)') . "), 0)";
    $sales = fn($c) => "COALESCE((SELECT SUM(si.quantity) FROM sale_items si
                JOIN sales s ON s.sale_id = si.sale_id
                WHERE si.product_id = p.product_id AND s.status = 'completed' AND " . $c('DATE(s.sale_date)') . "), 0)";
    $foc   = fn($c) => "COALESCE((SELECT SUM(f.quantity) FROM foc_records f
                WHERE f.product_id = p.product_id AND " . $c('DATE(f.foc_date)') . "), 0)";
    $adj   = fn($c) => "COALESCE((SELECT SUM(a.quantity) FROM stock_adjustments a
                WHERE a.product_id = p.product_id AND " . $c('DATE(a.adjustment_date)') . "), 0)";

    $sql = "SELECT p.product_id,
                COALESCE(b.physical_closing, 0)
                  + {$purch($before)} - {$sales($before)} - {$foc($before)} + {$adj($before)} AS opening,
                {$purch($on)} AS purchases,
                {$sales($on)} AS sales,
                {$foc($on)}   AS foc,
                {$adj($on)}   AS adjustments
            FROM products p
            LEFT JOIN daily_inventory b ON b.product_id = p.product_id
                 AND b.inventory_date = (SELECT MAX(b2.inventory_date) FROM daily_inventory b2
                                         WHERE b2.product_id = p.product_id AND b2.inventory_date < $d)";

    $out = [];
    foreach ($conn->query($sql)->fetchAll() as $r) {
        $row = [
            'opening'     => (int)$r['opening'],
            'purchases'   => (int)$r['purchases'],
            'sales'       => (int)$r['sales'],
            'foc'         => (int)$r['foc'],
            'adjustments' => (int)$r['adjustments'],
        ];
        $row['expected'] = $row['opening'] + $row['purchases'] - $row['sales'] - $row['foc'] + $row['adjustments'];
        $out[(int)$r['product_id']] = $row;
    }
    return $out;
}