<?php
$host = getenv('MYSQLHOST') ?: "localhost";
$user = getenv('MYSQLUSER') ?: "root";
$password = getenv('MYSQLPASSWORD') ?: "";
$dbname = getenv('MYSQLDATABASE') ?: "webyte";
$port = getenv('MYSQLPORT') ?: 3306;

try {
    $conn = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
