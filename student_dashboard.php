<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$success_message = "";
$error_message = "";

// Handle joining a class via Class Code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['join_class'])) {
        $class_code = trim($_POST['class_code']);

        if (!empty($class_code)) {
            $stmt_find = $conn->prepare("SELECT id, subject_name, section_name FROM teacher_subjects WHERE class_code = ?");
            $stmt_find->bind_param("s", $class_code);
            $stmt_find->execute();
            $result_find = $stmt_find->get_result();

            if ($result_find->num_rows > 0) {
                $subject = $result_find->fetch_assoc();
                $subject_id = $subject['id'];

                $stmt_check = $conn->prepare("SELECT id FROM student_enrollments WHERE student_id = ? AND subject_id = ?");
                $stmt_check->bind_param("ii", $student_id, $subject_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();

                if ($result_check->num_rows === 0) {
                    $stmt_enroll = $conn->prepare("INSERT INTO student_enrollments (student_id, subject_id) VALUES (?, ?)");
                    $stmt_enroll->bind_param("ii", $student_id, $subject_id);
                    if ($stmt_enroll->execute()) {
                        $success_message = "Successfully enrolled in " . htmlspecialchars($subject['subject_name']) . " (" . htmlspecialchars($subject['section_name']) . ")!";
                    } else {
                        $error_message = "An error occurred while enrolling.";
                    }
                    $stmt_enroll->close();
                } else {
                    $error_message = "You are already enrolled in this class.";
                }
                $stmt_check->close();
            } else {
                $error_message = "Invalid or non-matching Class Code.";
            }
            $stmt_find->close();
        } else {
            $error_message = "Please enter the Class Code.";
        }
    } elseif (isset($_POST['update_profile'])) {
        $password = $_POST['password'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $first_name = $_POST['first_name'] ?? '';
        $middle_name = $_POST['middle_name'] ?? '';
        $role = $_POST['role'] ?? '';
        $id_number = $_POST['id_number'] ?? '';
        $email = $_POST['email'] ?? '';
        $section = $_POST['section'] ?? '';
        $profile_pic_path = null;

        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['profile_pic']['tmp_name'];
            $file_name = time() . '_' . basename($_FILES['profile_pic']['name']);
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $profile_pic_path = $upload_dir . $file_name;
            move_uploaded_file($file_tmp, $profile_pic_path);
        }

        $fields = [];
        $types = "";
        $params = [];

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $fields[] = "password = ?";
            $types .= "s";
            $params[] = $hashed_password;
        }
        if ($profile_pic_path) {
            $fields[] = "profile_pic = ?";
            $types .= "s";
            $params[] = $profile_pic_path;
        }
        if (isset($_POST['last_name'])) {
            $fields[] = "last_name = ?";
            $types .= "s";
            $params[] = $last_name;
        }
        if (isset($_POST['first_name'])) {
            $fields[] = "first_name = ?";
            $types .= "s";
            $params[] = $first_name;
        }
        if (isset($_POST['middle_name'])) {
            $fields[] = "middle_name = ?";
            $types .= "s";
            $params[] = $middle_name;
        }
        if (isset($_POST['role'])) {
            $fields[] = "role = ?";
            $types .= "s";
            $params[] = $role;
        }
        if (isset($_POST['id_number'])) {
            $fields[] = "id_number = ?";
            $types .= "s";
            $params[] = $id_number;
        }
        if (isset($_POST['email'])) {
            $fields[] = "email = ?";
            $types .= "s";
            $params[] = $email;
        }
        // Check section column support before adding to query updates
        $check_section_col_up = $conn->query("SHOW COLUMNS FROM users LIKE 'section'");
        $has_section_col_up = ($check_section_col_up && $check_section_col_up->num_rows > 0);
        if ($has_section_col_up && isset($_POST['section'])) {
            $fields[] = "section = ?";
            $types .= "s";
            $params[] = $section;
        }

        if (!empty($fields)) {
            $types .= "i";
            $params[] = $student_id;
            $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
            $stmt_up = $conn->prepare($sql);
            $stmt_up->bind_param($types, ...$params);
            $stmt_up->execute();
            $stmt_up->close();
        }

        header("Location: student_dashboard.php");
        exit();
    } elseif (isset($_POST['delete_account'])) {
        $stmt_del_enr = $conn->prepare("DELETE FROM student_enrollments WHERE student_id = ?");
        $stmt_del_enr->bind_param("i", $student_id);
        $stmt_del_enr->execute();
        $stmt_del_enr->close();

        $stmt_del_user = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt_del_user->bind_param("i", $student_id);
        $stmt_del_user->execute();
        $stmt_del_user->close();

        session_destroy();
        header("Location: login.php");
        exit();
    }
}

// Fetch student profile including section if available[cite: 6]
$check_section_col = $conn->query("SHOW COLUMNS FROM users LIKE 'section'");
$has_section_col = ($check_section_col && $check_section_col->num_rows > 0);

if ($has_section_col) {
    $stmt_user = $conn->prepare("SELECT first_name, middle_name, last_name, role, id_number, email, profile_pic, section FROM users WHERE id = ?");
} else {
    $stmt_user = $conn->prepare("SELECT first_name, middle_name, last_name, role, id_number, email, profile_pic FROM users WHERE id = ?");
}
$stmt_user->bind_param("i", $student_id);
$stmt_user->execute();
$user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

$student_section = $has_section_col ? ($user['section'] ?? 'N/A') : 'N/A';

// Automatically clear old enrollments if they do not match the student's current section[cite: 6]
$stmt_old_enrollments = $conn->prepare("
    DELETE se FROM student_enrollments se
    JOIN teacher_subjects ts ON se.subject_id = ts.id
    WHERE se.student_id = ? AND ts.section_name != ?
");
$stmt_old_enrollments->bind_param("is", $student_id, $student_section);
$stmt_old_enrollments->execute();
$stmt_old_enrollments->close();

// Build full name for display[cite: 6]
$full_name = trim(($user['first_name'] ?? '') . ' ' . (!empty($user['middle_name']) ? $user['middle_name'] . ' ' : '') . ($user['last_name'] ?? ''));

// Get name parts for modal[cite: 6]
$last_name = $user['last_name'] ?? '';
$first_name = $user['first_name'] ?? '';
$middle_name = $user['middle_name'] ?? '';

// Fetch enrolled classes including teacher's profile picture and name[cite: 6]
$stmt_enrolled = $conn->prepare("
    SELECT ts.id, ts.subject_name, ts.section_name, ts.class_code, CONCAT(u.first_name, ' ', u.last_name) as teacher_name, u.profile_pic as teacher_pic 
    FROM student_enrollments se 
    JOIN teacher_subjects ts ON se.subject_id = ts.id 
    JOIN users u ON ts.teacher_id = u.id 
    WHERE se.student_id = ?
    ORDER BY ts.subject_name ASC
");
$stmt_enrolled->bind_param("i", $student_id);
$stmt_enrolled->execute();
$enrolled_result = $stmt_enrolled->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - KLD Attendance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        body {
            background: linear-gradient(135deg, #f0f7f4 0%, #e8f5e9 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        .main-wrapper {
            flex: 1;
            padding: 15px 30px 20px 30px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            width: 100%;
            box-sizing: border-box;
        }

        .styled-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 25px 30px;
            box-shadow: 0 10px 30px rgba(0, 128, 0, 0.05);
            border: 1px solid rgba(16, 138, 0, 0.08);
            width: 100%;
            box-sizing: border-box;
        }

        .profile-card-layout {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #ffffff 0%, #f4fbf7 100%);
            border-left: 6px solid #108a00;
            position: relative;
        }

        .signed-in-badge {
            font-size: 12px;
            font-weight: 700;
            color: #108a00;
            background: #e8f5e9;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .profile-right-container {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .btn-edit-profile {
            background: none;
            border: 1px solid #108a00;
            color: #108a00;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }

        .btn-edit-profile:hover {
            background: #108a00;
            color: #fff;
        }

        .action-buttons-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-action-icon {
            background-color: #108a00;
            color: #ffffff;
            border: none;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(16, 138, 0, 0.2);
            transition: all 0.2s ease;
            position: relative;
            text-decoration: none;
        }

        .btn-action-icon:hover {
            background-color: #0d6d00;
            transform: translateY(-2px);
        }

        .profile-content {
            display: flex;
            align-items: center;
            gap: 25px;
            flex: 1;
        }

        .user-profile-avatar {
            width: 85px;
            height: 85px;
            min-width: 85px;
            border-radius: 50%;
            background: #e8f5e9;
            color: #108a00;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            border: 3px solid #108a00;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            box-sizing: border-box;
        }

        .user-profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .user-details h2 {
            margin: 0 0 6px 0;
            color: #1a331e;
            font-size: 24px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .user-info-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin: 4px 0;
        }

        .user-details p {
            margin: 4px 0;
            color: #4f5d52;
            font-size: 13.5px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-details p i {
            color: #108a00;
            font-size: 14px;
            width: 16px;
            text-align: center;
        }

        .user-details p span {
            color: #2c3e50;
            font-weight: 600;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0;
        }

        .section-header h3 {
            font-size: 18px;
            font-weight: 800;
            color: #1a331e;
            margin: 0;
        }

        .header-right-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-add {
            background-color: #108a00;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: background-color 0.2s ease;
        }

        .btn-add:hover {
            background-color: #0d6d00;
        }

        .btn-scan-qr-header {
            background-color: #108a00;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s ease;
        }

        .btn-scan-qr-header:hover {
            background-color: #0d6d00;
        }

        .add-form-container {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #f8faf9;
            border-radius: 12px;
            border: 1px solid #eef2f0;
        }

        .add-form-container.active {
            display: block;
        }

        .form-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .form-input {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            border-color: #108a00;
        }

        .btn-enter {
            background-color: #108a00;
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .btn-enter:hover {
            background-color: #0d6d00;
        }

        .alert-success-banner {
            background-color: #d1e7dd;
            color: #0f5132;
            padding: 12px 20px;
            border-radius: 10px;
            border: 1px solid #badbcc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 14px;
            font-weight: 600;
        }

        .close-alert-btn {
            background: transparent;
            border: none;
            font-size: 20px;
            font-weight: bold;
            color: #0f5132;
            cursor: pointer;
            padding: 0 5px;
            line-height: 1;
        }

        .close-alert-btn:hover {
            color: #000;
        }

        .alert-error-banner {
            background-color: #f8d7da;
            color: #842029;
            padding: 12px 20px;
            border-radius: 10px;
            border: 1px solid #f5c2c7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 14px;
            font-weight: 600;
        }

        .subject-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .subject-card {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            border: 1px solid #eef2f0;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .subject-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 128, 0, 0.1);
        }

        .subject-card-banner {
            background: linear-gradient(135deg, #108a00 0%, #0d6d00 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            min-height: 110px;
            position: relative;
        }

        .teacher-card-avatar {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            background: #e8f5e9;
            color: #108a00;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            border: 3px solid #ffffff;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            box-sizing: border-box;
        }

        .teacher-card-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }

        .subject-card-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #ffffff;
            border-radius: 20px;
            padding: 25px 30px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
            position: relative;
            animation: modalFadeIn 0.2s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 12px;
        }

        .modal-header h4 {
            margin: 0;
            color: #1a331e;
            font-size: 18px;
            font-weight: 800;
        }

        .close-modal {
            font-size: 24px;
            font-weight: bold;
            color: #aaa;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }

        .close-modal:hover {
            color: #333;
        }

        .edit-profile-modal-content {
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .edit-form-group {
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .edit-form-group label {
            font-size: 12.5px;
            font-weight: 700;
            color: #1a331e;
        }

        .edit-form-group input, .edit-form-group textarea {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 13.5px;
        }

        .edit-modal-footer-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 15px;
        }

        .btn-submit-changes {
            background-color: #108a00;
            color: #fff;
            border: none;
            padding: 10px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit-changes:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        .btn-submit-changes:hover:not(:disabled) {
            background-color: #0d6d00;
        }

        .btn-delete-acc {
            background-color: #dc3545;
            color: #fff;
            border: none;
            padding: 10px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-delete-acc:hover {
            background-color: #b02a37;
        }

        .qr-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .qr-modal-box {
            background: #ffffff;
            border-radius: 20px;
            width: 90%;
            max-width: 420px;
            padding: 25px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            text-align: center;
            position: relative;
            animation: modalFadeIn 0.25s ease-out;
        }

        .qr-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #eef2f0;
            padding-bottom: 10px;
        }

        .qr-modal-header h3 {
            margin: 0;
            font-size: 17px;
            color: #108a00;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .close-qr-modal {
            background: transparent;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #888;
        }

        .close-qr-modal:hover { color: #333; }

        #qr-reader {
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #eef2f0;
        }

        #qr-status-msg {
            margin-top: 15px;
            font-size: 13.5px;
            font-weight: 600;
            padding: 10px;
            border-radius: 8px;
            display: none;
        }

        .status-success { background: #d1e7dd; color: #0f5132; }
        .status-error { background: #f8d7da; color: #842029; }
        .status-info { background: #cff4fc; color: #055160; }
    </style>
</head>
<body>
    <nav>
        <div class="logo-container">
            <img src="kld-logo.png" alt="KLD Logo" class="sidebar-logo">
            <h3 class="brand-title">KLD</h3>
            <span class="brand-subtitle">Attendance Monitoring System</span>
        </div>
        <div class="nav-links">
            <a href="student_dashboard.php"><i class="fa-solid fa-house"></i> Home</a>
            <a href="about.php"><i class="fa-solid fa-circle-info"></i> About us</a>
            <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Log-out</a>
            <a href="student_dashboard.php" class="active"><i class="fa-solid fa-user"></i> My Account</a>
        </div>
    </nav>

    <main class="main-wrapper">
        <div class="styled-card profile-card-layout">
            <div class="profile-content">
                <div class="user-profile-avatar">
                    <?php if (!empty($user['profile_pic']) && file_exists($user['profile_pic'])): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Profile Picture">
                    <?php else: ?>
                        <i class="fa-solid fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <h2><?php echo strtoupper(htmlspecialchars($full_name ?: 'Student')); ?></h2>
                    <div class="user-info-row">
                        <p><i class="fa-solid fa-id-card"></i> ID Number: <span><?php echo htmlspecialchars($user['id_number'] ?? 'N/A'); ?></span></p>
                        <p><i class="fa-solid fa-users"></i> Section: <span><?php echo htmlspecialchars($student_section); ?></span></p>
                    </div>
                    <p><i class="fa-solid fa-envelope"></i> Email: <span><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span></p>
                </div>
            </div>

            <div class="profile-right-container">
                <span class="signed-in-badge">Signed in as Student</span>
                <button type="button" class="btn-edit-profile" onclick="openEditProfileModal()">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Profile
                </button>
                <div class="action-buttons-group">
                    <button type="button" class="btn-action-icon" title="Notifications" onclick="alert('Notifications clicked')">
                        <i class="fa-solid fa-bell"></i>
                    </button>
                    <button type="button" class="btn-action-icon" title="Chat" onclick="alert('Chat clicked')">
                        <i class="fa-solid fa-comment-dots"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="styled-card">
            <div class="section-header">
                <h3>Enrolled Classes Overview</h3>
                <div class="header-right-actions">
                    <button type="button" class="btn-scan-qr-header" onclick="openQRModal()">
                        <i class="fa-solid fa-qrcode"></i> Scan QR Code
                    </button>
                    <button type="button" class="btn-add" id="toggleAddBtn" onclick="toggleAddForm()">
                        <i class="fa-solid fa-plus"></i> Add
                    </button>
                </div>
            </div>

            <div class="add-form-container <?php echo (!empty($success_message) || !empty($error_message)) ? 'active' : ''; ?>" id="addFormContainer">
                <?php if (!empty($success_message)): ?>
                    <div class="alert-success-banner" id="successAlert">
                        <span><?php echo $success_message; ?></span>
                        <button type="button" class="close-alert-btn" onclick="document.getElementById('successAlert').style.display='none';">&times;</button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert-error-banner" id="errorAlert">
                        <span><?php echo $error_message; ?></span>
                        <button type="button" class="close-alert-btn" onclick="document.getElementById('errorAlert').style.display='none';" style="color: #842029;">&times;</button>
                    </div>
                <?php endif; ?>
                <form action="student_dashboard.php" method="POST">
                    <div class="form-row">
                        <input type="text" name="class_code" class="form-input" placeholder="Enter Class Code (e.g. A3F89C)" required>
                        <button type="submit" name="join_class" class="btn-enter">Enter</button>
                    </div>
                </form>
            </div>

            <div class="subject-cards-grid">
                <?php if ($enrolled_result && $enrolled_result->num_rows > 0): ?>
                    <?php while ($class = $enrolled_result->fetch_assoc()): ?>
                        <a href="view_attendance.php?subject_id=<?php echo $class['id']; ?>" style="text-decoration: none; color: inherit;">
                            <div class="subject-card">
                                <div class="subject-card-banner">
                                    <div class="teacher-card-avatar">
                                        <?php if (!empty($class['teacher_pic']) && file_exists($class['teacher_pic'])): ?>
                                            <img src="<?php echo htmlspecialchars($class['teacher_pic']); ?>" alt="Teacher Profile">
                                        <?php else: ?>
                                            <i class="fa-solid fa-user"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="subject-card-body">
                                    <h4 style="margin: 0 0 5px 0; font-size: 18px; color: #1a331e; font-weight: 800;"><?php echo htmlspecialchars($class['subject_name']); ?></h4>
                                    <p style="margin: 0; font-size: 13px; color: #4f5d52;"><strong>Section:</strong> <?php echo htmlspecialchars($class['section_name']); ?></p>
                                    <p style="margin: 0; font-size: 13px; color: #4f5d52;"><strong>Instructor:</strong> <?php echo strtoupper(htmlspecialchars($class['teacher_name'])); ?></p>
                                </div>
                            </div>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="color: #888; font-size: 14px; grid-column: 1 / -1; text-align: center; padding: 25px;">You are not enrolled in any classes yet. Click "+ Add" and enter the Class Code provided by your instructor.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Edit Profile Modal -->
    <div id="editProfileModal" class="modal-overlay" onclick="closeEditProfileModal(event)">
        <div class="modal-content edit-profile-modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h4>Edit Profile Details</h4>
                <span class="close-modal" onclick="closeEditProfileModal()">&times;</span>
            </div>
            <form action="student_dashboard.php" method="POST" enctype="multipart/form-data" id="editProfileForm">
                <div class="edit-form-group">
                    <label>Profile Picture</label>
                    <input type="file" name="profile_pic" id="profilePicInput" accept="image/*" onchange="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>New Password (Leave blank if unchanged)</label>
                    <input type="password" name="password" id="passwordInput" placeholder="Enter new password" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" value="<?php echo htmlspecialchars($middle_name); ?>" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>Role</label>
                    <input type="text" name="role" value="<?php echo htmlspecialchars($user['role'] ?? ''); ?>" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>Student Number</label>
                    <input type="text" name="id_number" value="<?php echo htmlspecialchars($user['id_number'] ?? ''); ?>" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>KLD Email</label>
                    <input type="text" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" oninput="checkForEdits()">
                </div>
                <div class="edit-form-group">
                    <label>Section</label>
                    <input type="text" name="section" value="<?php echo htmlspecialchars($student_section); ?>" oninput="checkForEdits()">
                </div>

                <div class="edit-modal-footer-buttons">
                    <button type="submit" name="update_profile" id="submitChangesBtn" class="btn-submit-changes" disabled>Submit Changes</button>

                    <button type="submit" name="delete_account" class="btn-delete-acc" onclick="return confirm('Warning: All your details and account data will be deleted from the database. Are you sure?');">Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <div class="qr-modal-overlay" id="qrModal">
        <div class="qr-modal-box">
            <div class="qr-modal-header">
                <h3><i class="fa-solid fa-qrcode"></i> Scan Attendance QR</h3>
                <button class="close-qr-modal" onclick="closeQRModal()">&times;</button>
            </div>
            <p style="font-size: 12.5px; color: #666; margin-top: 0; margin-bottom: 12px;">Point your instructor's QR Code at the camera to record your attendance.</p>
            <div id="qr-reader"></div>
            <div id="qr-status-msg"></div>
        </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode"></script>

    <script>
        function toggleAddForm() {
            const formContainer = document.getElementById('addFormContainer');
            formContainer.classList.toggle('active');
        }

        let html5QrcodeScanner = null;

        function openQRModal() {
            document.getElementById('qrModal').style.display = 'flex';
            document.getElementById('qr-status-msg').style.display = 'none';

            html5QrcodeScanner = new Html5QrcodeScanner(
                "qr-reader", 
                { fps: 10, qrbox: { width: 220, height: 220 } },
                false
            );
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }

        function closeQRModal() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear().catch(err => console.error("Error clearing QR scanner:", err));
            }
            document.getElementById('qrModal').style.display = 'none';
        }

        function openEditProfileModal() {
            document.getElementById('editProfileModal').style.display = 'flex';
        }

        function closeEditProfileModal() {
            document.getElementById('editProfileModal').style.display = 'none';
        }

        function checkForEdits() {
            const submitBtn = document.getElementById('submitChangesBtn');
            submitBtn.removeAttribute('disabled');
        }

        // Web Audio API para sa Beep Sound
        function playBeepSound() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioCtx.createOscillator();
                const gainNode = audioCtx.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(800, audioCtx.currentTime);
                gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);

                oscillator.connect(gainNode);
                gainNode.connect(audioCtx.destination);

                oscillator.start();
                oscillator.stop(audioCtx.currentTime + 0.15);
            } catch (e) {
                console.log("Audio Context not supported or blocked by browser policy", e);
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            html5QrcodeScanner.clear();

            // Patunugin ang beep sound pagka-scan
            playBeepSound();

            const statusMsg = document.getElementById('qr-status-msg');
            statusMsg.className = 'status-info';
            statusMsg.innerText = 'Processing your attendance...';
            statusMsg.style.display = 'block';

            fetch('process_qr.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'qr_data=' + encodeURIComponent(decodedText)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    statusMsg.className = 'status-success';
                    statusMsg.innerText = data.message;
                    setTimeout(() => {
                        closeQRModal();
                        location.reload();
                    }, 1800);
                } else {
                    statusMsg.className = 'status-error';
                    statusMsg.innerText = data.message;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                statusMsg.className = 'status-error';
                statusMsg.innerText = 'An error occurred while processing attendance.';
            });
        }

        function onScanFailure(error) {
            // Unhandled scanning frames
        }
    </script>
</body>
</html>