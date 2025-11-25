<?php
session_start();

// Check if student is logged in using student_id
if (!isset($_SESSION['student_id'])) {
    // Redirect to the correct student login page (adjust filename if needed)
    header('Location: student-login.php'); 
    exit;
}

// Get email from session for display (ensure it's set during login)
$student_email = $_SESSION['student_email'] ?? 'Student'; // Default to 'Student' if not set

// --- Optional: Fetch student name from DB ---
// Uncomment and adapt if you want to display the name
/*
include('../db-config.php'); // Include DB connection
$student_name = 'Student'; // Default name
try {
    $stmt = $conn->prepare("SELECT name FROM students WHERE id = ?");
    $stmt->execute([$_SESSION['student_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['name'])) {
        $student_name = $result['name'];
    }
} catch (PDOException $e) {
    // Log error or handle gracefully, don't stop the page
    error_log("Error fetching student name: " . $e->getMessage());
}
$conn = null; // Close connection if opened
*/

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <style>
        /* Consistent Styling */
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
        <a href="../logout.php">Logout</a> <!-- Corrected path assuming student files are in a subfolder -->
    </div>

    <div class="container">
        <!-- Replace 'Welcome!' with the name if you fetch it -->
        <h1>Welcome!</h1> 
        <p>Email: <?= htmlspecialchars($student_email) ?></p>
        
        <div class="features">
            <a href="attendance.php" class="feature-link">View Attendance</a>
            <a href="ia-results.php" class="feature-link">View IA Results</a>
            <a href="take-test.php" class="feature-link">Take Assigned Test</a> <!-- Added Link -->
            <!-- <a href="timetable.php" class="feature-link">View Timetable</a> -->
            <!-- Add more links as features are developed -->
        </div>

        <a href="../logout.php" class="logout-btn">Logout</a> <!-- Corrected path -->
    </div>
</body>
</html>

