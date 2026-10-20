<?php
$current = basename($_SERVER['SCRIPT_NAME']);

// Sub-pages highlight their parent menu item
$alias = [
    'product_add.php'  => 'products.php',  'product_edit.php'  => 'products.php',
    'supplier_add.php' => 'suppliers.php', 'supplier_edit.php' => 'suppliers.php',
    'purchase_add.php' => 'purchases.php', 'purchase_view.php' => 'purchases.php',
    'sale_add.php'     => 'sales.php',     'sale_view.php'     => 'sales.php',
];
$active = $alias[$current] ?? $current;

// Icons (stroke SVG paths)
$icons = [
    'dashboard'  => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'products'   => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    'stock'      => '<path d="M12 2l9 5-9 5-9-5 9-5z"/><path d="M3 12l9 5 9-5M3 17l9 5 9-5"/>',
    'categories' => '<path d="M20 12l-8 8-9-9V3h8l9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    'sizes'      => '<path d="M4 20L20 4M4 20h6M4 20v-6M20 4h-6M20 4v6"/>',
    'suppliers'  => '<path d="M1 6h13v10H1zM14 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'purchases'  => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
    'sales'      => '<path d="M12 21V9M7 14l5-5 5 5M4 4h16"/>',
    'foc'        => '<path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H8a2.5 2.5 0 110-5c2 0 4 2.5 4 5zM12 7h4a2.5 2.5 0 100-5c-2 0-4 2.5-4 5z"/>',
    'adjust'     => '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0zM12 9v4M12 17h.01"/>',
    'daily'      => '<path d="M9 4h6a1 1 0 011 1v1H8V5a1 1 0 011-1z"/><path d="M8 6H6a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V8a2 2 0 00-2-2h-2M9 14l2 2 4-4"/>',
    'reports'    => '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
    'users'      => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8"/>',
];

$groups = [
    'Overview' => [
        'dashboard.php' => ['Dashboard', 'dashboard'],
        'stock.php'     => ['Current Stock', 'stock'],
    ],
    'Catalog' => [
        'products.php'   => ['Products', 'products'],
        'categories.php' => ['Categories', 'categories'],
        'sizes.php'      => ['Sizes', 'sizes'],
        'suppliers.php'  => ['Suppliers', 'suppliers'],
    ],
    'Transactions' => [
        'purchases.php'   => ['Purchases (Stock In)', 'purchases'],
        'sales.php'       => ['Sales (Stock Out)', 'sales'],
        'foc.php'         => ['FOC', 'foc'],
        'adjustments.php' => ['Damaged / Lost', 'adjust'],
    ],
    'Inventory' => [
        'daily_inventory.php' => ['Daily Inventory', 'daily'],
        'reports.php'         => ['Reports', 'reports'],
    ],
];
if (is_admin()) {
    $groups['Admin'] = ['users.php' => ['Users', 'users']];
}
?>
<nav class="sidebar">
    <div class="brand">
        <div class="brand-mark">🍸</div>
        <div>Bar Inventory<small>Stock &amp; sales</small></div>
    </div>

    <?php foreach ($groups as $label => $links): ?>
        <div class="nav-label"><?= e($label) ?></div>
        <ul>
            <?php foreach ($links as $file => [$text, $icon]): ?>
                <li>
                    <a href="<?= BASE_URL ?>/<?= e($file) ?>" class="<?= $active === $file ? 'active' : '' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$icon] ?></svg>
                        <?= e($text) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</nav>