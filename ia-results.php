<?php
session_start();
require_once 'db.php'; // Connect using PDO ($pdo)

// 1. Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: student-login.php"); 
    exit;
}

$user_id = $_SESSION['user_id'];
$results = [];

try {
    // 2. Resolve User ID -> Student ID
    // (Same logic as dashboard to ensure we get the correct academic record)
    $stmt_user = $pdo->prepare("SELECT email FROM users WHERE id = :id");
    $stmt_user->execute(['id' => $user_id]);
    $user_email = $stmt_user->fetchColumn();

    if ($user_email) {
        $stmt_stu = $pdo->prepare("SELECT id FROM students WHERE email = :email");
        $stmt_stu->execute(['email' => $user_email]);
        $student_id = $stmt_stu->fetchColumn();
    }

    if (empty($student_id)) {
        die("<div style='padding:20px;color:red;'>Error: Student profile not found. Please contact admin.</div>");
    }

    // 3. Fetch Results (The Correct JOIN Query)
    // We join ia_results -> question_papers -> subjects to get the names
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

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA Results</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f4f7f6; 
            margin: 0; 
            padding: 40px 20px; 
            color: #333;
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
        }
        h2 { 
            text-align: center; 
            margin-bottom: 30px; 
            color: #2b2d42; 
            border-bottom: 2px solid #007bff; 
            padding-bottom: 10px;
            display: inline-block;
        }
        .center-header { text-align: center; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #007bff; color: white; padding: 12px; text-align: left; font-weight: 600; }
        td { padding: 12px; border-bottom: 1px solid #eee; color: #555; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: #f9f9f9; }

        .marks { font-weight: bold; color: #2b2d42; }
        .max-marks { color: #888; font-size: 0.9em; }
        
        .back-link { 
            display: inline-block; 
            margin-top: 30px; 
            text-decoration: none; 
            color: #6c757d; 
            font-weight: 500; 
            transition: color 0.2s;
        }
        .back-link:hover { color: #007bff; }
        
        .no-records { 
            text-align: center; 
            padding: 40px; 
            color: #888; 
            font-style: italic; 
            background: #fdfdfd;
            border-radius: 8px;
            border: 1px dashed #ddd;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="center-header">
            <h2>Internal Assessment (IA) Results</h2>
        </div>

        <?php if (empty($results)): ?>
            <div class="no-records">No results found. You haven't completed any tests yet.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Test Name</th>
                        <th>Score</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['subject_name']) ?></td>
                            <td><?= htmlspecialchars($row['test_name']) ?></td>
                            <td>
                                <span class="marks"><?= htmlspecialchars($row['marks']) ?></span>
                                <span class="max-marks"> / <?= htmlspecialchars($row['max_marks']) ?></span>
                            </td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <center><a href="student-dashboard.php" class="back-link">&laquo; Back to Dashboard</a></center>
    </div>

</body>
</html>
