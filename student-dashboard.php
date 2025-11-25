<?php
// ia-results.php
declare(strict_types=1);

// Quick config
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// show non-sensitive debug info (temporary)
if ($DEBUG) {
    echo "<!-- DEBUG MODE ON -->\n";
    echo "<div style='font-family:monospace; padding:8px; background:#f8fafc; border:1px solid #e2e8f0;'>";
    echo "headers_sent? " . (headers_sent() ? "YES" : "NO") . "<br>";
    echo "session_id: " . session_id() . "<br>";
    echo "session keys: " . htmlspecialchars(json_encode(array_keys($_SESSION))) . "<br>";
    echo "</div>";
}

require_once 'db.php'; // must provide a working $pdo (PDO instance)

// ---------------------------
// Authorization & student lookup
// ---------------------------
if (!isset($_SESSION['user_id'])) {
    echo "<div style='display:flex; justify-content:center; align-items:center; height:100vh; background:#f8fafc; font-family:sans-serif;'>
            <div style='text-align:center; padding:40px; background:white; border-radius:10px; box-shadow:0 4px 6px rgba(0,0,0,0.1);'>
                <h2 style='color:#dc2626; margin-top:0;'>⚠️ Access Denied</h2>
                <p style='color:#475569; margin-bottom:20px;'>No active session found. You are not logged in.</p>
                <a href='login.php' style='background:#2563eb; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>Go to Login</a>
            </div>
          </div>";
    exit;
}

// normalize role
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($role !== 'student') {
    die("Access Denied: You are not a student. <a href='logout.php'>Logout</a>");
}

$user_id = $_SESSION['user_id'];
$student = null;
$results = [];
$tests = [];
$attendance_stats = ['total' => 0, 'present' => 0, 'percentage' => 0];

try {
    // get user email
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user_id]);
    $user_email = $stmt->fetchColumn();

    if ($user_email !== false && $user_email !== null) {
        $stmt2 = $pdo->prepare("SELECT * FROM students WHERE LOWER(TRIM(email)) = :email LIMIT 1");
        $stmt2->execute(['email' => strtolower(trim($user_email))]);
        $student = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if (!$student) {
        echo "<div style='padding:20px; text-align:center; color:#b91c1c; font-family:Inter, sans-serif;'>
                <h2>Profile Not Found</h2>
                <p>Logged in as user, but student data is missing. Ask admin to link your academic profile.</p>
                <a href='logout.php' style='display:inline-block;margin-top:12px;padding:8px 12px;background:#111;color:#fff;border-radius:6px;text-decoration:none;'>Logout</a>
              </div>";
        exit;
    }

    $student_id = $student['id'];

    // ---------------------------
    // IMPORTANT: runtime schema changes are risky.
    // Run migrations separately instead of creating/altering tables here.
    // If you still need a runtime helper, check DB driver and run carefully.
    // ---------------------------

    // ---------------------------
    // Fetch IA results (use LEFT JOIN so orphaned question_papers/subjects don't drop results)
    // ---------------------------
    $sqlResults = "
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
    $stmtR = $pdo->prepare($sqlResults);
    $stmtR->execute(['sid' => $student_id]);
    $results = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    // ---------------------------
    // Fetch pending tests (robust correlated NOT EXISTS)
    // ---------------------------
    $sqlTests = "
        SELECT qp.id, qp.title, COALESCE(s.name, 'General') AS subject_name
        FROM student_test_allocation sta
        JOIN question_papers qp ON sta.qp_id = qp.id
        LEFT JOIN subjects s ON qp.subject_id = s.id
        WHERE sta.student_id = :sid
          AND NOT EXISTS (
              SELECT 1 FROM ia_results ir2
              WHERE ir2.qp_id = qp.id AND ir2.student_id = :sid_for_exists
          )
        ORDER BY qp.title
    ";
    $stmtT = $pdo->prepare($sqlTests);
    // bind both parameters explicitly to be safe across drivers
    $stmtT->bindValue(':sid', $student_id, PDO::PARAM_INT);
    $stmtT->bindValue(':sid_for_exists', $student_id, PDO::PARAM_INT);
    $stmtT->execute();
    $tests = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    // ---------------------------
    // Attendance (safe)
    // ---------------------------
    try {
        $att = $pdo->prepare("
            SELECT 
              COUNT(*) AS total,
              SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present
            FROM attendance
            WHERE student_id = :sid
        ");
        $att->execute(['sid' => $student_id]);
        $att_row = $att->fetch(PDO::FETCH_ASSOC);
        if ($att_row && (int)$att_row['total'] > 0) {
            $attendance_stats['total'] = (int)$att_row['total'];
            $attendance_stats['present'] = (int)$att_row['present'];
            $attendance_stats['percentage'] = (int)round(($attendance_stats['present'] / $attendance_stats['total']) * 100);
        }
    } catch (PDOException $e) {
        // ignore attendance errors (table might not exist)
        if ($DEBUG) {
            echo "<div style='font-family:monospace; padding:8px; background:#fff4e6; border:1px solid #ffd8a8;'>Attendance query failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

} catch (PDOException $e) {
    if ($DEBUG) {
        echo "<pre style='color:#b91c1c;'>DB Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
    } else {
        error_log("IA Results DB error for user {$user_id}: " . $e->getMessage());
        echo "<div style='padding:20px; font-family:Inter, sans-serif; color:#b91c1c;'>An unexpected error occurred. Please contact support.</div>";
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Student Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
/* (same styles as you had; condensed for brevity) */
:root { --bg:#f8fafc; --card:#fff; --text:#0f172a; --muted:#64748b; --primary:#2563eb; --border:#e2e8f0; }
*{box-sizing:border-box} body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);margin:0;padding:20px;max-width:1000px;margin-left:auto;margin-right:auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;padding-bottom:20px;border-bottom:1px solid var(--border)}
.header h1{font-size:1.5rem;font-weight:700}
.logout-btn{color:#ef4444;text-decoration:none;font-weight:600;font-size:0.9rem;border:1px solid #fecaca;padding:8px 16px;border-radius:6px}
.action-bar{display:flex;gap:15px;margin-bottom:30px;flex-wrap:wrap}
.action-btn{flex:1;padding:15px;border-radius:10px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;font-size:1rem;color:white;min-width:200px}
.btn-test{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.btn-att{background:linear-gradient(135deg,#059669,#047857)}
.results-container{background:var(--card);padding:25px;border-radius:12px;border:1px solid var(--border)}
.section-title{font-size:1.25rem;margin-bottom:20px;color:var(--text);border-left:4px solid var(--primary);padding-left:12px}
table{width:100%;border-collapse:collapse}
th{background:#f1f5f9;color:var(--muted);padding:14px;text-align:left;font-size:0.85rem}
td{padding:14px;border-bottom:1px solid var(--border);font-size:0.95rem}
.no-data{text-align:center;padding:36px;color:var(--muted);font-style:italic}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center}
.modal-content{background:white;width:90%;max-width:500px;padding:25px;border-radius:12px}
.close-btn{position:absolute;top:15px;right:15px;background:#f1f5f9;border:none;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:1.2rem;color:var(--muted)}
.test-list-item{display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--border);margin-bottom:10px;border-radius:8px}
@media (max-width:600px){.header{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>

<div class="header">
    <div>
        <h1>Hello, <?= htmlspecialchars($student['student_name'] ?? ($student['name'] ?? 'Student')) ?></h1>
        <span style="color:var(--muted);font-size:0.9rem;">
            <?= htmlspecialchars($student['usn'] ?? '') ?> <?= $student['semester'] ? '| Semester ' . htmlspecialchars($student['semester']) : '' ?>
        </span>
    </div>
    <a href="logout.php" class="logout-btn">Sign Out</a>
</div>

<div class="action-bar">
    <button class="action-btn btn-test" onclick="openModal('testModal')">
        📝 Take Pending Tests
        <?php if (count($tests) > 0): ?>
            <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:10px; font-size:0.8em"><?= count($tests) ?></span>
        <?php endif; ?>
    </button>

    <button class="action-btn btn-att" onclick="openModal('attModal')">
        📊 View Attendance
    </button>
</div>

<div class="results-container">
    <h2 class="section-title">Exam Results</h2>
    <?php if (empty($results)): ?>
        <div class="no-data">
            <p>No results found yet.</p>
            <small>Completed tests will appear here.</small>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Subject</th><th>Test Name</th><th>Score</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['subject_name'] ?? 'General') ?></strong></td>
                        <td><?= htmlspecialchars($r['test_name'] ?? 'Untitled') ?></td>
                        <td>
                            <span style="font-weight:700;"><?= htmlspecialchars($r['marks'] ?? '0') ?></span>
                            <span style="color:var(--muted);">/ <?= htmlspecialchars($r['max_marks'] ?? '0') ?></span>
                        </td>
                        <td style="color:var(--muted);font-size:0.85rem;">
                            <?php
                                $date = $r['created_at'] ?? null;
                                echo $date ? htmlspecialchars(date('M d, Y', strtotime($date))) : '';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Test Modal -->
<div id="testModal" class="modal">
    <div class="modal-content">
        <button class="close-btn" onclick="closeModal('testModal')">&times;</button>
        <h3>Available Tests</h3>
        <div style="margin-top:20px;">
            <?php if (empty($tests)): ?>
                <p style="color:var(--muted); text-align:center; padding:20px;">🎉 No pending tests!</p>
            <?php else: ?>
                <?php foreach ($tests as $t): ?>
                    <div class="test-list-item">
                        <div>
                            <strong><?= htmlspecialchars($t['title']) ?></strong><br>
                            <small style="color:var(--muted)"><?= htmlspecialchars($t['subject_name']) ?></small>
                        </div>
                        <a href="take-test.php?id=<?= (int)$t['id'] ?>" style="background:var(--primary);color:#fff;padding:6px 12px;border-radius:4px;text-decoration:none;font-weight:600;">Start</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Attendance Modal -->
<div id="attModal" class="modal">
    <div class="modal-content" style="text-align:center; position:relative;">
        <button class="close-btn" onclick="closeModal('attModal')">&times;</button>
        <h3>Attendance Overview</h3>
        <div style="margin:30px auto; width:120px; height:120px; border-radius:50%; background: conic-gradient(#16a34a <?= $attendance_stats['percentage'] ?>%, #f1f5f9 0); display:flex; align-items:center; justify-content:center;">
            <div style="width:100px; height:100px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-direction:column;">
                <span style="font-size:1.5rem; font-weight:800; color:#0f172a"><?= $attendance_stats['percentage'] ?>%</span>
            </div>
        </div>
        <p><strong><?= $attendance_stats['present'] ?></strong> Present out of <strong><?= $attendance_stats['total'] ?></strong> Classes</p>
    </div>
</div>

<script>
function openModal(id){ document.getElementById(id).style.display='flex'; }
function closeModal(id){ document.getElementById(id).style.display='none'; }
window.onclick = function(event){ if (event.target.classList.contains('modal')) event.target.style.display='none'; }
</script>

</body>
</html>
