<?php
$host = "localhost";
$user = "root";
$password = "";
$dbname = "webyte";

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");
?>