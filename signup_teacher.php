<?php
session_start();
require_once 'db_connect.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $id_number = trim($_POST['id_number']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = 'faculty'; // Strictly faculty / teacher role
    
    $teacher_institute = isset($_POST['teacher_institute']) ? trim($_POST['teacher_institute']) : '';
    $teacher_position = isset($_POST['teacher_position']) ? trim($_POST['teacher_position']) : '';

    // Server-side Validations
    if (!preg_match('/^[A-ZÑa-zñ\-\s]+$/', $first_name) || !preg_match('/^[A-ZÑa-zñ\-\s]+$/', $last_name)) {
        $error = "Names must contain only letters, spaces, and hyphens.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{6}$/', $id_number)) {
        $error = "The identification number must strictly follow the format: 202X-XX-XXXXXX.";
    } elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@kld\.edu\.ph$/', $email)) {
        $error = "Only official KLD institutional email addresses (@kld.edu.ph) are accepted.";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,16}$/', $password)) {
        $error = "The password must be between 8 and 16 characters long and include uppercase letters, lowercase letters, numbers, and special characters.";
    } elseif (!isset($_POST['privacy_policy'])) {
        $error = "You must agree to the data privacy policy.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $profile_pic = 'kld-logo.png';
        
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['profile_pic']['tmp_name'];
            $file_name = time() . '_' . basename($_FILES['profile_pic']['name']);
            $upload_dir = 'uploads/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $target_path = $upload_dir . $file_name;
            if (move_uploaded_file($file_tmp, $target_path)) {
                $profile_pic = $target_path;
            }
        }

        // Insert teacher account details into the users table
        $stmt = $conn->prepare("INSERT INTO users (first_name, middle_name, last_name, id_number, email, password, role, institute, position, profile_pic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssss", $first_name, $middle_name, $last_name, $id_number, $email, $hashed_password, $role, $teacher_institute, $teacher_position, $profile_pic);
        
        if ($stmt->execute()) {
            header("Location: login.php");
            exit();
        } else {
            $error = "Registration failed. The employee number or email address may already be in use.";
        }
    }
}

$institutes_query = "SELECT id, institute_name FROM institutes ORDER BY institute_name ASC";
$institutes_result = $conn->query($institutes_query);
$institutes_data = [];
while ($row = $institutes_result->fetch_assoc()) {
    $institutes_data[] = $row;
}

$taken_positions_query = "SELECT institute, position FROM users WHERE role = 'faculty' AND position IN ('Institute Dean', 'Institute Associate Dean')";
$taken_res = $conn->query($taken_positions_query);
$taken_positions = [];
while ($row = $taken_res->fetch_assoc()) {
    $taken_positions[$row['institute']][] = $row['position'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Sign Up - KLD Attendance System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        body {
            background: linear-gradient(135deg, #f0f7f4 0%, #e8f5e9 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .main-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
        }
        .signup-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 35px;
            width: 100%;
            max-width: 550px;
            box-shadow: 0 10px 30px rgba(0, 128, 0, 0.08);
            border: 1px solid rgba(16, 138, 0, 0.08);
            box-sizing: border-box;
        }
        .signup-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .signup-header h2 {
            color: #1a331e;
            margin: 10px 0 5px 0;
            font-size: 26px;
            font-weight: 800;
        }
        .signup-header p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }
        .form-group {
            margin-bottom: 18px;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #2c3e50;
            font-weight: 700;
            font-size: 13px;
        }
        .input-with-icon {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-with-icon i.fa-solid:not(.password-toggle) {
            position: absolute;
            left: 15px;
            color: #108a00;
            font-size: 14px;
            pointer-events: none;
        }
        .input-with-icon .form-control {
            padding-left: 42px;
            padding-right: 40px;
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            color: #666;
            cursor: pointer;
            font-size: 14px;
            z-index: 10;
        }
        .password-toggle:hover {
            color: #108a00;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 14px;
            box-sizing: border-box;
            background: #fff;
        }
        .form-control:focus {
            border-color: #108a00;
            box-shadow: 0 0 0 3px rgba(16, 138, 0, 0.1);
            outline: none;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            font-size: 13px;
            color: #444;
        }
        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #108a00;
            cursor: pointer;
        }
        .checkbox-group a {
            color: #108a00;
            text-decoration: none;
            font-weight: 600;
        }
        .checkbox-group a:hover {
            text-decoration: underline;
        }
        .btn-submit {
            background: #108a00;
            color: #ffffff;
            border: none;
            width: 100%;
            padding: 14px;
            font-size: 16px;
            font-weight: 700;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 5px;
        }
        .error-msg {
            background: #ffebee;
            color: #c62828;
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: 600;
        }
        .field-alert {
            color: #c62828;
            font-size: 11px;
            margin-top: 4px;
            display: none;
            font-weight: 600;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: #666;
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: center;
        }
        .login-link a {
            color: #108a00;
            text-decoration: none;
            font-weight: 700;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
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
            <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
            <a href="about.php"><i class="fa-solid fa-circle-info"></i> About Us</a>
            <a href="signup_teacher.php" class="active"><i class="fa-solid fa-user-plus"></i> Sign Up</a>
            <a href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Log In</a>
        </div>
    </nav>

    <div class="main-container">
        <div class="signup-card">
            <div class="signup-header">
                <img src="kld-logo.png" alt="KLD Logo" style="width: 55px; height: 55px; object-fit: contain; margin-bottom: 5px;">
                <h2>Teacher Registration</h2>
                <p>Register your KLD Faculty Account</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="error-msg" id="serverErrorMsg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="error-msg" id="clientErrorMsg" style="display: none;"></div>

            <form action="signup_teacher.php" method="POST" enctype="multipart/form-data" id="signupForm" onsubmit="return validateFormOnSubmit(event)">
                <input type="hidden" name="role" value="faculty">

                <div class="form-group">
                    <label>Upload Profile Photo (Optional)</label>
                    <input type="file" name="profile_pic" class="form-control" accept="image/*">
                </div>

                <div class="form-group">
                    <label>First Name</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" name="first_name" id="firstName" class="form-control" placeholder="e.g., Sean Rose" required onblur="validateFirstName(this)">
                    </div>
                    <div class="field-alert" id="firstNameAlert">The name must contain only letters, spaces, and hyphens.</div>
                </div>

                <div class="form-group">
                    <label>Middle Name <span style="font-weight: normal; color: #666; font-size: 11px;">(Input "N/A" if none)</span></label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" name="middle_name" id="middleName" class="form-control" placeholder="e.g., Los Aura" required onblur="validateMiddleName(this)">
                    </div>
                    <div class="field-alert" id="middleNameAlert">The middle name must contain only letters, spaces, and hyphens (or "N/A").</div>
                </div>

                <div class="form-group">
                    <label>Last Name</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" name="last_name" id="lastName" class="form-control" placeholder="e.g., Bellen" required onblur="validateLastName(this)">
                    </div>
                    <div class="field-alert" id="lastNameAlert">The last name must contain only letters, spaces, and hyphens.</div>
                </div>

                <div class="form-group">
                    <label>Employee Number</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-id-card"></i>
                        <input type="text" name="id_number" id="idNumber" class="form-control" placeholder="202X-XX-XXXXXX" maxlength="14" required onblur="validateIdNumber(this)">
                    </div>
                    <div class="field-alert" id="idNumberAlert">The identification number must strictly follow the format: 202X-XX-XXXXXX.</div>
                </div>

                <div class="form-group">
                    <label>KLD Email</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" name="email" id="emailInput" class="form-control" placeholder="e.g., srbellen@kld.edu.ph" required onblur="validateEmail(this)">
                    </div>
                    <div class="field-alert" id="emailAlert">Please provide a valid and official KLD institutional email address (@kld.edu.ph).</div>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" id="passwordInput" class="form-control" placeholder="••••••••" minlength="8" maxlength="16" required onblur="validatePassword(this)">
                        <i class="fa-solid fa-eye password-toggle" id="togglePassword" onclick="togglePasswordVisibility('passwordInput', 'togglePassword')"></i>
                    </div>
                    <div class="field-alert" id="passwordAlert">The password must be between 8 and 16 characters long and include uppercase letters, lowercase letters, numbers, and special characters.</div>
                </div>

                <div class="form-group">
                    <label>Institute</label>
                    <select name="teacher_institute" id="teacherInstitute" class="form-control" required onchange="onInstituteChange()">
                        <option value="">Select Institute</option>
                        <?php if (!empty($institutes_data)): ?>
                            <?php foreach ($institutes_data as $inst): ?>
                                <option value="<?php echo htmlspecialchars($inst['institute_name']); ?>"><?php echo htmlspecialchars($inst['institute_name']); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Position</label>
                    <select name="teacher_position" id="teacherPosition" class="form-control" required>
                        <option value="">Select Position</option>
                        <option value="Institute Dean">Institute Dean</option>
                        <option value="Institute Associate Dean">Institute Associate Dean</option>
                        <option value="Associate Professor I">Associate Professor I</option>
                        <option value="Associate Professor II">Associate Professor II</option>
                        <option value="Associate Professor III">Associate Professor III</option>
                        <option value="Assistant Professor I">Assistant Professor I</option>
                        <option value="Assistant Professor II">Assistant Professor II</option>
                        <option value="Assistant Professor III">Assistant Professor III</option>
                    </select>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="privacy_policy" id="privacyPolicy">
                    <label for="privacyPolicy" style="margin-bottom:0; cursor:pointer;">I agree to the <a href="privacy_policy.php" target="_blank">Data Privacy Policy</a></label>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">Sign Up</button>
            </form>

            <div class="login-link">
                <div>Already have an account? <a href="login.php">Log in here</a></div>
            </div>
        </div>
    </div>

    <script>
        const takenPositionsByInstitute = <?php echo json_encode($taken_positions); ?>;

        function validateFirstName(input) {
            const alertBox = document.getElementById('firstNameAlert');
            const nameRegex = /^[A-ZÑa-zñ\-\s]+$/;
            if (input.value.trim() !== "" && !nameRegex.test(input.value)) {
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function validateMiddleName(input) {
            const alertBox = document.getElementById('middleNameAlert');
            const nameRegex = /^[A-ZÑa-zñ\-\s]+$/;
            if (input.value.trim() !== "" && !nameRegex.test(input.value)) {
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function validateLastName(input) {
            const alertBox = document.getElementById('lastNameAlert');
            const nameRegex = /^[A-ZÑa-zñ\-\s]+$/;
            if (input.value.trim() !== "" && !nameRegex.test(input.value)) {
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function validateIdNumber(input) {
            const alertBox = document.getElementById('idNumberAlert');
            const idRegex = /^\d{4}-\d{2}-\d{6}$/;
            if (input.value.trim() !== "" && !idRegex.test(input.value)) {
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function validateEmail(input) {
            const alertBox = document.getElementById('emailAlert');
            const emailRegex = /^[a-zA-Z0-9._%+-]+@kld\.edu\.ph$/;
            if (input.value.trim() !== "" && !emailRegex.test(input.value.trim())) {
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function validatePassword(input) {
            const alertBox = document.getElementById('passwordAlert');
            const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,16}$/;
            if (input.value.trim() !== "" && !passwordRegex.test(input.value)) {
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        }

        function onInstituteChange() {
            const instituteSelect = document.getElementById('teacherInstitute');
            const positionSelect = document.getElementById('teacherPosition');
            const selectedInstitute = instituteSelect.value;

            for (let option of positionSelect.options) {
                option.style.display = 'block';
            }

            if (selectedInstitute && takenPositionsByInstitute[selectedInstitute]) {
                const taken = takenPositionsByInstitute[selectedInstitute];
                for (let option of positionSelect.options) {
                    if (taken.includes(option.value)) {
                        option.style.display = 'none';
                    }
                }
            }
        }

        function togglePasswordVisibility(fieldId, iconId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(iconId);
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        function validateFormOnSubmit(event) {
            const firstName = document.getElementById('firstName').value.trim();
            const middleName = document.getElementById('middleName').value.trim();
            const lastName = document.getElementById('lastName').value.trim();
            const idNumber = document.getElementById('idNumber').value.trim();
            const emailInput = document.getElementById('emailInput').value.trim();
            const passwordInput = document.getElementById('passwordInput').value;
            const teacherInstitute = document.getElementById('teacherInstitute').value;
            const teacherPosition = document.getElementById('teacherPosition').value;
            const privacyPolicy = document.getElementById('privacyPolicy').checked;
            
            const clientErrorMsg = document.getElementById('clientErrorMsg');
            const serverErrorMsg = document.getElementById('serverErrorMsg');
            if (serverErrorMsg) serverErrorMsg.style.display = 'none';

            let errorMessage = "";

            const nameRegex = /^[A-ZÑa-zñ\-\s]+$/;
            const idRegex = /^\d{4}-\d{2}-\d{6}$/;
            const emailRegex = /^[a-zA-Z0-9._%+-]+@kld\.edu\.ph$/;
            const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,16}$/;

            if (!nameRegex.test(firstName) || !nameRegex.test(middleName) || !nameRegex.test(lastName)) {
                errorMessage = "All name fields must contain only letters, spaces, and hyphens.";
            } else if (!idRegex.test(idNumber)) {
                errorMessage = "The identification number must strictly follow the format: 202X-XX-XXXXXX.";
            } else if (!emailRegex.test(emailInput)) {
                errorMessage = "Only official KLD institutional email addresses (@kld.edu.ph) are accepted.";
            } else if (!passwordRegex.test(passwordInput)) {
                errorMessage = "The password must be between 8 and 16 characters long and include uppercase letters, lowercase letters, numbers, and special characters.";
            } else if (!teacherInstitute || !teacherPosition) {
                errorMessage = "Please select your institute and position.";
            } else if (!privacyPolicy) {
                errorMessage = "You must agree to the data privacy policy before proceeding.";
            }

            if (errorMessage !== "") {
                event.preventDefault();
                clientErrorMsg.textContent = errorMessage;
                clientErrorMsg.style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return false;
            }

            return true;
        }
    </script>
</body>
</html>