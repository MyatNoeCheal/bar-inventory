<?php

$hostname = "localhost";
$dbname   = "bar_inventory_db";
$username = "root";
$password = "";

try {
    $conn = new PDO(
        "mysql:host=$hostname;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Connection Failed: " . $e->getMessage());
}

?>