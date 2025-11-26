<?php
/**
 * Universal Login Page (login.php)
 * Handles authentication for Admin, Student, Staff, HOD, and Principal.
 */

// 1. INCLUDE SESSION CONFIG (Must be first)
require_once 'session_config.php';

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include your secure Neon database connection
require_once __DIR__ . '/db.php';

// 2. Middleware: If already logged in, redirect based on role
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    switch ($role) {
        case 'admin':
            header('Location: admin-panel.php');
            exit;
        case 'student':
            header('Location: student-dashboard.php');
            exit;
        case 'staff':
            header('Location: staff-panel.php');
            exit;
        case 'HOD':
            header('Location: hod-panel.php');
            exit;
        case 'principal':
            header('Location: principal-panel.php');
            exit;
        default:
            // If role is unknown, logout
            session_destroy();
            header('Location: login.php'); 
            exit;
    }
}

$error = '';
$email = '';

// 3. Handle Login Form Submission INSIDE this file
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            // Prepare SQL to find user by email
            // We explicitly select 'role' to know where to redirect them
            $stmt = $pdo->prepare("SELECT id, first_name, surname, password, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify User and Password
            if ($user && password_verify($password, $user['password'])) {
                
                // Login Success: Set Session Variables
                session_regenerate_id(true); // Security best practice
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['first_name'] . ' ' . $user['surname'];

                // REDIRECT BASED ON ROLE
                switch ($user['role']) {
                    case 'admin':
                        header('Location: admin-panel.php');
                        exit;
                    case 'staff':
                        header('Location: staff-panel.php');
                        exit;
                    case 'HOD':
                        header('Location: hod-panel.php');
                        exit;
                    case 'principal':
                        header('Location: principal-panel.php');
                        exit;
                    default:
                        $_SESSION['error'] = "Login Successful, but your role is undefined.";
                        session_destroy(); 
                        header('Location: login.php');
                        exit;
                }
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "System Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - College Exam Section</title>
    <style>
/* General Styles */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Arial', sans-serif; }
body { background-color: #f4f4f9; font-size: 16px; color: #333; line-height: 1.6; overflow-x: hidden; }
h1, h2, h3, h4 { color: #333; }
a { text-decoration: none; color: #3498db; }
a:hover { color: #2980b9; }

/* Background Video */
.video-background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
#bg-video { object-fit: cover; width: 100%; height: 100%; }

/* Login Form Container */
.login-container { display: flex; justify-content: center; align-items: center; height: 100vh; position: relative; z-index: 1; }
.login-form { background-color: rgba(0, 0, 0, 0.7); color: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5); width: 100%; max-width: 400px; text-align: center; }
.login-form h2 { margin-bottom: 20px; font-size: 2rem; color: #fff; }
.form-group { margin-bottom: 20px; text-align: left; }
.form-group label { font-size: 1rem; color: #fff; }
.form-group input { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ddd; border-radius: 5px; font-size: 1rem; }
.form-group input:focus { outline: none; border-color: #3498db; }
button.btn { width: 100%; padding: 10px; background-color: #3498db; color: white; font-size: 1.2rem; border-radius: 5px; border: none; cursor: pointer; transition: background-color 0.3s; }
button.btn:hover { background-color: #2980b9; }
.register-link { margin-top: 20px; font-size: 1rem; color: #fff; }
.register-link a { color: #3498db; }
.forgot-password { margin-top: 10px; font-size: 1rem; color: #fff; }
.forgot-password a { color: #e74c3c; }

/* Responsive Styles */
@media (max-width: 768px) {
    .login-form { width: 90%; padding: 20px; }
    .login-form h2 { font-size: 1.8rem; }
}
</style>
</head>
<body>
    <!-- Background Video -->
    <div class="video-background">
        <video autoplay muted loop id="bg-video">
            <!-- Ensure this path is correct -->
            <source src="assets/video/back.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </div>

    <!-- Login Form -->
    <div class="login-container">
        <div class="login-form">
            <h2>Login</h2>
            
            <!-- Error Message Display -->
            <?php if ($error): ?>
                <p style="color: #ff4d4d; text-align: center; font-weight: bold; margin-bottom: 15px; background: rgba(0,0,0,0.5); padding: 5px; border-radius: 4px;">
                    <?= htmlspecialchars($error) ?>
                </p>
            <?php endif; ?>

            <!-- FIX: Action is empty so it submits to THIS FILE (login.php) -->
            <form action="" method="POST">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn">Login</button>
            </form>
            
            <p class="register-link">Don't have an account? <a href="register.php">Register here</a></p>
            <p class="forgot-password"><a href="forgot-password.php">Forgot Password?</a></p>
        </div>
    </div>
</body>
</html>
