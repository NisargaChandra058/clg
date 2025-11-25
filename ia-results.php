<?php
// ia-results-debug.php - temporary combined debug + results page
// Replace your current ia-results.php with this file for debugging.
// Remove debug & test-session code after you fix the issue.

declare(strict_types=1);

// ---------- config ----------
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
$ALLOW_TEST_SESSION = true; // temporary: set to false before removing file

// include safe session config BEFORE starting session if it exists
if (file_exists(__DIR__ . '/session_config.php')) {
    require_once __DIR__ . '/session_config.php';
}

// start session exactly once
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// temporary helper: create a fake session for testing (DEV ONLY)
if ($ALLOW_TEST_SESSION && isset($_GET['set_test_session']) && $_GET['set_test_session'] == '1') {
    // WARNING: use only on local/dev. This forces a logged-in student with user_id=1.
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'student';
    // regenerate id to mimic login
    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }
    $test_set_msg = "Test session set: user_id=1, role=student (remove this in production).";
} else {
    $test_set_msg = null;
}

// Debug output (non-sensitive)
if ($DEBUG) {
    echo "<div style='font-family:monospace; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; margin:10px 0;'>";
    echo "<strong>DEBUG</strong><br>";
    echo "headers_sent? " . (headers_sent() ? "YES" : "NO") . "<br>";
    echo "session_id: " . htmlspecialchars(session_id()) . "<br>";
    echo "session.save_path: " . htmlspecialchars(ini_get('session.save_path')) . "<br>";
    echo "session.cookie_secure: " . htmlspecialchars(ini_get('session.cookie_secure')) . "<br>";
    echo "session.cookie_samesite: " . (ini_get('session.cookie_samesite') ?: '(none)') . "<br>";
    echo "Incoming cookies: <pre>" . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>";
    echo "Session array: <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>";
    if ($test_set_msg) echo "<div style='color:green; font-weight:700; margin-top:8px;'>" . htmlspecialchars($test_set_msg) . "</div>";
    echo "</div>";
}

// Enable verbose PHP errors for debugging (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// require DB (must not echo)
require_once __DIR__ . '/db.php'; // must define $pdo

// Authorization: require session user_id and role student
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || $role !== 'student') {
    // If debugging, show a clear message without redirecting so you can inspect session/cookies
    if ($DEBUG) {
        echo "<div style='font-family:Inter, sans-serif; padding:12px; border:1px solid #fee2e2; background:#fff5f5; color:#7f1d1d;'>";
        echo "<h3>Auth Debug</h3>";
        echo "<p>Session missing required keys or role != 'student'.</p>";
        echo "<ul>";
        echo "<li>session user_id: <strong>" . htmlspecialchars((string)($_SESSION['user_id'] ?? 'MISSING')) . "</strong></li>";
        echo "<li>session role: <strong>" . htmlspecialchars((string)($_SESSION['role'] ?? 'MISSING')) . "</strong> (normalized: '" . htmlspecialchars($role) . "')</li>";
        echo "</ul>";
        echo "<p>Common fixes:</p>";
        echo "<ol><li>Ensure your login script does <code>session_start(); $_SESSION['user_id'] = $userId; $_SESSION['role'] = strtolower(trim($roleFromDb));</code></li>";
        echo "<li>Check cookies: PHPSESSID must be present in browser (see Incoming cookies above).</li>";
        echo "<li>Check session.save_path is writable by webserver.</li></ol>";
        echo "<p>To test the results display without your login system, reload this page with <code>?set_test_session=1&amp;debug=1</code> (dev only).</p>";
        echo "</div>";
        exit;
    }
    // production behaviour: redirect to login
    header('Location: student-login.php');
    exit;
}

// If we reach here, session indicates an authenticated student
$user_id = (int) $_SESSION['user_id'];

// Resolve student id: prefer students.user_id, fallback to users.email -> students.email
$student = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        // fallback by email
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user_id]);
        $user_email = $stmt->fetchColumn();
        if ($user_email) {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE LOWER(TRIM(email)) = :email LIMIT 1");
            $stmt->execute(['email' => strtolower(trim($user_email))]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$student) {
        echo "<div style='padding:20px; background:#fff7ed; border:1px solid #ffedd5; font-family:Inter, sans-serif;'>";
        echo "<h3>Student profile missing</h3>";
        echo "<p>Account exists but no student record found for this user.</p>";
        echo "<p>Ask admin to link student or run the 'Fix Missing Students' tool.</p>";
        echo "</div>";
        exit;
    }

    $student_id = (int)$student['id'];

    // Fetch IA results (LEFT JOIN to avoid losing rows if question_papers missing)
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
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $student_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<pre style='color:#b91c1c;'>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

// HTML output (same look as yours)
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IA Results (Debug)</title>
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
                    <td class="date-text"><?= !empty($r['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($r['created_at']))) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="text-align:center;margin-top:20px">
        <a href="student-dashboard.php" style="color:#64748b;text-decoration:none">&laquo; Back to Dashboard</a>
    </div>
</div>
</body>
</html>
