<?php
// ia-results.php - debug + fixed version
declare(strict_types=1);

// ---- CONFIG: enable debug by adding ?debug=1 to URL (temporary only) ----
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// IMPORTANT: start session BEFORE any output. Do not include files that echo before this.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Non-sensitive debug info (temporary)
if ($DEBUG) {
    echo "<!-- DEBUG MODE -->\n";
    echo "<div style='font-family:monospace; padding:8px; background:#f8fafc; border:1px solid #e2e8f0;'>";
    echo "<strong>Debug info</strong><br>";
    echo "headers_sent? " . (headers_sent() ? "YES" : "NO") . "<br>";
    echo "session_id: " . htmlspecialchars(session_id()) . "<br>";
    echo "session cookie params: " . htmlspecialchars(json_encode(session_get_cookie_params())) . "<br>";
    echo "Incoming cookies: <pre>" . htmlspecialchars(print_r($_COOKIE, true)) . "</pre>";
    echo "Session array keys: <pre>" . htmlspecialchars(print_r(array_keys($_SESSION), true)) . "</pre>";
    echo "</div>";
}

// If you used session_config.php that sets cookie params, require it AFTER session_start only if it doesn't echo.
// If session_config.php calls session_start itself or outputs anything, avoid requiring it here.
if (file_exists(__DIR__ . '/session_config.php')) {
    // require but only if it's safe; if it starts session again it won't break.
    require_once __DIR__ . '/session_config.php';
}

// Error display (dev only)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Require DB (must NOT echo anything)
require_once __DIR__ . '/db.php'; // provides $pdo

// ------------------------
// Authorization check
// ------------------------
// Normalize role to avoid ' Student ' or 'STUDENT' mismatches
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

// If not logged in, show debug info (in debug) or redirect
if (!isset($_SESSION['user_id']) || $role !== 'student') {
    if ($DEBUG) {
        echo "<div style='font-family:Inter, sans-serif; margin:18px; padding:14px; border:1px solid #fee2e2; background:#fff5f5; color:#7f1d1d;'>";
        echo "<h3>Auth Debug</h3>";
        echo "<p>session user_id: " . htmlspecialchars((string)($_SESSION['user_id'] ?? 'MISSING')) . "</p>";
        echo "<p>session role: " . htmlspecialchars((string)($_SESSION['role'] ?? 'MISSING')) . " (normalized -> '" . htmlspecialchars($role) . "')</p>";
        echo "<p>If the login script sets these values, make sure it uses <code>session_start()</code> and sets <code>\$_SESSION['user_id']</code> and <code>\$_SESSION['role']</code>.</p>";
        echo "</div>";
        // Halt so you can inspect session values in the browser
        exit;
    }
    // production behavior: redirect to login
    header('Location: student-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$results = [];

try {
    // ------------------------
    // Resolve user -> student (by email)
    // ------------------------
    $stmt_user = $pdo->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
    $stmt_user->execute(['id' => $user_id]);
    $user_email = $stmt_user->fetchColumn();

    $student_id = null;
    $student = null;
    if ($user_email) {
        $stmt_stu = $pdo->prepare("SELECT * FROM students WHERE LOWER(TRIM(email)) = :email LIMIT 1");
        $stmt_stu->execute(['email' => strtolower(trim($user_email))]);
        $student = $stmt_stu->fetch(PDO::FETCH_ASSOC);
        $student_id = $student['id'] ?? null;
    }

    if (!$student_id) {
        // developer friendly when debugging, otherwise user friendly message
        if ($DEBUG) {
            echo "<div style='font-family:monospace; padding:10px; background:#fff7ed; border:1px solid #ffedd5;'>";
            echo "Bridge Debug: user_id=" . htmlspecialchars((string)$user_id) . "<br>";
            echo "user_email=" . htmlspecialchars((string)$user_email) . "<br>";
            echo "student_id resolved: (none)<br>";
            echo "Run: SELECT id FROM students WHERE LOWER(TRIM(email)) = '" . htmlspecialchars(strtolower(trim((string)$user_email))) . "'</div>";
            exit;
        }
        die("<div style='padding:20px; font-family:sans-serif; text-align:center; color:#721c24; background:#f8d7da;'>
                <h2>❌ Student Profile Not Found</h2>
                <p>Your login works, but we couldn't find your academic record.</p>
                <p><strong>To Fix:</strong> Ask the Admin to use the 'Fix Missing Students' button or ensure emails match.</p>
                <a href='student-dashboard.php' style='background:#333; color:white; padding:10px; text-decoration:none; border-radius:5px;'>Back to Dashboard</a>
             </div>");
    }

    // ------------------------
    // Fetch results - use LEFT JOIN for safety
    // ------------------------
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
    } else {
        error_log("DB error on IA results: " . $e->getMessage());
        die("An unexpected database error occurred. Please contact admin.");
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IA Results</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* Keep your styles (same as original) */
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
                            <td class="date-text"><?= $row['created_at'] ? htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) : '' ?></td>
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
