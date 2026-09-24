<?php
ob_start();
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in.']);
    exit();
}

$student_id = $_SESSION['user_id'];
$qr_data = trim($_POST['qr_data'] ?? '');

if (empty($qr_data)) {
    echo json_encode(['status' => 'error', 'message' => 'Walang nareceive na QR data.']);
    exit();
}

// Parse QR Code Data (Suportahan pareho ang JSON mula sa generate_teacher_qr.php at legacy formats)
$subject_id = null;
$json_decoded = json_decode($qr_data, true);

if (is_array($json_decoded)) {
    if (isset($json_decoded['class_id'])) {
        $subject_id = intval($json_decoded['class_id']);
    } elseif (isset($json_decoded['subject_id'])) {
        $subject_id = intval($json_decoded['subject_id']);
    }
} else {
    $parts = explode('_', $qr_data);
    if (count($parts) >= 3 && $parts[0] === 'ATTENDANCE' && $parts[1] === 'SUBJ') {
        $subject_id = intval($parts[2]);
    } else if (is_numeric($qr_data)) {
        $subject_id = intval($qr_data);
    }
}

if (!$subject_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid o hindi kilalang QR Code format.']);
    exit();
}

// Suriin kung naka-enroll ang estudyante
$stmt_check_enroll = $conn->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND subject_id = ?");
$stmt_check_enroll->bind_param("ii", $student_id, $subject_id);
$stmt_check_enroll->execute();
$enroll_res = $stmt_check_enroll->get_result();

if ($enroll_res->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Hindi ka naka-enroll sa asignaturang ito.']);
    exit();
}

// Suriin kung nakapag-attendance na ngayong araw
$today = date('Y-m-d');
$stmt_att_check = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND subject_id = ? AND DATE(scanned_at) = ?");
$stmt_att_check->bind_param("iis", $student_id, $subject_id, $today);
$stmt_att_check->execute();
$att_res = $stmt_att_check->get_result();

if ($att_res->num_rows > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Naka-record na ang iyong attendance para sa araw na ito!']);
    exit();
}

// I-record ang attendance
$stmt_ins = $conn->prepare("INSERT INTO attendance (student_id, subject_id, status, scanned_at) VALUES (?, ?, 'Present', NOW())");
$stmt_ins->bind_param("ii", $student_id, $subject_id);

if ($stmt_ins->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Matagumpay na na-record ang iyong attendance!']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'May naganap na error sa database.']);
}
exit();
?>