<?php
require_once 'session_config.php';
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/db.php';

function safe_redirect($url) {
    session_write_close();
    header("Location: $url");
    exit;
}

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower(trim($_SESSION['role']));
    switch ($role) {
        case 'admin': safe_redirect('admin-panel.php');
        case 'student': safe_redirect('student-dashboard.php');
        case 'staff': safe_redirect('staff-panel.php');
        case 'hod': safe_redirect('hod-panel.php');
        case 'principal': safe_redirect('principal-panel.php');
        default: safe_redirect('index.php');
    }
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<!-- DEBUG: POST received -->";
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        try {
            if (!$pdo) {
                throw new Exception("Database connection failed.");
            }
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<!-- DEBUG: User: " . json_encode($user) . " -->";

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['surname'] ?? ''));
                $_SESSION['branch'] = $user['branch'] ?? 'N/A';

                $role = strtolower(trim($user['role']));
                switch ($role) {
                    case 'admin': safe_redirect('admin-panel.php');
                    case 'student': safe_redirect('student-dashboard.php');
                    case 'staff': safe_redirect('staff-panel.php');
                    case 'hod': safe_redirect('hod-panel.php');
                    case 'principal': safe_redirect('principal-panel.php');
                    default: safe_redirect('index.php');
                }
            } else {
                $error = "Invalid email or password.";
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System Error: Please try again later.";
        }
    }
}
?>
<!-- Rest of your HTML remains the same -->

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

