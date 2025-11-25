<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php'; // PDO Connection

echo "<div style='font-family:monospace; background:#333; color:#0f0; padding:20px; margin-bottom:20px;'>";
echo "<strong>--- DIAGNOSTIC MODE ---</strong><br>";

// 1. CHECK SESSION
echo "Checking Session... ";
if (!isset($_SESSION['user_id'])) {
    echo "<span style='color:red'>FAIL: Session 'user_id' is missing. You are not logged in.</span><br>";
    echo "<a href='student-login.php' style='color:white'>Go to Login</a></div>";
    exit;
}
echo "<span style='color:#0f0'>OK (User ID: " . $_SESSION['user_id'] . ")</span><br>";

// 2. CHECK ROLE
echo "Checking Role... ";
$role = strtolower($_SESSION['role'] ?? 'none');
echo "Role is '$role'. ";
if ($role !== 'student') {
    echo "<span style='color:red'>FAIL: Role must be 'student'.</span><br></div>";
    exit;
}
echo "<span style='color:#0f0'>OK</span><br>";

// 3. CHECK DATABASE LINK
$user_id = $_SESSION['user_id'];
$student_id = null;

try {
    // Get Email from User ID
    echo "Fetching User Email... ";
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $user_email = $stmt->fetchColumn();
    
    if (!$user_email) {
        echo "<span style='color:red'>FAIL: Could not find user with ID $user_id in 'users' table.</span><br></div>";
        exit;
    }
    echo "<span style='color:#0f0'>OK ($user_email)</span><br>";

    // Get Student ID from Email
    echo "Looking up Student Profile... ";
    $stmt = $pdo->prepare("SELECT id, student_name FROM students WHERE email = :email");
    $stmt->execute(['email' => $user_email]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo "<span style='color:red'>FAIL: No record found in 'students' table for email '$user_email'.</span><br>";
        echo "<strong style='color:yellow'>SOLUTION: Ask Admin to run 'Fix Missing Students' in Assign Subject page.</strong><br></div>";
        exit;
    }
    $student_id = $student['id'];
    echo "<span style='color:#0f0'>OK (Student ID: $student_id, Name: {$student['student_name']})</span><br>";

} catch (PDOException $e) {
    echo "<span style='color:red'>DATABASE ERROR: " . $e->getMessage() . "</span><br></div>";
    exit;
}

echo "<strong>--- CHECKS PASSED. LOADING DATA ---</strong></div>";

// ---------------------------------------------------------
// 4. FETCH RESULTS (Normal Logic)
// ---------------------------------------------------------
$results = [];
try {
    $sql = "
        SELECT 
            COALESCE(s.name, 'General') AS subject_name,
            qp.title AS test_name,
            ir.marks,
            ir.max_marks,
            ir.created_at
        FROM ia_results ir
        JOIN question_papers qp ON ir.qp_id = qp.id
        LEFT JOIN subjects s ON qp.subject_id = s.id
        WHERE ir.student_id = :sid
        ORDER BY ir.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $student_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Query Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA Results</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; padding: 40px 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #007bff; color: white; padding: 15px; text-align: left; }
        td { padding: 15px; border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center">🏆 Internal Assessment Results</h2>
        
        <?php if (empty($results)): ?>
            <div style="text-align:center; padding:40px; color:#888; font-style:italic; border:1px dashed #ccc;">
                No results found for Student ID: <?= $student_id ?>
            </div>
        <?php else: ?>
            <table>
                <thead><tr><th>Subject</th><th>Test Name</th><th>Score</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['subject_name']) ?></td>
                            <td><?= htmlspecialchars($row['test_name']) ?></td>
                            <td><strong><?= htmlspecialchars($row['marks']) ?></strong> / <?= htmlspecialchars($row['max_marks']) ?></td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <center style="margin-top:20px;">
            <a href="student-dashboard.php" style="text-decoration:none; color:#555;">&laquo; Back to Dashboard</a>
        </center>
    </div>
</body>
</html>
