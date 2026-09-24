<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

// 1. Suriin kung naka-login ang estudyante
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit();
}

// 2. Kunin ang QR Code Data (Suportado ang parehong Form Data at JSON Raw Body)
$input_data = json_decode(file_get_contents('php://input'), true);
$qr_code = '';

if (isset($input_data['qr_code'])) {
    $qr_code = trim($input_data['qr_code']);
} elseif (isset($input_data['qr_data'])) {
    $qr_code = trim($input_data['qr_data']);
} elseif (isset($_POST['qr_data'])) {
    $qr_code = trim($_POST['qr_data']);
} elseif (isset($_POST['qr_code'])) {
    $qr_code = trim($_POST['qr_code']);
}

if (empty($qr_code)) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Walang natanggap na QR Code data.']);
    exit();
}

// 3. I-verify ang QR Code mula sa qr_sessions table
$stmt = $conn->prepare("SELECT id, created_at FROM qr_sessions WHERE qr_code = ?");
$stmt->bind_param("s", $qr_code);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Invalid o hindi kilalang QR Code!']);
    exit();
}

$student_id = $_SESSION['user_id'];

// 4. Kunin ang mga detalye ng estudyante para sa pag-insert sa logs
$user_stmt = $conn->prepare("SELECT full_name, id_number, email FROM users WHERE id = ?");
$user_stmt->bind_param("i", $student_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$user_data) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Hindi nahanap ang profile ng estudyante.']);
    exit();
}

// 5. Suriin kung nakapag-scan na ang estudyante ngayong araw
$check = $conn->prepare("SELECT id FROM attendance_logs WHERE student_id = ? AND DATE(scan_datetime) = CURDATE()");
$check->bind_param("i", $student_id);
$check->execute();
$check_result = $check->get_result();

if ($check_result->num_rows > 0) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Naka-scan ka na ng attendance ngayong araw!']);
    $check->close();
    exit();
}
$check->close();

// 6. Compute ng remarks / status base sa oras ng pag-generate ng QR Code
$generated_time = strtotime($session['created_at']);
$current_time = time();
$diff_in_minutes = round(($current_time - $generated_time) / 60);

if ($diff_in_minutes <= 5) {
    $status = 'Present';
} elseif ($diff_in_minutes <= 15) {
    $status = 'Late';
} else {
    $status = 'Absent';
}

// 7. I-save ang attendance record sa attendance_logs table
$insert = $conn->prepare("
    INSERT INTO attendance_logs (student_id, student_name, student_number, email, scan_datetime, status) 
    VALUES (?, ?, ?, ?, NOW(), ?)
");
$insert->bind_param("issss", $student_id, $user_data['full_name'], $user_data['id_number'], $user_data['email'], $status);

if ($insert->execute()) {
    echo json_encode([
        'success' => true, 
        'status' => 'success', 
        'message' => "Matagumpay na naitala ang iyong attendance: $status"
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'status' => 'error', 
        'message' => 'Database error. Nabuag ang pag-record ng attendance.'
    ]);
}

$insert->close();
$conn->close();
?>