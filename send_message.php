<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$sender_id = $_SESSION['user_id'];
$receiver_id = $_POST['user_id'] ?? 0;
$message = trim($_POST['message'] ?? '');
$reply_to = $_POST['reply_to'] ?? null;

if (!$receiver_id || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit();
}

$stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, reply_to, created_at) VALUES (?, ?, ?, ?, NOW())");
$stmt->bind_param("iiss", $sender_id, $receiver_id, $message, $reply_to);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}

$stmt->close();
$conn->close();
?>