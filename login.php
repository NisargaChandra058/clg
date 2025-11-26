<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - College Exam Section</title>
    <style>
/* General Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Arial', sans-serif;
}

body {
    background-color: #f4f4f9;
    font-size: 16px;
    color: #333;
    line-height: 1.6;
    overflow-x: hidden;
}

h1, h2, h3, h4 {
    color: #333;
}

a {
    text-decoration: none;
    color: #3498db;
}

a:hover {
    color: #2980b9;
}

/* Background Video */
.video-background {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
}

#bg-video {
    object-fit: cover;
    width: 100%;
    height: 100%;
}

/* Login Form Container */
.login-container {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    position: relative;
    z-index: 1;
}

.login-form {
    background-color: rgba(0, 0, 0, 0.7);
    color: #fff;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
    width: 100%;
    max-width: 400px;
    text-align: center;
}

.login-form h2 {
    margin-bottom: 20px;
    font-size: 2rem;
}

.form-group {
    margin-bottom: 20px;
    text-align: left;
}

.form-group label {
    font-size: 1rem;
    color: #fff;
}

.form-group input {
    width: 100%;
    padding: 10px;
    margin-top: 5px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 1rem;
}

.form-group input:focus {
    outline: none;
    border-color: #3498db;
}

button.btn {
    width: 100%;
    padding: 10px;
    background-color: #3498db;
    color: white;
    font-size: 1.2rem;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    transition: background-color 0.3s;
}

button.btn:hover {
    background-color: #2980b9;
}

.register-link {
    margin-top: 20px;
    font-size: 1rem;
    color: #fff;
}

.register-link a {
    color: #3498db;
}

.register-link a:hover {
    color: #2980b9;
}

/* Forget Password Link */
.forgot-password {
    margin-top: 10px;
    font-size: 1rem;
    color: #fff;
}

.forgot-password a {
    color: #e74c3c;
}

.forgot-password a:hover {
    color: #c0392b;
}

/* Responsive Styles */
@media (max-width: 768px) {
    .login-form {
        width: 90%;
        padding: 20px;
    }

    .login-form h2 {
        font-size: 1.8rem;
    }

    .form-group input {
        font-size: 1rem;
    }

    button.btn {
        font-size: 1rem;
    }
}
h1, h2, h3, h4 {
    color: #f4f4f9;
}
</style>
</head>
<body>
    <!-- Background Video -->
    <div class="video-background">
        <video autoplay muted loop id="bg-video">
            <!-- NOTE: Make sure this video path is correct relative to your project structure -->
            <source src="assets/video/back.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </div>

    <!-- Login Form -->
    <div class="login-container">
        <div class="login-form">
            <h2>Login</h2>
            
            <form action="login-action.php" method="POST">

                <!-- START: ERROR MESSAGE DISPLAY -->
                <?php
                // Check if an error message is set in the session
                if (isset($_SESSION['error'])) {
                    // Display the error message in red
                    echo '<p style="color: #ff4d4d; text-align: center; font-weight: bold; margin-bottom: 15px;">' . htmlspecialchars($_SESSION['error']) . '</p>';
                    
                    // Unset the error so it doesn't show again on refresh
                    unset($_SESSION['error']);
                }
                ?>
                <!-- END: ERROR MESSAGE DISPLAY -->

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
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
