<?php
// One-time upgrade for databases created BEFORE the WH / Sale split.
// Log in as admin, open this page once, then DELETE this file. Safe to run twice.
// Fresh installs (create_table.php) already have these changes and don't need it.

require_once __DIR__ . '/auth/check_auth.php';
require_admin();

$log = [];

function col_exists(PDO $conn, string $table, string $col): bool
{
    $st = $conn->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

function index_exists(PDO $conn, string $table, string $index): bool
{
    $st = $conn->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $index]);
    return (bool)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        // 1. FOC type
        if (!col_exists($conn, 'foc_records', 'foc_type')) {
            $conn->exec("ALTER TABLE foc_records ADD COLUMN foc_type ENUM('received','given') NOT NULL DEFAULT 'given' AFTER quantity");
            $log[] = 'foc_records: added foc_type (existing rows = given to customer).';
        } else {
            $log[] = 'foc_records: already upgraded.';
        }

        // 2. Adjustment location
        if (!col_exists($conn, 'stock_adjustments', 'location')) {
            $conn->exec("ALTER TABLE stock_adjustments ADD COLUMN location ENUM('wh','sale') NOT NULL DEFAULT 'sale' AFTER adjustment_type");
            $log[] = 'stock_adjustments: added location (existing rows = Sale).';
        } else {
            $log[] = 'stock_adjustments: already upgraded.';
        }

        // 3. Daily inventory location + new unique key (add the new key BEFORE dropping the old one)
        if (!col_exists($conn, 'daily_inventory', 'location')) {
            $conn->exec("ALTER TABLE daily_inventory ADD COLUMN location ENUM('wh','sale') NOT NULL DEFAULT 'sale' AFTER inventory_date");
            $log[] = 'daily_inventory: added location (existing rows = Sale).';
        } else {
            $log[] = 'daily_inventory: location already exists.';
        }
        if (!index_exists($conn, 'daily_inventory', 'uq_daily_product_location')) {
            $conn->exec('ALTER TABLE daily_inventory ADD UNIQUE KEY uq_daily_product_location (product_id, inventory_date, location)');
            $log[] = 'daily_inventory: added unique key (product, date, location).';
        }
        if (index_exists($conn, 'daily_inventory', 'uq_daily_product')) {
            $conn->exec('ALTER TABLE daily_inventory DROP INDEX uq_daily_product');
            $log[] = 'daily_inventory: removed old unique key.';
        }

        // 4. Transfers table
        $conn->exec("
            CREATE TABLE IF NOT EXISTS stock_transfers (
                transfer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                transfer_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                description TEXT NULL,
                created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_transfers_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT fk_transfers_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB
        ");
        $log[] = 'stock_transfers: ready.';
    } catch (Throwable $ex) {
        $log[] = 'FAILED: ' . $ex->getMessage();
    }
}

$pageTitle = 'Upgrade database';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <h3>Upgrade to WH / Sale stock</h3>
    <p>Adds the new columns and the transfers table. No data is deleted.</p>
    <p class="muted">Old purchases then count as WH stock, and old sales, FOC and counts are treated as Sale. If you only have test data, run <code>seed_test_data.php</code> afterwards to load a clean WH / Sale scenario.</p>
    <form method="post">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Run upgrade</button>
    </form>
</div>

<?php if ($log): ?>
    <div class="card">
        <h3>Result</h3>
        <ul>
            <?php foreach ($log as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>
        <p>Now delete <code>migrate_wh_sale.php</code>.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
