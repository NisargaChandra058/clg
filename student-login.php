<?php
// student-login.php - Student login with corrected session variables
declare(strict_types=1);

// Temporary debug flag via ?debug=1 (remove in production)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Start session
if (session_status() === PHP_SESSION_NONE) session_start();

// Show debug info early when asked
if ($DEBUG) {
    echo "<div style='font-family:monospace;padding:10px;background:#f1f5f9;border:1px solid #e2e8f0;margin:10px 0;'>";
    echo "<strong>DEBUG (Login)</strong><br>";
    echo "Session id: " . htmlspecialchars(session_id()) . "<br>";
    echo "Session array: <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>";
    echo "Cookies: <pre>" . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>";
    echo "</div>";
}

// dev error display (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1. Include the correct PDO database connection (aligned with dashboard/ia-results)
require_once __DIR__ . '/db.php';  // Assumes db.php sets $pdo; adjust if path differs

$error = ""; // Variable to hold login error messages

// Handle POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']); // Get plain password

    // Basic validation
    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        try {
            // 2. Prepare the query using PDO
            // Fetching from the 'students' table
            $stmt = $pdo->prepare("SELECT id, email, password FROM students WHERE email = ?");
            
            // 3. Execute the query with the email parameter
            $stmt->execute([$email]);
            
            // 4. Fetch the student record
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            // 5. Verify if student exists and password matches
            if ($student && password_verify($password, $student['password'])) {
                // Password is correct, set session variables (aligned with dashboard/ia-results)
                session_regenerate_id(true); // Prevent session fixation
                $_SESSION['user_id'] = $student['id'];  // Set user_id to students.id
                $_SESSION['role'] = 'student';  // Set role for consistency
                $_SESSION['student_email'] = $student['email'];  // Keep for display
                
                // Redirect to the student dashboard
                header("Location: student-dashboard.php"); // Assuming this is the correct dashboard file
                exit;
            } else {
                // Invalid email or password
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            // Handle potential database errors
            $error = "Login failed due to a system error. Please try again later.";
            // Optional: Log the detailed error $e->getMessage() for debugging
            error_log("Student Login DB Error: " . $e->getMessage()); 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login</title>
    <style>
        /* General Styles */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Arial', sans-serif; }
        body { background-color: #f4f4f9; font-size: 16px; color: #333; line-height: 1.6; overflow-x: hidden; }
        h1, h2, h3, h4 { color: #f4f4f9; } /* Corrected color for headings inside dark form */
        /* Background Video */
        .video-background { position: fixed; /* Changed to fixed */ top: 0; left: 0; width: 100%; height: 100%; z-index: -1; overflow: hidden; /* Prevent scrollbars */ }
        #bg-video { object-fit: cover; width: 100%; height: 100%; }
        /* Login Form Container */
        .login-container { display: flex; justify-content: center; align-items: center; min-height: 100vh; /* Use min-height */ padding: 20px; /* Add padding for small screens */ box-sizing: border-box; }
        /* Login Form Styling */
        .login-form { background-color: rgba(0, 0, 0, 0.75); /* Slightly more opaque */ color: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5); width: 100%; max-width: 400px; text-align: center; }
        .login-form h2 { margin-bottom: 25px; font-size: 2rem; }
        .form-group { margin-bottom: 20px; text-align: left; }
        .form-group label { font-size: 1rem; color: #eee; /* Lighter label color */ display: block; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 12px; margin-top: 5px; border: 1px solid #555; /* Darker border */ border-radius: 5px; font-size: 1rem; background-color: #333; /* Dark input background */ color: #fff; }
        .form-group input:focus { outline: none; border-color: #3498db; box-shadow: 0 0 5px rgba(52, 152, 219, 0.5); }
        button.btn { width: 100%; padding: 12px; background-color: #3498db; color: white; font-size: 1.2rem; font-weight: bold; border-radius: 5px; border: none; cursor: pointer; transition: background-color 0.3s; margin-top: 10px; /* Add some space above button */ }
        button.btn:hover { background-color: #2980b9; }
        /* Error Message */
        .error-message { color: #ff6b6b; /* Brighter red for visibility */ background-color: rgba(255, 107, 107, 0.1); border: 1px solid #ff6b6b; padding: 10px; border-radius: 5px; font-size: 1rem; margin-top: 15px; text-align: center; }
        /* Back Link */
         .back-link { display: block; margin-top: 15px; text-decoration: none; color: #bdc3c7; font-size: 0.9rem; }
         .back-link:hover { color: #fff; text-decoration: underline; }
        /* Responsive Styles */
        @media (max-width: 480px) {
            .login-form { padding: 20px; }
            .login-form h2 { font-size: 1.8rem; }
            .form-group input { font-size: 1rem; padding: 10px; }
            button.btn { font-size: 1.1rem; padding: 10px; }
        }
    </style>
</head>
<body>
    <!-- Background Video -->
    <div class="video-background">
        <video autoplay muted loop playsinline id="bg-video"> <!-- Added playsinline for mobile -->
            <!-- Ensure the video path is correct relative to this file -->
            <source src="../video/back.mp4" type="video/mp4"> 
            Your browser does not support the video tag.
        </video>
    </div>
    <!-- Login Form Container -->
    <div class="login-container">
        <div class="login-form">
            <h2>Student Login</h2>
            
            <?php 
            // Display error message if it exists
            if (!empty($error)) {
                 echo "<p class='error-message'>" . htmlspecialchars($error) . "</p>"; 
            }
            ?>
            
            <form action="student-login.php" method="POST">
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" name="email" id="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" name="password" id="password" required>
                </div>
                
                <button class="btn" type="submit">Login</button>
            </form>
             <a href="../index.php" class="back-link">Back to Home</a> 
        </div>
    </div>

</body>
</html>
