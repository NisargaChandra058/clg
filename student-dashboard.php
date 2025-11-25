<?php
// student-dashboard.php (rewritten — resilient test allocation lookup + safer queries)

error_reporting(E_ALL);
ini_set('display_errors', 0); // keep raw errors hidden from users
session_start();

require_once('db.php'); // must initialize $pdo (PDO instance)

// Make sure PDO exists and throws exceptions
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // fatal: DB not available
    http_response_code(500);
    echo "Server configuration error. Please contact the administrator.";
    error_log("[student-dashboard] Missing or invalid \$pdo in db.php");
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// redirect if not logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: student-login.php');
    exit;
}

$student_id = (int) $_SESSION['student_id'];

$tests = [];
$results = [];
$attendance_summary = [];
$student_name = 'Student';
$page_error = '';

try {
    // 1) Student record
    $stmt = $pdo->prepare("SELECT id, student_name, email, class_id FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        $page_error = "Student record not found.";
    } else {
        $student_name = $student['student_name'] ?? $student_name;
        $class_id = isset($student['class_id']) ? $student['class_id'] : null;

        //
        // 2) Fetch tests assigned to the student (robust/fallback approach)
        //
        // Common schema variations:
        //  - test_allocation with columns: class_id / class / classid and qp_id / question_paper_id / qpId
        //  - test_allocation may assign to students directly via student_id
        //  - question_papers may have flags like assigned_class_id or is_published
        //
        // We will try multiple queries in order of likelihood; stop when we find any tests.
        //

        $test_attempts = [];

        // primary: test_allocation linking by class_id -> qp.id
        if ($class_id) {
            $test_attempts[] = [
                'sql' => "
                    SELECT DISTINCT qp.id, qp.title, qp.subject_id
                    FROM test_allocation ta
                    JOIN question_papers qp ON (ta.qp_id = qp.id OR ta.question_paper_id = qp.id OR ta.qpId = qp.id)
                    WHERE (ta.class_id = :class_id OR ta.class = :class_id OR ta.classid = :class_id)
                    ORDER BY qp.id DESC
                ",
                'params' => [':class_id' => $class_id],
                'reason' => 'test_allocation -> class_id'
            ];
        }

        // fallback: test_allocation assigned directly to the student
        $test_attempts[] = [
            'sql' => "
                SELECT DISTINCT qp.id, qp.title, qp.subject_id
                FROM test_allocation ta
                JOIN question_papers qp ON (ta.qp_id = qp.id OR ta.question_paper_id = qp.id OR ta.qpId = qp.id)
                WHERE (ta.student_id = :student_id OR ta.student = :student_id)
                ORDER BY qp.id DESC
            ",
            'params' => [':student_id' => $student_id],
            'reason' => 'test_allocation -> student_id'
        ];

        // fallback: question_papers has a class/assigned_class column
        if ($class_id) {
            $test_attempts[] = [
                'sql' => "
                    SELECT DISTINCT qp.id, qp.title, qp.subject_id
                    FROM question_papers qp
                    WHERE (qp.class_id = :class_id OR qp.assigned_class = :class_id OR qp.assigned_to_class = :class_id)
                    ORDER BY qp.id DESC
                ",
                'params' => [':class_id' => $class_id],
                'reason' => 'question_papers -> class column'
            ];
        }

        // fallback: published / visible tests (generic)
        $test_attempts[] = [
            'sql' => "
                SELECT DISTINCT qp.id, qp.title, qp.subject_id
                FROM question_papers qp
                WHERE (qp.is_published = 1 OR qp.published = '1' OR qp.visible = 1)
                ORDER BY qp.id DESC
                LIMIT 200
            ",
            'params' => [],
            'reason' => 'question_papers -> published visible (generic)'
        ];

        // Execute attempts until we find results
        $found_tests = false;
        foreach ($test_attempts as $attempt) {
            try {
                $tstmt = $pdo->prepare($attempt['sql']);
                $tstmt->execute($attempt['params']);
                $rows = $tstmt->fetchAll();
                if (!empty($rows)) {
                    $tests = $rows;
                    $found_tests = true;
                    // log which strategy succeeded for ops debugging (server log only)
                    error_log("[student-dashboard] Tests found using strategy: " . $attempt['reason'] . " (student_id={$student_id})");
                    break;
                } else {
                    // no rows — continue to next attempt
                    error_log("[student-dashboard] No tests with strategy: " . $attempt['reason'] . " (student_id={$student_id})");
                }
            } catch (PDOException $ex) {
                // Don't show SQL errors to user; log for admin
                error_log("[student-dashboard][test-fetch] strategy: " . $attempt['reason'] . " — " . $ex->getMessage());
                // continue trying other strategies
            }
        }

        // If still empty, set tests to empty and optionally set page hint (not an error)
        if (!$found_tests) {
            $tests = [];
            // We intentionally do not set $page_error because this is not a fatal error;
            // tests might legitimately not be assigned.
        }

        //
        // 3) Completed results for this student (safe, limited)
        //
        $results_stmt = $pdo->prepare("
            SELECT
                COALESCE(s.name,'—') AS subject_name,
                qp.title AS test_name,
                ir.marks
            FROM ia_results ir
            JOIN question_papers qp ON ir.qp_id = qp.id
            LEFT JOIN subjects s ON qp.subject_id = s.id
            WHERE ir.student_id = :student_id
            ORDER BY ir.id DESC
            LIMIT 50
        ");
        $results_stmt->execute([':student_id' => $student_id]);
        $results = $results_stmt->fetchAll();

        //
        // 4) Attendance summary (tolerant)
        //
        try {
            $att_sql = "
                SELECT
                    COALESCE(sub.name, 'Unknown Subject') AS subject_name,
                    SUM(CASE
                        WHEN a.status IN (1, '1', 'present', 'P', 'p', 'Present') THEN 1
                        ELSE 0
                    END) AS present_count,
                    COUNT(*) AS total_count
                FROM attendance a
                LEFT JOIN subjects sub ON a.subject_id = sub.id
                WHERE a.student_id = :student_id
                GROUP BY sub.name
                ORDER BY sub.name
            ";
            $att_stmt = $pdo->prepare($att_sql);
            $att_stmt->execute([':student_id' => $student_id]);
            $att_rows = $att_stmt->fetchAll();

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

            // If empty, try alternative attendance table(s)
            if (empty($attendance_summary)) {
                $alt_sql = "
                    SELECT COALESCE(subject, 'Unknown Subject') AS subject_name,
                           SUM(CASE WHEN (is_present = 1 OR is_present = '1' OR is_present = 'true' OR is_present = 't') THEN 1 ELSE 0 END) AS present_count,
                           COUNT(*) AS total_count
                    FROM attendance_records
                    WHERE student_id = :student_id
                    GROUP BY subject
                    ORDER BY subject
                ";
                $alt_stmt = $pdo->prepare($alt_sql);
                $alt_stmt->execute([':student_id' => $student_id]);
                $alt_rows = $alt_stmt->fetchAll();
                foreach ($alt_rows as $r) {
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
            }
        } catch (PDOException $attEx) {
            // non-fatal: log and continue
            error_log("[student-dashboard][attendance] " . $attEx->getMessage());
        }
    }

} catch (PDOException $e) {
    // Friendly message for users, but log full detail on server
    $page_error = "Error: Could not retrieve data at this time. Please try again later.";
    error_log("[student-dashboard] PDOException: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}

// Prepare chart data
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
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        .navbar{display:flex;justify-content:space-between;align-items:center;max-width:1200px;margin:0 auto 20px;padding:10px 20px;background:rgba(141,153,174,0.08);border-radius:10px;}
        .logout-btn{padding:8px 12px;background:var(--fire-engine-red);color:#fff;text-decoration:none;border-radius:5px;font-weight:bold;}
        .layout{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1fr 420px;gap:20px;align-items:start;}
        @media(max-width:980px){.layout{grid-template-columns:1fr;}}
        .card{background:rgba(141,153,174,0.06);padding:18px;border-radius:10px;border:1px solid rgba(141,153,174,0.12);}
        .test-list{list-style:none;padding:0;margin:0;}
        .test-list li{background:rgba(43,45,66,0.5);border-radius:8px;margin-bottom:10px;}
        .test-list a{display:block;padding:14px 18px;color:var(--antiflash-white);text-decoration:none;font-weight:bold;}
        .results-table{width:100%;border-collapse:collapse;margin-top:10px;background:#fff;color:var(--space-cadet);border-radius:8px;overflow:hidden;}
        .results-table th,.results-table td{padding:12px 14px;border-bottom:1px solid #eee;text-align:left;}
        .results-table th{background:var(--space-cadet);color:var(--antiflash-white);}
        .no-data{color:var(--cool-gray);padding:12px;background:rgba(255,255,255,0.04);border-radius:6px;}
        .muted{color:var(--cool-gray);font-size:0.95rem;}
        .message.error{background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:10px;border-radius:5px;}
    </style>
</head>
<body>
    <div class="navbar">
        <h1>Welcome, <?= htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') ?>!</h1>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <?php if (!empty($page_error)): ?>
        <div style="max-width:1200px;margin:0 auto;padding:0 20px 20px;">
            <div class="card message error"><?= htmlspecialchars($page_error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <div class="layout" role="main" aria-live="polite">
        <div>
            <div class="card tests">
                <h2 style="margin-top:0;">Available Tests</h2>
                <?php if (empty($tests)): ?>
                    <div class="no-data">You have no new tests assigned at this time.</div>
                    <div class="muted" style="margin-top:8px;">If you expect tests but see none, the assignment may be recorded differently in the database. The server admin can check <code>test_allocation</code> or <code>question_papers</code> records.</div>
                <?php else: ?>
                    <ul class="test-list" aria-label="Available tests">
                        <?php foreach ($tests as $test): ?>
                            <?php
                                $tid = htmlspecialchars($test['id'], ENT_QUOTES, 'UTF-8');
                                $ttitle = htmlspecialchars($test['title'] ?? 'Untitled Test', ENT_QUOTES, 'UTF-8');
                            ?>
                            <li><a href="take-test.php?id=<?= $tid ?>">Take Test: <?= $ttitle ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 style="margin-top:0;">My Recent Results</h2>
                <?php if (empty($results)): ?>
                    <div class="no-data">You have not completed any tests yet.</div>
                <?php else: ?>
                    <table class="results-table" role="table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Test Name</th>
                                <th>Marks Obtained</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <tr>
                                    <td><?= htmlspecialchars($result['subject_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($result['test_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($result['marks'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <aside>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <div>
                        <h2 style="margin:0;">Attendance</h2>
                        <div class="muted">Subject-wise attendance percentage</div>
                    </div>
                </div>

                <?php if (empty($attendance_summary)): ?>
                    <div class="no-data">Attendance data not available for your account.</div>
                    <div class="muted" style="margin-top:8px;">If you believe this is incorrect, ensure attendance is recorded in the <code>attendance</code> or <code>attendance_records</code> table (student_id, subject_id, status/is_present).</div>
                <?php else: ?>
                    <canvas id="attendanceChart" width="400" height="300" aria-label="Attendance chart" role="img"></canvas>

                    <div style="margin-top:12px;">
                        <table style="width:100%;border-collapse:collapse;background:#fff;color:var(--space-cadet);border-radius:6px;overflow:hidden;">
                            <thead>
                                <tr>
                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:left">Subject</th>
                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:right">Present</th>
                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:right">Total</th>
                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:right">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_summary as $row): ?>
                                    <tr>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?= htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;"><?= htmlspecialchars($row['present'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;"><?= htmlspecialchars($row['total'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;"><?= htmlspecialchars($row['percent'], ENT_QUOTES, 'UTF-8') ?>%</td>
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
        const labels = <?= json_encode($chart_labels, JSON_UNESCAPED_UNICODE) ?>;
        const data = <?= json_encode($chart_data, JSON_UNESCAPED_UNICODE) ?>;
        if (labels && labels.length && data && data.length) {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Attendance (%)',
                        data: data,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { callback: function(value){ return value + "%"; } },
                            title: { display: true, text: 'Percentage' }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) label += ': ';
                                    if (context.parsed.y !== null) label += context.parsed.y + '%';
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
    })();
    </script>
</body>
</html>
