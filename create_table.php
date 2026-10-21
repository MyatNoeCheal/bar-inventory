<?php

$hostname = "localhost";
$username = "root";
$password = "";

try {

    // =====================================================
    // 1. CONNECT TO MYSQL SERVER
    // =====================================================

    $conn = new PDO(
        "mysql:host=$hostname;charset=utf8mb4",
        $username,
        $password
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


    // =====================================================
    // 2. CREATE DATABASE
    // =====================================================

    $conn->exec("
        CREATE DATABASE IF NOT EXISTS bar_inventory_db
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    ");

    // Select database
    $conn->exec("USE bar_inventory_db");


    // =====================================================
    // 3. USERS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 4. CATEGORIES
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS categories (
            category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(50) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 5. SIZE / TYPE
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS size_types (
            size_type_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(50) NOT NULL,
            unit VARCHAR(20) NOT NULL DEFAULT 'piece',
            description VARCHAR(255) NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 6. PRODUCTS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS products (
            product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_name VARCHAR(100) NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            size_type_id INT UNSIGNED NULL,
            description TEXT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            CONSTRAINT fk_products_category
                FOREIGN KEY (category_id)
                REFERENCES categories(category_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,

            CONSTRAINT fk_products_size_type
                FOREIGN KEY (size_type_id)
                REFERENCES size_types(size_type_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 7. SUPPLIERS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            supplier_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(100) NOT NULL,
            phone VARCHAR(30) NULL,
            address VARCHAR(255) NULL,
            description TEXT NULL,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 8. PURCHASES
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS purchases (
            purchase_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT UNSIGNED NULL,
            purchase_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            description TEXT NULL,

            status ENUM('completed', 'voided')
                NOT NULL DEFAULT 'completed',

            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            CONSTRAINT fk_purchases_supplier
                FOREIGN KEY (supplier_id)
                REFERENCES suppliers(supplier_id)
                ON UPDATE CASCADE
                ON DELETE SET NULL,

            CONSTRAINT fk_purchases_user
                FOREIGN KEY (created_by)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 9. PURCHASE ITEMS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS purchase_items (
            purchase_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(14,2) NOT NULL,

            subtotal DECIMAL(14,2)
                GENERATED ALWAYS AS (quantity * unit_price) STORED,

            CONSTRAINT fk_purchase_items_purchase
                FOREIGN KEY (purchase_id)
                REFERENCES purchases(purchase_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,

            CONSTRAINT fk_purchase_items_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 10. SALES
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS sales (
            sale_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',

            status ENUM('completed', 'voided')
                NOT NULL DEFAULT 'completed',

            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            CONSTRAINT fk_sales_user
                FOREIGN KEY (created_by)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 11. SALE ITEMS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS sale_items (
            sale_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(14,2) NOT NULL,
            unit_cost DECIMAL(14,2) NOT NULL,

            subtotal DECIMAL(14,2)
                GENERATED ALWAYS AS (quantity * unit_price) STORED,

            CONSTRAINT fk_sale_items_sale
                FOREIGN KEY (sale_id)
                REFERENCES sales(sale_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE,

            CONSTRAINT fk_sale_items_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 12. FOC RECORDS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS foc_records (
            foc_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL,

            -- received = FOC from a supplier (adds WH stock)
            -- given    = FOC given to a customer (reduces Sale stock)
            foc_type ENUM('received', 'given')
                NOT NULL DEFAULT 'given',

            foc_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            description TEXT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            CONSTRAINT fk_foc_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,

            CONSTRAINT fk_foc_user
                FOREIGN KEY (created_by)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 13. STOCK ADJUSTMENTS
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS stock_adjustments (
            adjustment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            quantity INT NOT NULL,

            adjustment_type ENUM(
                'damaged',
                'lost',
                'found',
                'correction',
                'other'
            ) NOT NULL,

            -- which stock the adjustment applies to
            location ENUM('wh', 'sale')
                NOT NULL DEFAULT 'sale',

            description TEXT NULL,
            adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            CONSTRAINT fk_adjustments_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,

            CONSTRAINT fk_adjustments_user
                FOREIGN KEY (created_by)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 14. DAILY INVENTORY
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS daily_inventory (
            daily_inventory_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            inventory_date DATE NOT NULL,

            -- each location (WH / Sale) is counted separately
            location ENUM('wh', 'sale')
                NOT NULL DEFAULT 'sale',

            opening_quantity INT NOT NULL DEFAULT 0,
            physical_closing INT NOT NULL DEFAULT 0,
            calculated_closing INT NOT NULL DEFAULT 0,
            difference INT NOT NULL DEFAULT 0,

            description TEXT NULL,
            counted_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uq_daily_product_location (
                product_id,
                inventory_date,
                location
            ),

            CONSTRAINT fk_daily_inventory_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,

            CONSTRAINT fk_daily_inventory_user
                FOREIGN KEY (counted_by)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // 15. STOCK TRANSFERS (WH -> SALE)
    // =====================================================

    $conn->exec("
        CREATE TABLE IF NOT EXISTS stock_transfers (
            transfer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            transfer_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            description TEXT NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            CONSTRAINT fk_transfers_product
                FOREIGN KEY (product_id)
                REFERENCES products(product_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT,

            CONSTRAINT fk_transfers_user
                FOREIGN KEY (created_by)
                REFERENCES users(user_id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB
    ");


    // =====================================================
    // SUCCESS
    // =====================================================

    echo "<h2>Database setup completed successfully!</h2>";
    echo "<p>Database: <strong>bar_inventory_db</strong></p>";
    echo "<p>All required tables have been created.</p>";

} catch (PDOException $e) {

    die(
        "<h2>Database setup failed!</h2>" .
        "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>"
    );
}

?>