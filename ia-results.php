<?php
// ia-results.php  (clean, syntax-safe)
// Replace your current file contents with this file. Temporary debug via ?debug=1

declare(strict_types=1);

// ---------- CONFIG ----------
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Start session before output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Dev debug (non-sensitive)
if ($DEBUG) {
    echo "<div style='font-family:monospace;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;margin:8px 0;'>";
    echo "<strong>DEBUG</strong><br>";
    echo "headers_sent? " . (headers_sent() ? "YES" : "NO") . "<br>";
    echo "session_id: " . htmlspecialchars(session_id()) . "<br>";
    echo "Cookies: <pre>" . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>";
    echo "Session: <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>";
    echo "</div>";
}

// Show errors while debugging (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ------------------
// Database include
// ------------------
// Make sure db.php exists and sets $pdo (PDO instance). Adjust path if needed.
require_once __DIR__ . '/db.php';

// ------------------
// Authorization
// ------------------
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || $role !== 'student') {
    if ($DEBUG) {
        echo "<div style='font-family:Inter, sans-serif;padding:12px;border:1px solid #fee2e2;background:#fff5f5;color:#7f1d1d;'>";
        echo "<h3>Auth debug</h3>";
        echo "<p>session user_id: <strong>" . htmlspecialchars((string)($_SESSION['user_id'] ?? 'MISSING')) . "</strong></p>";
        echo "<p>session role: <strong>" . htmlspecialchars((string)($_SESSION['role'] ?? 'MISSING')) . "</strong> (normalized: '" . htmlspecialchars($role) . "')</p>";
        echo "<p>If you expect to be logged in, ensure your login script calls <code>session_start()</code> and sets <code>\$_SESSION['user_id']</code> and <code>\$_SESSION['role']</code>.</p>";
        echo "</div>";
        exit;
    }
    header('Location: student-login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// ------------------
// Resolve student
// ------------------
$student = null;
try {
    // 1) Try students.user_id mapping
    $stmt = $pdo->prepare("SELECT * FROM students WHERE user_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        // 2) Fallback by email
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
        // friendly message
        echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
        echo "<title>Student Not Found</title>";
        echo "<style>body{font-family:Inter,sans-serif;background:#f8fafc;padding:30px;color:#111} .card{max-width:700px;margin:40px auto;background:#fff;padding:24px;border-radius:10px;box-shadow:0 6px 18px rgba(2,6,23,0.06)}</style>";
        echo "</head><body><div class='card'><h2>Student Profile Not Found</h2><p>Account is valid but no student record exists for this user. Contact admin.</p>";
        echo "<p><a href='logout.php' style='display:inline-block;margin-top:12px;padding:8px 12px;background:#111;color:#fff;border-radius:6px;text-decoration:none;'>Logout</a></p></div></body></html>";
        exit;
    }

    $student_id = (int)$student['id'];

    // ------------------
    // Fetch results
    // ------------------
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
    if ($DEBUG) {
        echo "<pre style='color:#b91c1c;'>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
    error_log("DB error: " . $e->getMessage());
    die("An unexpected error occurred. Please contact admin.");
}

// ------------------
// Render HTML
// ------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IA Results</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f1f5f9;--card:#fff;--text:#334155;--muted:#94a3b8;--accent:#3b82f6;--border:#e2e8f0}
body{font-family:'Inter',sans-serif;background:var(--bg);margin:0;padding:40px 20px;color:var(--text)}
.container{max-width:900px;margin:0 auto;background:var(--card);padding:30px;border-radius:16px;box-shadow:0 6px 18px rgba(2,6,23,0.06)}
.header-wrap{text-align:center}
h2{display:inline-block;margin:0 0 18px;padding-bottom:10px;border-bottom:3px solid var(--accent);color:#0f172a}
table{width:100%;border-collapse:collapse;margin-top:16px}
th{background:#f8fafc;color:#64748b;padding:14px;text-align:left;font-weight:600;border-bottom:2px solid var(--border)}
td{padding:14px;border-bottom:1px solid var(--border);font-size:0.95rem}
.score-badge{background:#dcfce7;color:#166534;padding:6px 12px;border-radius:20px;font-weight:700}
.date-text{color:var(--muted);font-size:0.85rem}
.back-link{display:inline-block;margin-top:26px;text-decoration:none;color:#64748b;font-weight:500;transition:color .2s}
.back-link:hover{color:var(--accent)}
.no-records{text-align:center;padding:36px;color:var(--muted);font-style:italic;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1}
.meta{color:var(--muted);font-size:0.95rem;margin-bottom:14px}
</style>
</head>
<body>
    <div class="container">
        <div class="header-wrap">
            <h2>🏆 Internal Assessment Results</h2>
            <div class="meta">Student: <?= htmlspecialchars($student['student_name'] ?? ($student['name'] ?? 'Student')) ?> &nbsp; | &nbsp; <?= htmlspecialchars($student['usn'] ?? '') ?></div>
        </div>

        <?php if (empty($results)): ?>
            <div class="no-records">You haven't completed any tests yet.</div>
        <?php else: ?>
            <table aria-live="polite">
                <thead>
                    <tr><th>Subject</th><th>Test Name</th><th>Score</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($row['subject_name'] ?? 'General') ?></td>
                            <td><?= htmlspecialchars($row['test_name'] ?? 'Untitled') ?></td>
                            <td><span class="score-badge"><?= htmlspecialchars($row['marks'] ?? '0') ?> / <?= htmlspecialchars($row['max_marks'] ?? '0') ?></span></td>
                            <td class="date-text"><?= !empty($row['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) : '' ?></td>
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
