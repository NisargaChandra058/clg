<?php
// ia-results.php
// Full improved version of your original script.
// Requirements: a working `db.php` that sets up $pdo (PDO instance).
// IMPORTANT: Do NOT leave debug enabled in production.

declare(strict_types=1);

// --- Configuration for debug (use only temporarily) ---
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Start session BEFORE any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Optional debug header output (non-sensitive)
if ($DEBUG) {
    echo "<!-- DEBUG MODE ENABLED -->\n";
    echo "<div style='font-family:monospace; background:#f8fafc; color:#0b1220; padding:10px; border:1px solid #e2e8f0; margin:12px 0;'>";
    echo "<strong>DEBUG</strong><br>";
    echo "headers_sent? " . (headers_sent() ? "YES" : "NO") . "<br>";
    echo "session_id: " . session_id() . "<br>";
    echo "session cookie params: " . htmlspecialchars(json_encode(session_get_cookie_params())) . "<br>";
    echo "session keys: " . htmlspecialchars(json_encode(array_keys($_SESSION))) . "<br>";
    echo "</div>";
}

// No output beyond this point before possible redirect
require_once 'db.php'; // must provide $pdo (PDO instance)

////////////////////////////////////////////////////////////////////////////////
// 1) Authorization check
////////////////////////////////////////////////////////////////////////////////

// Normalized role check (trim + lowercase)
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));

// If not logged in as student -> redirect to login
if (!isset($_SESSION['user_id']) || $role !== 'student') {
    // For debug, print a helpful message and stop so you can inspect session values.
    if ($DEBUG) {
        echo "<div style='font-family:monospace; background:#fff3cd; color:#856404; padding:10px; border:1px solid #ffeeba;'>";
        echo "<strong>AUTH DEBUG:</strong><br>";
        echo "Missing or invalid session. \n";
        echo "session user_id: " . htmlspecialchars((string)($_SESSION['user_id'] ?? '')) . "<br>";
        echo "session role: " . htmlspecialchars((string)($_SESSION['role'] ?? '')) . "<br>";
        echo "<p>Set the session from your login script and try again.</p>";
        echo "</div>";
        exit;
    }

    header('Location: student-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$results = [];

////////////////////////////////////////////////////////////////////////////////
// 2) Resolve USER -> STUDENT (bridge)
////////////////////////////////////////////////////////////////////////////////

try {
    // A. Get user email (limit 1)
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user_id]);
    $user_email = $stmt->fetchColumn();

    $student_id = null;

    if ($user_email !== false && $user_email !== null) {
        // Normalize email for matching
        $norm_email = strtolower(trim($user_email));
        $stmt2 = $pdo->prepare("SELECT id FROM students WHERE LOWER(TRIM(email)) = :email LIMIT 1");
        $stmt2->execute(['email' => $norm_email]);
        $student_id = $stmt2->fetchColumn();
    }

    if (!$student_id) {
        // If debug, show helpful message; otherwise show friendly UI message and stop.
        if ($DEBUG) {
            echo "<div style='font-family:monospace; background:#fff1f2; color:#721c24; padding:10px; border:1px solid #f5c6cb;'>";
            echo "<strong>BRIDGE DEBUG:</strong><br>";
            echo "User exists? " . ($user_email ? 'YES' : 'NO') . "<br>";
            echo "user email: " . htmlspecialchars((string)($user_email ?? '')) . "<br>";
            echo "mapped student_id: (none)<br>";
            echo "Try running: SELECT id FROM students WHERE LOWER(TRIM(email)) = '" . htmlspecialchars(strtolower(trim((string)$user_email))) . "'<br>";
            echo "</div>";
            exit;
        }

        // Friendly message for the user if no student profile found
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Student Not Found</title>";
        echo "<style>body{font-family:Inter, sans-serif;background:#f8fafc;color:#111;padding:30px} .card{max-width:720px;margin:40px auto;background:#fff;padding:24px;border-radius:10px;box-shadow:0 4px 8px rgba(2,6,23,0.06)}</style>";
        echo "</head><body><div class='card'>";
        echo "<h2>❌ Student Profile Not Found</h2>";
        echo "<p>Your account is valid, but we couldn't locate an academic profile linked to your email address.</p>";
        echo "<p><strong>What you can do:</strong></p>";
        echo "<ul><li>Ask an admin to use the 'Fix Missing Students' button on the Assign Subject page.</li>";
        echo "<li>Or ensure your users.email matches students.email exactly.</li></ul>";
        echo "<p><a href='student-dashboard.php' style='display:inline-block;margin-top:12px;padding:8px 12px;background:#111;color:#fff;border-radius:6px;text-decoration:none'>Back to Dashboard</a></p>";
        echo "</div></body></html>";
        exit;
    }

    // 3) Fetch IA results. Use LEFT JOIN so orphaned qp/subject rows don't drop results.
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

    $stmt3 = $pdo->prepare($sql);
    $stmt3->execute(['sid' => $student_id]);
    $results = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    if ($DEBUG) {
        echo "<div style='font-family:monospace; background:#eef2ff; color:#1e293b; padding:10px; border:1px solid #e0e7ff;'>";
        echo "Fetched " . count($results) . " result(s) for student_id: " . htmlspecialchars((string)$student_id);
        echo "</div>";
    }

} catch (PDOException $e) {
    // In debug mode show error; otherwise log and show a friendly message.
    if ($DEBUG) {
        echo "<pre style='background:#fff0f6;color:#6b0214;padding:10px;border:1px solid #ffd1da;'>PDO Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    } else {
        error_log("DB error on IA results for user {$user_id}: " . $e->getMessage());
        // Friendly fallback UI
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Error</title></head><body>";
        echo "<p>An unexpected error occurred. Please contact support.</p>";
        echo "</body></html>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>IA Results</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f1f5f9; --card:#ffffff; --muted:#94a3b8; --text:#334155; --accent:#3b82f6; --danger-bg:#f8d7da; --danger-text:#721c24; }
        body{font-family:'Inter',sans-serif;background:var(--bg);margin:0;padding:40px 20px;color:var(--text)}
        .container{max-width:920px;margin:0 auto;background:var(--card);padding:30px;border-radius:16px;box-shadow:0 6px 18px rgba(2,6,23,0.06)}
        .header-wrap{text-align:center}
        h2{display:inline-block;margin:0 0 18px;padding-bottom:10px;border-bottom:3px solid var(--accent);color:#0f172a}
        table{width:100%;border-collapse:collapse;margin-top:16px}
        th{background:#f8fafc;color:#64748b;padding:14px;text-align:left;font-weight:600;border-bottom:2px solid #e2e8f0}
        td{padding:14px;border-bottom:1px solid #e2e8f0;font-size:0.95rem}
        .score-badge{background:#dcfce7;color:#166534;padding:6px 12px;border-radius:20px;font-weight:700}
        .date-text{color:var(--muted);font-size:0.85rem}
        .back-link{display:inline-block;margin-top:26px;text-decoration:none;color:#64748b;font-weight:500;transition:color .2s}
        .back-link:hover{color:var(--accent)}
        .no-records{text-align:center;padding:36px;color:var(--muted);font-style:italic;background:#f8fafc;border-radius:8px;border:1px dashed #cbd5e1}
        .alert{padding:12px;border-radius:8px;margin-bottom:16px}
        .alert-danger{background:var(--danger-bg);color:var(--danger-text);border:1px solid #f5c6cb}
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
            <table aria-live="polite">
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
                            <td style="font-weight:500; color:#0f172a;"><?= htmlspecialchars($row['subject_name'] ?? 'General') ?></td>
                            <td><?= htmlspecialchars($row['test_name'] ?? 'Untitled') ?></td>
                            <td>
                                <span class="score-badge"><?= htmlspecialchars($row['marks'] ?? '0') ?> / <?= htmlspecialchars($row['max_marks'] ?? '0') ?></span>
                            </td>
                            <td class="date-text"><?= htmlspecialchars($row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) : '') ?></td>
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
