<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php'; // PDO connection

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// 1. Authorization check (trim + lowercase)
$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!isset($_SESSION['user_id']) || $role !== 'student') {
    if ($debug) {
        // Safe debug output — do NOT leave this enabled in production
        echo "<pre>Access Denied. session user_id: " . htmlspecialchars($_SESSION['user_id'] ?? 'MISSING')
             . "\nrole: " . htmlspecialchars($_SESSION['role'] ?? 'MISSING') . "</pre>";
        // Stop further execution when debugging
        exit;
    }
    header("Location: student-login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$results = [];

try {
    // 2. Resolve user -> student (using email)
    $stmt_user = $pdo->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
    $stmt_user->execute(['id' => $user_id]);
    $user_email = $stmt_user->fetchColumn();

    $student_id = null;

    if ($user_email) {
        // normalize email
        $user_email = strtolower(trim($user_email));
        $stmt_stu = $pdo->prepare("SELECT id FROM students WHERE LOWER(TRIM(email)) = :email LIMIT 1");
        $stmt_stu->execute(['email' => $user_email]);
        $student_id = $stmt_stu->fetchColumn();
    }

    if (!$student_id) {
        // In debug mode show detailed hint; otherwise show friendly UI
        if ($debug) {
            echo "<pre>Student profile not found.
user_id: " . htmlspecialchars($user_id) .
                 "\nuser_email: " . htmlspecialchars($user_email ?? 'NULL') .
                 "\nTry running: SELECT id FROM students WHERE email = '...'</pre>";
            exit;
        } else {
            die("<div style='padding:20px; font-family:sans-serif; text-align:center; color:#721c24; background:#f8d7da;'>
                    <h2>❌ Student Profile Not Found</h2>
                    <p>Your login works, but we couldn't find your academic record.</p>
                    <p><strong>To Fix:</strong> Ask the Admin to use the 'Fix Missing Students' button in the Assign Subject page.</p>
                    <a href='student-dashboard.php' style='background:#333; color:white; padding:10px; text-decoration:none; border-radius:5px;'>Back to Dashboard</a>
                 </div>");
        }
    }

    // 3. Fetch results
    // Use LEFT JOIN for question_papers and subjects so that missing qp/subject rows don't remove the result row.
    $sql = "
        SELECT 
            COALESCE(s.name, 'General') AS subject_name,
            COALESCE(qp.title, CONCAT('Deleted Paper (ID:', ir.qp_id, ')')) AS test_name,
            ir.marks,
            ir.max_marks,
            ir.created_at
        FROM ia_results ir
        LEFT JOIN question_papers qp ON ir.qp_id = qp.id
        LEFT JOIN subjects s ON qp.subject_id = s.id
        WHERE ir.student_id = :sid
        ORDER BY ir.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $student_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($debug) {
        echo "<pre>Debug info:\nuser_id={$user_id}\nstudent_id={$student_id}\nrows=" . count($results) . "</pre>";
    }

} catch (PDOException $e) {
    // In debug mode show error; otherwise log and show user-friendly message
    if ($debug) {
        die("Database Error: " . htmlspecialchars($e->getMessage()));
    } else {
        // Consider logging $e->getMessage() to a file instead of echoing
        error_log("DB error on IA results page for user {$user_id}: " . $e->getMessage());
        die("An unexpected error occurred. Please contact admin.");
    }
}
?>
<!-- (the rest of your HTML is unchanged) -->
<!DOCTYPE html>
<html lang="en">
<head>
<!-- ... keep your head and styles ... -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IA Results</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* keep your styles here (same as original) */
body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding: 40px 20px; color: #334155; }
.container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
h2 { text-align: center; margin-bottom: 30px; color: #0f172a; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; display: inline-block; }
.header-wrap { text-align: center; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th { background: #f8fafc; color: #64748b; padding: 15px; text-align: left; font-weight: 600; border-bottom: 2px solid #e2e8f0; }
td { padding: 15px; border-bottom: 1px solid #e2e8f0; font-size: 0.95rem; }
tr:last-child td { border-bottom: none; }
.score-badge { background: #dcfce7; color: #166534; padding: 5px 10px; border-radius: 20px; font-weight: 700; font-size: 0.9rem; }
.date-text { color: #94a3b8; font-size: 0.85rem; }
.back-link { display: inline-block; margin-top: 30px; text-decoration: none; color: #64748b; font-weight: 500; transition: color 0.2s; }
.back-link:hover { color: #3b82f6; }
.no-records { text-align: center; padding: 40px; color: #94a3b8; font-style: italic; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; }
</style>
</head>
<body>
    <div class="container">
        <div class="header-wrap">
            <h2>🏆 Internal Assessment Results</h2>
        </div>

        <?php if (empty($results)): ?>
            <div class="no-records">You haven't completed any tests yet.</div>
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
                            <td style="font-weight:500; color:#0f172a;"><?= htmlspecialchars($row['subject_name']) ?></td>
                            <td><?= htmlspecialchars($row['test_name']) ?></td>
                            <td>
                                <span class="score-badge"><?= htmlspecialchars($row['marks']) ?> / <?= htmlspecialchars($row['max_marks']) ?></span>
                            </td>
                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="text-align:center;">
            <a href="student-dashboard.php" class="back-link">&laquo; Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
