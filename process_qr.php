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
$device_time = trim($_POST['device_time'] ?? date('Y-m-d H:i:s')); // Oras base sa device ng estudyante

if (empty($qr_data)) {
    echo json_encode(['status' => 'error', 'message' => 'Walang nareceive na QR data.']);
    exit();
}

// Parse QR Code Data (Suportahan pareho ang JSON mula sa generate_teacher_qr.php at legacy formats)
$subject_id = null;
$json_decoded = json_decode($qr_data, true);
$qr_session_id = null;

if (is_array($json_decoded)) {
    if (isset($json_decoded['class_id'])) {
        $subject_id = intval($json_decoded['class_id']);
    } elseif (isset($json_decoded['subject_id'])) {
        $subject_id = intval($json_decoded['subject_id']);
    }
    // Kung kasama sa JSON ang session ID o creation time ng QR ng teacher
    if (isset($json_decoded['session_id'])) {
        $qr_session_id = intval($json_decoded['session_id']);
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

// Kunin ang oras ng pag-generate ng QR ng teacher (Mula sa active session o table ng teacher QR logs)
$qr_generated_at = null;
if ($qr_session_id) {
    // Halimbawa kung may table kang pinag-iimbakan ng active sessions/QR codes ng teacher
    $stmt_sess = $conn->prepare("SELECT created_at FROM teacher_qr_sessions WHERE id = ?");
    if ($stmt_sess) {
        $stmt_sess->bind_param("i", $qr_session_id);
        $stmt_sess->execute();
        $sess_res = $stmt_sess->get_result();
        if ($sess_res->num_rows > 0) {
            $qr_generated_at = $sess_res->fetch_assoc()['created_at'];
        }
        $stmt_sess->close();
    }
}

// Fallback kung walang hiwalay na session table: Hanapin ang pinakabagong bukas na session para sa subject na ito ngayong araw
if (!$qr_generated_at) {
    $stmt_latest_qr = $conn->prepare("SELECT created_at FROM teacher_qr_sessions WHERE subject_id = ? ORDER BY created_at DESC LIMIT 1");
    if ($stmt_latest_qr) {
        $stmt_latest_qr->bind_param("i", $subject_id);
        $stmt_latest_qr->execute();
        $l_res = $stmt_latest_qr->get_result();
        if ($l_res->num_rows > 0) {
            $qr_generated_at = $l_res->fetch_assoc()['created_at'];
        }
        $stmt_latest_qr->close();
    }
}

// Kung wala talagang mahanap na timestamp ng pagka-generate, gamitin ang device time o current time bilang safety fallback
if (!$qr_generated_at) {
    $qr_generated_at = $device_time;
}

// Suriin kung nakapag-attendance na ngayong araw
$today = date('Y-m-d', strtotime($device_time));
$stmt_att_check = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND subject_id = ? AND DATE(scanned_at) = ?");
$stmt_att_check->bind_param("iis", $student_id, $subject_id, $today);
$stmt_att_check->execute();
$att_res = $stmt_att_check->get_result();

if ($att_res->num_rows > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Naka-record na ang iyong attendance para sa araw na ito!']);
    exit();
}

// I-record ang attendance kasama ang qr_generated_at at ang device scan time (scanned_at)
$stmt_ins = $conn->prepare("INSERT INTO attendance (student_id, subject_id, status, qr_generated_at, scanned_at) VALUES (?, ?, 'Present', ?, ?)");
$stmt_ins->bind_param("iiss", $student_id, $subject_id, $qr_generated_at, $device_time);

if ($stmt_ins->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Matagumpay na na-record ang iyong attendance!']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'May naganap na error sa database: ' . $conn->error]);
}
exit();
?>
