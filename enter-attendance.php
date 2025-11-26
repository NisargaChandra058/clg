<?php
session_start();
require_once 'db.php'; // PDO Connection

// 1. Authorization Check
// Allow Admin, Staff, HOD, Principal
$allowed_roles = ['admin', 'staff', 'hod', 'principal'];
if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowed_roles)) {
    die("Access Denied. Staff only.");
}

$message = '';

// 2. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $qp_id      = filter_input(INPUT_POST, 'qp_id', FILTER_VALIDATE_INT);
    $marks      = filter_input(INPUT_POST, 'marks', FILTER_VALIDATE_INT);
    $max_marks  = filter_input(INPUT_POST, 'max_marks', FILTER_VALIDATE_INT);

    if ($student_id && $qp_id && $marks !== null) {
        try {
            // Ensure table columns exist (Self-Healing)
            try { $pdo->exec("ALTER TABLE ia_results ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch(Exception $e){}
            try { $pdo->exec("ALTER TABLE ia_results ADD COLUMN IF NOT EXISTS max_marks INT DEFAULT 100"); } catch(Exception $e){}

            // Upsert (Insert or Update if exists)
            $stmt = $pdo->prepare("
                INSERT INTO ia_results (student_id, qp_id, marks, max_marks) 
                VALUES (:sid, :qid, :marks, :max)
                ON CONFLICT (student_id, qp_id) 
                DO UPDATE SET marks = EXCLUDED.marks, max_marks = EXCLUDED.max_marks, created_at = NOW()
            ");
            
            $stmt->execute([
                ':sid' => $student_id,
                ':qid' => $qp_id,
                ':marks' => $marks,
                ':max' => $max_marks ?: 100
            ]);

            $message = "<div class='message success'>✅ Result saved successfully!</div>";
        } catch (PDOException $e) {
            $message = "<div class='message error'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='message error'>⚠️ Please select all fields.</div>";
    }
}

// 3. Fetch Data for Dropdowns
try {
    // FIX: Use 'student_name' instead of 'name'
    $students = $pdo->query("SELECT id, student_name, usn FROM students ORDER BY student_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch Tests (Question Papers)
    $tests = $pdo->query("SELECT id, title, subject_id FROM question_papers ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Data Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Results</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; margin-bottom: 20px; }
        
        label { font-weight: bold; display: block; margin-top: 15px; color: #555; }
        select, input { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 16px; }
        
        button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 5px; font-size: 18px; font-weight: bold; margin-top: 25px; cursor: pointer; transition: 0.3s; }
        button:hover { background: #0056b3; }
        
        .message { padding: 10px; margin-bottom: 15px; border-radius: 5px; text-align: center; font-weight: bold; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #666; text-decoration: none; }
    </style>
</head>
<body>

<div class="container">
    <h2>Enter IA Results</h2>
    <?= $message ?>

    <form method="POST">
        <!-- Student Dropdown -->
        <label>Student:</label>
        <select name="student_id" required>
            <option value="">-- Select Student --</option>
            <?php foreach ($students as $s): ?>
                <option value="<?= $s['id'] ?>">
                    <?= htmlspecialchars($s['student_name']) ?> (<?= htmlspecialchars($s['usn'] ?? '') ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Test Dropdown -->
        <label>Test / Exam:</label>
        <select name="qp_id" required>
            <option value="">-- Select Test --</option>
            <?php foreach ($tests as $t): ?>
                <option value="<?= $t['id'] ?>">
                    <?= htmlspecialchars($t['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Marks -->
        <label>Marks Obtained:</label>
        <input type="number" name="marks" required placeholder="e.g. 25">

        <label>Total / Max Marks:</label>
        <input type="number" name="max_marks" value="100" required>

        <button type="submit">Submit Result</button>
    </form>

    <a href="admin-panel.php" class="back-link">Back to Dashboard</a>
</div>

</body>
</html>
