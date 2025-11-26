<?php
// ia-results.php - robust student-resolution + results display
declare(strict_types=1);

// Temporary debug flag via ?debug=1 (remove in production)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Start session
if (session_status() === PHP_SESSION_NONE) session_start();

// Show debug info early when asked
if ($DEBUG) {
    echo "<div style='font-family:monospace;padding:10px;background:#f1f5f9;border:1px solid #e2e8f0;margin:10px 0;'>";
    echo "<strong>DEBUG</strong><br>";
    echo "Session id: " . htmlspecialchars(session_id()) . "<br>";
    echo "Session array: <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>";
    echo "Cookies: <pre>" . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>";
    echo "</div>";
}

// dev error display (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// require DB (aligned with dashboard/login; assumes db-config.php sets $conn)
include('db-config.php');  // Fixed path: same directory as other files

// Normalize role check
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || $role !== 'student') {
    if ($DEBUG) {
        echo "<div style='padding:12px;background:#fff5f5;border:1px solid #fee2e2;color:#7f1d1d;'>";
        echo "<h3>Auth Debug</h3>";
        echo "<p>Session missing required keys or role != 'student'.</p>";
        echo "</div>";
        exit;
    }
    header('Location: student-login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$student = null;
$resolve_log = [];

try {
    // Attempt 1: students.user_id = user_id (preferred if available)
    $stmt = $conn->prepare("SELECT * FROM students WHERE user_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    $resolve_log[] = 'attempt students.user_id => ' . ($student ? 'FOUND (id=' . $student['id'] . ')' : 'no');

    // Attempt 2: maybe your login used the student id directly -> students.id = user_id
    if (!$student) {
        $stmt = $conn->prepare("SELECT * FROM students WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        $resolve_log[] = 'attempt students.id => ' . ($student ? 'FOUND (id=' . $student['id'] . ')' : 'no');
    }

    // Attempt 3: fallback via users.email -> students.email
    if (!$student) {
        $stmt = $conn->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user_id]);
        $user_email = $stmt->fetchColumn();
        $resolve_log[] = 'fetched users.email => ' . ($user_email ?: 'none');

        if ($user_email) {
            $stmt = $conn->prepare("SELECT * FROM students WHERE LOWER(TRIM(email)) = :email LIMIT 1");
            $stmt->execute(['email' => strtolower(trim($user_email))]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $resolve_log[] = 'attempt students.email match => ' . ($student ? 'FOUND (id=' . $student['id'] . ')' : 'no');
        } else {
            $resolve_log[] = 'skipped students.email attempt (no user email)';
        }
    }

    if (!$student) {
        // Show debug info (if debug) or friendly message
        if ($DEBUG) {
            echo "<div style='font-family:monospace;padding:12px;background:#fff7ed;border:1px solid #ffedd5;'>";
            echo "<h3>Resolve attempts</h3><pre>" . htmlspecialchars(print_r($resolve_log, true)) . "</pre>";
            echo "<p>ia_results table screenshot: /mnt/data/dc95d872-b0a6-4c6e-b999-d150e16d6c14.png</p>";
            echo "</div>";
            exit;
        }
        die("<div style='padding:20px;font-family:Inter,sans-serif;text-align:center;color:#721c24;background:#f8d7da;'>
                <h2>❌ Student Profile Not Found</h2>
                <p>Your login is valid but no linked student record exists. Ask admin to link your account.</p>
             </div>");
    }

    $student_id = (int)$student['id'];

    // Fetch IA results (LEFT JOIN so missing qp/subject won't drop rows)
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
        ORDER BY COALESCE(ir.created_at, ir.id) DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['sid' => $student_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    if ($DEBUG) {
        echo "<pre style='color:#b91c1c;'>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
    error_log("DB error: " . $e->getMessage());
    die("Database error. Contact admin.");
}

// If debug, show resolve log
if ($DEBUG) {
    echo "<div style='font-family:monospace;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;margin:10px 0;'><strong>Resolve log</strong><pre>"
         . htmlspecialchars(print_r($resolve_log, true)) . "</pre></div>";
}

// Render results (same styling as before)
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>IA Results</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:'Inter',sans-serif;background:#f1f5f9;margin:0;padding:40px 20px;color:#334155}
.container{max-width:900px;margin:0 auto;background:#fff;padding:30px;border-radius:12px;box-shadow:0 6px 18px rgba(2,6,23,0.06)}
.header-wrap{text-align:center}
h2{display:inline-block;margin:0 0 18px;padding-bottom:10px;border-bottom:3px solid #3b82f6;color:#0f172a}
table{width:100%;border-collapse:collapse;margin-top:16px}
th{background:#f8fafc;color:#64748b;padding:14px;text-align:left;font-weight:600;border-bottom:2px solid #e2e8f0}
td{padding:14px;border-bottom:1px solid #e2e8f0;font-size:0.95rem}
.score-badge{background:#dcfce7;color:#166534;padding:6px 12px;border-radius:20px;font-weight:700}
.date-text{color:#94a3b8;font-size:0.85rem}
.no-records{text-align:center;padding:36px;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1}
</style>
</head>
<body>
<div class="container">
  <div class="header-wrap">
    <h2>🏆 Internal Assessment Results</h2>
    <div style="color:#64748b;margin-top:8px">Student: <?= htmlspecialchars($student['student_name'] ?? ($student['name'] ?? 'Student')) ?> &nbsp; | &nbsp; <?= htmlspecialchars($student['usn'] ?? '') ?></div>
  </div>

  <?php if (empty($results)): ?>
    <div class="no-records">You haven't completed any tests yet.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Subject</th><th>Test Name</th><th>Score</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($results as $r): ?>
          <tr>
            <td style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($r['subject_name'] ?? 'General') ?></td>
            <td><?= htmlspecialchars($r['test_name'] ?? 'Untitled') ?></td>
            <td><span class="score-badge"><?= htmlspecialchars($r['marks'] ?? '0') ?> / <?= htmlspecialchars($r['max_marks'] ?? '0') ?></span></td>
            <td class="date-text"><?= !empty($r['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($r['created_at']))) : 'N/A' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div style="text-align:center;margin-top:20px"><a href="student-dashboard.php" style="color:#64748b;text-decoration:none">&laquo; Back to Dashboard</a></div>
</div>
</body>
</html>
