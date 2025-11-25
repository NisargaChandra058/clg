<?php
session_start();
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php'; // PDO Connection

// -------------------------------------------------------------------------
// 1. AUTHORIZATION & STUDENT LOOKUP (The Bridge)
// -------------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    // FIX: Instead of silently redirecting, show a clear message
    echo "<div style='display:flex; justify-content:center; align-items:center; height:100vh; background:#f8fafc; font-family:sans-serif;'>
            <div style='text-align:center; padding:40px; background:white; border-radius:10px; box-shadow:0 4px 6px rgba(0,0,0,0.1);'>
                <h2 style='color:#dc2626; margin-top:0;'>⚠️ Access Denied</h2>
                <p style='color:#475569; margin-bottom:20px;'>No active session found. You are not logged in.</p>
                <a href='login.php' style='background:#2563eb; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>Go to Login</a>
            </div>
          </div>";
    exit;
}

// Case-insensitive role check
$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'student') {
    die("Access Denied: You are not a student. <a href='logout.php'>Logout</a>");
}

$user_id = $_SESSION['user_id'];
$student = null;
$results = [];
$tests = [];
$attendance_stats = ['total' => 0, 'present' => 0, 'percentage' => 0];

try {
    // Get User Email
    $stmt_user = $pdo->prepare("SELECT email FROM users WHERE id = :id");
    $stmt_user->execute(['id' => $user_id]);
    $user_email = $stmt_user->fetchColumn();

    // Find Student Profile
    if ($user_email) {
        $stmt_stu = $pdo->prepare("SELECT * FROM students WHERE email = :email");
        $stmt_stu->execute(['email' => $user_email]);
        $student = $stmt_stu->fetch(PDO::FETCH_ASSOC);
    }

    if (!$student) {
        die("<div style='padding:20px; text-align:center; color:red;'>
                <h2>Profile Not Found</h2>
                <p>Logged in as user, but Student data is missing.</p>
                <a href='logout.php'>Logout</a>
             </div>");
    }

    $student_id = $student['id']; // The real academic ID

    // -------------------------------------------------------------------------
    // 1.5 SELF-HEALING: Ensure Tables/Columns Exist (Prevents Crashes)
    // -------------------------------------------------------------------------
    
    // Ensure 'student_test_allocation' table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_test_allocation (
            id SERIAL PRIMARY KEY,
            student_id INT NOT NULL,
            qp_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id, qp_id)
        );
    ");

    // Ensure 'ia_results' has required columns (Fail-safe)
    // This prevents 'Undefined column' errors if migration wasn't run
    try {
        $pdo->exec("ALTER TABLE ia_results ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        $pdo->exec("ALTER TABLE ia_results ADD COLUMN IF NOT EXISTS max_marks INT DEFAULT 100");
    } catch (Exception $ex) { /* Ignore if exists */ }


    // -------------------------------------------------------------------------
    // 2. FETCH DATA
    // -------------------------------------------------------------------------

    // A. FETCH RESULTS (Main View)
    $res_stmt = $pdo->prepare("
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
    ");
    $res_stmt->execute(['sid' => $student_id]);
    $results = $res_stmt->fetchAll(PDO::FETCH_ASSOC);

    // B. FETCH PENDING TESTS (For the Button)
    $test_stmt = $pdo->prepare("
        SELECT qp.id, qp.title, COALESCE(s.name, 'General') as subject_name
        FROM student_test_allocation sta
        JOIN question_papers qp ON sta.qp_id = qp.id
        LEFT JOIN subjects s ON qp.subject_id = s.id
        WHERE sta.student_id = :sid
        AND qp.id NOT IN (SELECT qp_id FROM ia_results WHERE student_id = :sid)
    ");
    $test_stmt->execute(['sid' => $student_id]);
    $tests = $test_stmt->fetchAll(PDO::FETCH_ASSOC);

    // C. FETCH ATTENDANCE (For the Button)
    // Wrapped in try-catch so dashboard loads even if attendance table is missing
    try {
        $att_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
            FROM attendance 
            WHERE student_id = :sid
        ");
        $att_stmt->execute(['sid' => $student_id]);
        $att_data = $att_stmt->fetch(PDO::FETCH_ASSOC);

        if ($att_data && $att_data['total'] > 0) {
            $attendance_stats['total'] = $att_data['total'];
            $attendance_stats['present'] = $att_data['present'];
            $attendance_stats['percentage'] = round(($att_data['present'] / $att_data['total']) * 100);
        }
    } catch (PDOException $e) {
        // Ignore attendance errors silently
    }

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Modern Reset & Variables */
        :root { --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #64748b; --primary: #2563eb; --success: #16a34a; --border: #e2e8f0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); padding: 20px; max-width: 1000px; margin: 0 auto; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
        .header h1 { font-size: 1.5rem; font-weight: 700; }
        .logout-btn { color: #ef4444; text-decoration: none; font-weight: 600; font-size: 0.9rem; border: 1px solid #fecaca; padding: 8px 16px; border-radius: 6px; transition: 0.2s; }
        .logout-btn:hover { background: #fef2f2; }

        /* Action Buttons Area */
        .action-bar { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
        .action-btn { flex: 1; padding: 15px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 1rem; transition: transform 0.2s, box-shadow 0.2s; color: white; min-width: 200px; }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        
        .btn-test { background: linear-gradient(135deg, #2563eb, #1d4ed8); }
        .btn-att { background: linear-gradient(135deg, #059669, #047857); }

        /* Results Section (Main Display) */
        .results-container { background: var(--card); padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border); }
        .section-title { font-size: 1.25rem; margin-bottom: 20px; color: var(--text); border-left: 4px solid var(--primary); padding-left: 12px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; background: #f1f5f9; color: var(--muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; border-radius: 6px 6px 0 0; }
        td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
        tr:last-child td { border-bottom: none; }
        
        .score { font-weight: 700; color: var(--text); }
        .score-max { color: var(--muted); font-weight: 400; font-size: 0.85em; }
        .no-data { text-align: center; padding: 40px; color: var(--muted); font-style: italic; }

        /* Popups / Modals */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-content { background: white; width: 90%; max-width: 500px; padding: 25px; border-radius: 12px; position: relative; animation: popup 0.3s ease-out; }
        @keyframes popup { from{transform:scale(0.9); opacity:0;} to{transform:scale(1); opacity:1;} }
        
        .close-btn { position: absolute; top: 15px; right: 15px; background: #f1f5f9; border: none; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; line-height: 30px; color: var(--muted); }
        .close-btn:hover { background: #e2e8f0; color: #333; }

        .test-list-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--border); margin-bottom: 10px; border-radius: 8px; transition: 0.2s; }
        .test-list-item:hover { border-color: var(--primary); background: #eff6ff; }
        .start-link { background: var(--primary); color: white; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; }

        /* Responsive */
        @media (max-width: 600px) { .header { flex-direction: column; gap: 10px; align-items: flex-start; } .logout-btn { width: 100%; text-align: center; } }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        <div>
            <h1>Hello, <?= htmlspecialchars($student['student_name']) ?></h1>
            <span style="color:#64748b; font-size:0.9rem;">
                <?= htmlspecialchars($student['usn'] ?? '') ?> | Semester <?= htmlspecialchars($student['semester']) ?>
            </span>
        </div>
        <a href="logout.php" class="logout-btn">Sign Out</a>
    </div>

    <!-- Action Buttons -->
    <div class="action-bar">
        <button class="action-btn btn-test" onclick="openModal('testModal')">
            📝 Take Pending Tests 
            <?php if(count($tests) > 0): ?>
                <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:10px; font-size:0.8em"><?= count($tests) ?></span>
            <?php endif; ?>
        </button>
        
        <button class="action-btn btn-att" onclick="openModal('attModal')">
            📊 View Attendance
        </button>
    </div>

    <!-- Results Section (Main Display) -->
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
                    <tr>
                        <th>Subject</th>
                        <th>Test Name</th>
                        <th>Score</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($r['subject_name']) ?></strong></td>
                        <td><?= htmlspecialchars($r['test_name']) ?></td>
                        <td>
                            <span class="score"><?= htmlspecialchars($r['marks']) ?></span>
                            <span class="score-max">/ <?= htmlspecialchars($r['max_marks']) ?></span>
                        </td>
                        <td style="color:#64748b; font-size:0.85rem;">
                            <?= date('M d, Y', strtotime($r['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- MODAL: Pending Tests -->
    <div id="testModal" class="modal">
        <div class="modal-content">
            <button class="close-btn" onclick="closeModal('testModal')">&times;</button>
            <h3>Available Tests</h3>
            <div style="margin-top:20px;">
                <?php if (empty($tests)): ?>
                    <p style="color:#64748b; text-align:center; padding:20px;">🎉 No pending tests!</p>
                <?php else: ?>
                    <?php foreach ($tests as $t): ?>
                        <div class="test-list-item">
                            <div>
                                <strong><?= htmlspecialchars($t['title']) ?></strong><br>
                                <small style="color:#64748b"><?= htmlspecialchars($t['subject_name']) ?></small>
                            </div>
                            <a href="take-test.php?id=<?= $t['id'] ?>" class="start-link">Start</a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: Attendance -->
    <div id="attModal" class="modal">
        <div class="modal-content" style="text-align:center;">
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
        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = "none";
            }
        }
    </script>

</body>
</html>
