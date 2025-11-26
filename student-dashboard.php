<?php
// student-dashboard.php - Student dashboard with session validation (aligned with ia-results.php)
declare(strict_types=1);

// Temporary debug flag via ?debug=1 (remove in production)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Start session
if (session_status() === PHP_SESSION_NONE) session_start();

// Show debug info early when asked
if ($DEBUG) {
    echo "<div style='font-family:monospace;padding:10px;background:#f1f5f9;border:1px solid #e2e8f0;margin:10px 0;'>";
    echo "<strong>DEBUG (Dashboard)</strong><br>";
    echo "Session id: " . htmlspecialchars(session_id()) . "<br>";
    echo "Session array: <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>";
    echo "Cookies: <pre>" . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>";
    echo "</div>";
}

// dev error display (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Normalize role check (same as ia-results.php for consistency)
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || $role !== 'student') {
    if ($DEBUG) {
        echo "<div style='padding:12px;background:#fff5f5;border:1px solid #fee2e2;color:#7f1d1d;'>";
        echo "<h3>Auth Debug (Dashboard)</h3>";
        echo "<p>Session missing required keys or role != 'student'.</p>";
        echo "</div>";
        exit;
    }
    header('Location: student-login.php');
    exit;
}

// Get user_id and optional student data
$user_id = (int) $_SESSION['user_id'];
$student_name = 'Student'; // Default
$student_email = $_SESSION['student_email'] ?? 'Student'; // Fallback from session

// --- Fetch student name from DB (optional, aligned with ia-results.php) ---
require_once __DIR__ . '/db.php'; // Adjust path if db.php is elsewhere (e.g., '../db.php')
try {
    $stmt = $pdo->prepare("SELECT student_name, name, usn FROM students WHERE user_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        $student_name = $student['student_name'] ?? $student['name'] ?? 'Student';
        $student_email = $student['email'] ?? $student_email; // Override if DB has it
    }
} catch (PDOException $e) {
    // Log error or handle gracefully
    error_log("Error fetching student data: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <style>
        /* Consistent Styling (retained from your original) */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f9; color: #333; }
        .navbar { background-color: #007bff; padding: 1em; display: flex; justify-content: flex-end; gap: 1em; }
        .navbar a { color: #fff; text-decoration: none; padding: 0.5em 1em; border-radius: 5px; transition: background-color 0.3s ease; }
        .navbar a:hover { background-color: #0056b3; }
        .container { width: 90%; max-width: 800px; margin: 20px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
        h1 { color: #444; margin-bottom: 15px; }
        p { margin-bottom: 20px; color: #555; }
        .features { margin-top: 30px; display: flex; flex-wrap: wrap; justify-content: center; gap: 15px; }
        .feature-link { 
            display: inline-block; 
            padding: 12px 20px; 
            background-color: #17a2b8; /* Teal color */
            color: white; 
            text-decoration: none; 
            border-radius: 5px; 
            transition: background-color 0.3s;
            font-size: 16px;
            min-width: 150px; /* Ensure buttons have some width */
            text-align: center; /* Center text in button */
        }
        .feature-link:hover { background-color: #138496; }
        .logout-btn { 
            display: inline-block; 
            margin-top: 30px;
            padding: 10px 20px; 
            background-color: #dc3545; /* Red color */
            color: white; 
            text-decoration: none; 
            border-radius: 5px; 
            transition: background-color 0.3s;
        }
        .logout-btn:hover { background-color: #c82333; }
    </style>
</head>
<body>
    <div class="navbar">
        <a href="../logout.php">Logout</a> <!-- Adjust path if needed -->
    </div>

    <div class="container">
        <!-- Display fetched name -->
        <h1>Welcome, <?= htmlspecialchars($student_name) ?>!</h1> 
        <p>Email: <?= htmlspecialchars($student_email) ?></p>
        
        <div class="features">
            <a href="attendance.php" class="feature-link">View Attendance</a>
            <a href="ia-results.php" class="feature-link">View IA Results</a>
            <a href="take-test.php" class="feature-link">Take Assigned Test</a>
            <!-- <a href="timetable.php" class="feature-link">View Timetable</a> -->
            <!-- Add more links as features are developed -->
        </div>

        <a href="../logout.php" class="logout-btn">Logout</a> <!-- Adjust path if needed -->
    </div>
</body>
</html>
