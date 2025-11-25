<?php
// student-dashboard.php (auto-detecting test_allocation / question_papers columns)
// Drop-in replacement: make sure db.php sets $pdo (PDO instance)

error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

require_once('db.php');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Server configuration error. Please contact the administrator.";
    error_log("[student-dashboard] Missing or invalid \$pdo in db.php");
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

/**
 * Utility: get column names for a table in the current database
 */
function get_table_columns(PDO $pdo, string $table) : array {
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':table' => $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

/**
 * Utility: pick first existing column name from candidates array
 */
function pick_col(array $cols, array $candidates) {
    foreach ($candidates as $cand) {
        if (in_array($cand, $cols, true)) return $cand;
    }
    return null;
}

try {
    // 1) Student record
    $stmt = $pdo->prepare("SELECT id, student_name, email, class_id FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        $page_error = "Student record not found.";
    } else {
        $student_name = $student['student_name'] ?? $student_name;
        $class_id = $student['class_id'] ?? null;

        // 2) Auto-detect columns for test allocation lookup
        $ta_cols = get_table_columns($pdo, 'test_allocation');
        $qp_cols = get_table_columns($pdo, 'question_papers');

        // candidate names (common variations)
        $qp_id_candidates = ['qp_id', 'question_paper_id', 'qpId', 'qpid', 'paper_id', 'questionpaper_id'];
        $ta_class_candidates = ['class_id', 'class', 'classid', 'classId', 'class_name'];
        $ta_student_candidates = ['student_id', 'student', 'studentid', 'studentId'];
        $qp_class_candidates = ['class_id', 'assigned_class', 'assigned_to_class', 'class', 'classid'];
        $qp_publish_candidates = ['is_published', 'published', 'visible', 'is_visible'];

        // pick detected column names (if table exists)
        $ta_has_cols = !empty($ta_cols);
        $qp_has_cols = !empty($qp_cols);

        $ta_qp_col = $ta_has_cols ? pick_col($ta_cols, $qp_id_candidates) : null;
        $ta_class_col = $ta_has_cols ? pick_col($ta_cols, $ta_class_candidates) : null;
        $ta_student_col = $ta_has_cols ? pick_col($ta_cols, $ta_student_candidates) : null;

        $qp_class_col = $qp_has_cols ? pick_col($qp_cols, $qp_class_candidates) : null;
        $qp_publish_col = $qp_has_cols ? pick_col($qp_cols, $qp_publish_candidates) : null;

        // Build dynamic queries based on what we detected. We'll try several strategies.
        $strategies = [];

        // Strategy A: test_allocation by class -> question_papers
        if ($ta_qp_col && $ta_class_col && $class_id) {
            $strategies[] = [
                'name' => 'ta_by_class',
                'sql'  => "SELECT DISTINCT qp.id, qp.title
                           FROM test_allocation ta
                           JOIN question_papers qp ON ta.{$ta_qp_col} = qp.id
                           WHERE ta.{$ta_class_col} = :class_id
                           ORDER BY qp.id DESC",
                'params' => [':class_id' => $class_id]
            ];
        }

        // Strategy B: test_allocation by student -> question_papers
        if ($ta_qp_col && $ta_student_col) {
            $strategies[] = [
                'name' => 'ta_by_student',
                'sql'  => "SELECT DISTINCT qp.id, qp.title
                           FROM test_allocation ta
                           JOIN question_papers qp ON ta.{$ta_qp_col} = qp.id
                           WHERE ta.{$ta_student_col} = :student_id
                           ORDER BY qp.id DESC",
                'params' => [':student_id' => $student_id]
            ];
        }

        // Strategy C: question_papers assigned_class column
        if ($qp_class_col && $class_id) {
            $strategies[] = [
                'name' => 'qp_assigned_class',
                'sql'  => "SELECT id, title FROM question_papers
                           WHERE {$qp_class_col} = :class_id
                           ORDER BY id DESC",
                'params' => [':class_id' => $class_id]
            ];
        }

        // Strategy D: question_papers published/visible tests
        if ($qp_publish_col) {
            $strategies[] = [
                'name' => 'qp_published',
                'sql'  => "SELECT id, title FROM question_papers
                           WHERE {$qp_publish_col} IN (1, '1', 't', 'true')
                           ORDER BY id DESC
                           LIMIT 200",
                'params' => []
            ];
        }

        // Strategy E: If no schema info, try common fallback (original style)
        $strategies[] = [
            'name' => 'fallback_generic',
            'sql'  => "
                SELECT DISTINCT qp.id, qp.title
                FROM test_allocation ta
                JOIN question_papers qp ON ta.qp_id = qp.id
                WHERE ta.class_id = :class_id
                ORDER BY qp.id DESC
            ",
            'params' => [':class_id' => $class_id]
        ];

        // Execute strategies in order, stop on first non-empty result
        $found = false;
        foreach ($strategies as $s) {
            try {
                $tstmt = $pdo->prepare($s['sql']);
                $tstmt->execute($s['params']);
                $rows = $tstmt->fetchAll();
                if (!empty($rows)) {
                    $tests = $rows;
                    $found = true;
                    error_log("[student-dashboard] Tests found via strategy '{$s['name']}' for student_id={$student_id}");
                    break;
                } else {
                    error_log("[student-dashboard] Strategy '{$s['name']}' returned no rows (student_id={$student_id})");
                }
            } catch (PDOException $ex) {
                // Log and continue to next
                error_log("[student-dashboard] Strategy '{$s['name']}' error: " . $ex->getMessage());
            }
        }

        // If still not found, optionally attempt a broad scan: all question_papers with no filters (small limit)
        if (!$found) {
            try {
                $wide = $pdo->prepare("SELECT id, title FROM question_papers ORDER BY id DESC LIMIT 100");
                $wide->execute();
                $wideRows = $wide->fetchAll();
                if (!empty($wideRows)) {
                    // we found papers but none were allocated specifically to the user/class
                    // do NOT auto-show them as "assigned" — but provide a hint in logs.
                    error_log("[student-dashboard] Found question_papers rows but none matched allocation for student_id={$student_id} — count=" . count($wideRows));
                    // Keep $tests empty so UI shows "no assigned tests".
                }
            } catch (Exception $e) {
                error_log("[student-dashboard] Broad question_papers scan failed: " . $e->getMessage());
            }
        }

        // 3) Results (unchanged)
        $results_stmt = $pdo->prepare("
            SELECT COALESCE(s.name,'—') AS subject_name, qp.title AS test_name, ir.marks
            FROM ia_results ir
            JOIN question_papers qp ON ir.qp_id = qp.id
            LEFT JOIN subjects s ON qp.subject_id = s.id
            WHERE ir.student_id = :student_id
            ORDER BY ir.id DESC
            LIMIT 50
        ");
        $results_stmt->execute([':student_id' => $student_id]);
        $results = $results_stmt->fetchAll();

        // 4) Attendance (unchanged tolerant logic)
        try {
            $att_sql = "
                SELECT COALESCE(sub.name,'Unknown Subject') AS subject_name,
                       SUM(CASE WHEN a.status IN (1,'1','present','P','p','Present') THEN 1 ELSE 0 END) AS present_count,
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
            if (empty($attendance_summary)) {
                $alt_sql = "
                    SELECT COALESCE(subject,'Unknown Subject') AS subject_name,
                           SUM(CASE WHEN (is_present = 1 OR is_present = '1') THEN 1 ELSE 0 END) AS present_count,
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
            error_log("[student-dashboard][attendance] " . $attEx->getMessage());
        }
    }

} catch (PDOException $e) {
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
    <meta charset="utf-8">
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
                    <div class="muted" style="margin-top:8px;">If you expect tests, ask your administrator to check `test_allocation` or `question_papers` table column names. Server logs contain detection notes.</div>
                <?php else: ?>
                    <ul class="test-list" aria-label="Available tests">
                        <?php foreach ($tests as $test): 
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
