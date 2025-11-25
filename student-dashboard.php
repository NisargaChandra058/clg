<?php
// student-dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1); // Show errors for debugging

session_start();
require_once('db.php'); // PDO connection ($pdo)

// -------------------------------------------------------------------------
// 1. AUTHORIZATION FIX
// -------------------------------------------------------------------------
// We check for 'user_id' because that is what your Login page sets.
if (!isset($_SESSION['user_id'])) {
    header('Location: student-login.php');
    exit;
}

// We assume the logged-in user IS the student (Single Table Architecture)
$student_id = (int) $_SESSION['user_id'];
$student_name = $_SESSION['name'] ?? 'Student';

$tests = [];
$results = [];
$attendance_summary = [];
$page_error = '';

try {
    // -------------------------------------------------------------------------
    // 2. FETCH STUDENT DETAILS
    // -------------------------------------------------------------------------
    $stmt = $pdo->prepare("SELECT student_name, email, semester, usn FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $student_name = $student['student_name'];
        $semester = $student['semester'] ?? 0;

        // ---------------------------------------------------------------------
        // 3. FETCH PENDING TESTS (From student_test_allocation)
        // ---------------------------------------------------------------------
        // We look for tests assigned to THIS student that they haven't taken yet
        $test_stmt = $pdo->prepare("
            SELECT qp.id, qp.title, COALESCE(s.name, 'General') as subject_name
            FROM student_test_allocation sta
            JOIN question_papers qp ON sta.qp_id = qp.id
            LEFT JOIN subjects s ON qp.subject_id = s.id
            WHERE sta.student_id = :sid
            AND qp.id NOT IN (SELECT qp_id FROM ia_results WHERE student_id = :sid)
            ORDER BY qp.id DESC
        ");
        $test_stmt->execute([':sid' => $student_id]);
        $tests = $test_stmt->fetchAll(PDO::FETCH_ASSOC);

        // ---------------------------------------------------------------------
        // 4. FETCH RECENT RESULTS
        // ---------------------------------------------------------------------
        $results_stmt = $pdo->prepare("
            SELECT 
                COALESCE(s.name, '—') AS subject_name,
                qp.title AS test_name,
                ir.marks,
                ir.max_marks
            FROM ia_results ir
            JOIN question_papers qp ON ir.qp_id = qp.id
            LEFT JOIN subjects s ON qp.subject_id = s.id
            WHERE ir.student_id = :sid
            ORDER BY ir.id DESC
            LIMIT 50
        ");
        $results_stmt->execute([':sid' => $student_id]);
        $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);

        // ---------------------------------------------------------------------
        // 5. FETCH ATTENDANCE SUMMARY
        // ---------------------------------------------------------------------
        try {
            $att_sql = "
                SELECT 
                    COALESCE(sub.name, 'General') AS subject_name,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                    COUNT(*) AS total_count
                FROM attendance a
                LEFT JOIN subjects sub ON a.subject_id = sub.id
                WHERE a.student_id = :sid
                GROUP BY sub.name
                ORDER BY sub.name
            ";
            $att_stmt = $pdo->prepare($att_sql);
            $att_stmt->execute([':sid' => $student_id]);
            $att_rows = $att_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($att_rows as $r) {
                $present = (int)$r['present_count'];
                $total = (int)$r['total_count'];
                $percent = $total > 0 ? round(($present / $total) * 100, 2) : 0;
                
                $attendance_summary[] = [
                    'subject' => $r['subject_name'],
                    'present' => $present,
                    'total'   => $total,
                    'percent' => $percent
                ];
            }
        } catch (PDOException $attEx) {
            // Ignore attendance errors if table is empty
        }

    } else {
        $page_error = "Student record not found. Please contact admin.";
    }

} catch (PDOException $e) {
    $page_error = "Database Error: " . htmlspecialchars($e->getMessage());
}

// Prepare data for chart
$chart_labels = [];
$chart_data = [];
foreach ($attendance_summary as $row) {
    $chart_labels[] = $row['subject'];
    $chart_data[] = $row['percent'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        .navbar { display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto 20px auto; padding: 10px 20px; background: rgba(141, 153, 174, 0.08); border-radius: 10px; }
        .navbar h1 { margin: 0; font-size: 1.3em; }
        .logout-btn { display: inline-block; padding: 8px 12px; background-color: var(--fire-engine-red); color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .layout { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 1fr 420px; gap: 20px; align-items: start; }
        @media (max-width: 980px) { .layout { grid-template-columns: 1fr; } }

        /* Cards */
        .card { background: rgba(141,153,174,0.06); padding: 18px; border-radius: 10px; border: 1px solid rgba(141,153,174,0.12); margin-bottom: 20px; }
        .tests { margin-bottom: 18px; }
        
        /* Lists */
        .test-list { list-style: none; padding: 0; margin: 0 0 12px 0; }
        .test-list li { background: rgba(43,45,66,0.5); border: 1px solid var(--cool-gray); border-radius: 8px; margin-bottom: 10px; }
        .test-list a { display: block; padding: 14px 18px; text-decoration: none; color: var(--antiflash-white); font-weight: bold; }
        .test-list a:hover { background: rgba(141,153,174,0.12); }

        /* Table */
        .results-table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; color: var(--space-cadet); border-radius: 8px; overflow: hidden; }
        .results-table th, .results-table td { padding: 12px 14px; border-bottom: 1px solid #eee; text-align: left; }
        .results-table th { background: var(--space-cadet); color: var(--antiflash-white); }
        .no-data { color: var(--cool-gray); padding: 12px; background: rgba(255,255,255,0.04); border-radius: 6px; font-style: italic; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>

    <div class="navbar">
        <h1>Welcome, <?= htmlspecialchars($student_name) ?>!</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <?php if (!empty($page_error)): ?>
        <div style="max-width:1200px;margin:0 auto;padding:0 20px 20px;">
            <div class="card message error"><?= htmlspecialchars($page_error) ?></div>
        </div>
    <?php endif; ?>

    <div class="layout">
        <!-- LEFT COLUMN -->
        <div>
            <div class="card tests">
                <h2 style="margin-top:0;">Available Tests</h2>
                <?php if (empty($tests)): ?>
                    <div class="no-data">You have no new tests assigned at this time.</div>
                <?php else: ?>
                    <ul class="test-list">
                        <?php foreach ($tests as $test): ?>
                            <li><a href="take-test.php?id=<?= htmlspecialchars($test['id']) ?>">📝 Take Test: <?= htmlspecialchars($test['title']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 style="margin-top:0;">My Recent Results</h2>
                <?php if (empty($results)): ?>
                    <div class="no-data">You have not completed any tests yet.</div>
                <?php else: ?>
                    <table class="results-table">
                        <thead>
                            <tr><th>Subject</th><th>Test Name</th><th>Marks</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <tr>
                                    <td><?= htmlspecialchars($result['subject_name']) ?></td>
                                    <td><?= htmlspecialchars($result['test_name']) ?></td>
                                    <td><?= htmlspecialchars($result['marks']) ?> / <?= htmlspecialchars($result['max_marks']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN -->
        <aside>
            <div class="card">
                <h2 style="margin:0 0 10px 0;">Attendance</h2>
                <?php if (empty($attendance_summary)): ?>
                    <div class="no-data">No attendance data available.</div>
                <?php else: ?>
                    <canvas id="attendanceChart" width="400" height="300"></canvas>
                    <div style="margin-top:12px;">
                        <table class="results-table">
                            <thead><tr><th>Subject</th><th>%</th></tr></thead>
                            <tbody>
                                <?php foreach ($attendance_summary as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['subject']) ?></td>
                                        <td><?= htmlspecialchars($row['percent']) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    (function() {
        const labels = <?= json_encode($chart_labels) ?>;
        const data = <?= json_encode($chart_data) ?>;

        if (labels && labels.length) {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Attendance (%)',
                        data: data,
                        backgroundColor: '#ef233c',
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        }
    })();
    </script>

</body>
</html>
