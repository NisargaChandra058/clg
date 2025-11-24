<?php

// student-dashboard.php

error_reporting(E_ALL);

ini_set('display_errors', 1);



// --- FIX: Correct session path if required ---

// session_save_path('/var/www/sessions'); // uncomment if you use custom session path

session_start();



require_once('db.php'); // Use our PDO connection ($pdo)



// Check if student is logged in

if (!isset($_SESSION['student_id'])) {

    header('Location: student-login.php'); // Redirect to login if not logged in

    exit;

}



$student_id = (int) $_SESSION['student_id'];

$tests = [];

$results = [];

$attendance_summary = []; // will hold ['subject' => ..., 'present' => n, 'total' => m, 'percent' => x]

$student_name = 'Student';

$page_error = '';



try {

    // Fetch student's name and class_id

    $stmt = $pdo->prepare("SELECT student_name, email, class_id FROM students WHERE id = :id LIMIT 1");

    $stmt->execute([':id' => $student_id]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);



    if ($student) {

        $student_name = $student['student_name'] ?? $student_name;

        $class_id = $student['class_id'] ?? null;



        if ($class_id) {

            // 1. Fetch all tests allocated to this student's class

            $test_stmt = $pdo->prepare("

                SELECT qp.id, qp.title 

                FROM test_allocation ta

                JOIN question_papers qp ON ta.qp_id = qp.id

                WHERE ta.class_id = :class_id

                ORDER BY qp.id DESC

            ");

            $test_stmt->execute([':class_id' => $class_id]);

            $tests = $test_stmt->fetchAll(PDO::FETCH_ASSOC);

        }



        // 2. Fetch all COMPLETED results for this student

        $results_stmt = $pdo->prepare("

            SELECT 

                s.name AS subject_name,

                qp.title AS test_name,

                ir.marks,

                ir.created_at

            FROM 

                ia_results ir

            JOIN 

                question_papers qp ON ir.qp_id = qp.id

            LEFT JOIN 

                subjects s ON qp.subject_id = s.id

            WHERE 

                ir.student_id = :student_id

            ORDER BY

                ir.created_at DESC

            LIMIT 50

        ");

        $results_stmt->execute([':student_id' => $student_id]);

        $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);



        // 3. Attendance summary: tolerant query

        // Try a reasonable attendance schema: attendance(student_id, subject_id, status, date)

        // We'll compute present / total per subject and join subject name.

        // This block is wrapped in try/catch in case table/columns differ.

        try {

            $att_sql = "

                SELECT 

                    COALESCE(sub.name, 'Unknown Subject') AS subject_name,

                    SUM(CASE WHEN a.status IN (1, '1', 'present', 'P', 'p') THEN 1 ELSE 0 END) AS present_count,

                    COUNT(*) AS total_count

                FROM attendance a

                LEFT JOIN subjects sub ON a.subject_id = sub.id

                WHERE a.student_id = :student_id

                GROUP BY sub.name

                ORDER BY sub.name

            ";

            $att_stmt = $pdo->prepare($att_sql);

            $att_stmt->execute([':student_id' => $student_id]);

            $att_rows = $att_stmt->fetchAll(PDO::FETCH_ASSOC);



            // Build summary with percent

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



            // If no rows found, try alternative schema: attendance_records(student_id, subject, is_present)

            if (empty($attendance_summary)) {

                $alt_sql = "

                    SELECT subject as subject_name, SUM(CASE WHEN is_present THEN 1 ELSE 0 END) as present_count, COUNT(*) as total_count

                    FROM attendance_records

                    WHERE student_id = :student_id

                    GROUP BY subject

                    ORDER BY subject

                ";

                $alt_stmt = $pdo->prepare($alt_sql);

                $alt_stmt->execute([':student_id' => $student_id]);

                $alt_rows = $alt_stmt->fetchAll(PDO::FETCH_ASSOC);

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

            // If attendance table doesn't exist or columns differ we'll ignore and show friendly message later

            // Log $attEx if you have a logger; for now collect a non-fatal message

            // $page_error .= ' Attendance info unavailable. ';

        }

    } else {

        $page_error = "Student record not found.";

    }



} catch (PDOException $e) {

    $page_error = "Error: Could not retrieve data at this time. Please try again later.";

    // in development you might append the exception message:

    // $page_error .= ' ' . htmlspecialchars($e->getMessage());

}



// Prepare data for chart JS: labels and data arrays

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



        /* Left column (results & tests) */

        .card { background: rgba(141,153,174,0.06); padding: 18px; border-radius: 10px; border: 1px solid rgba(141,153,174,0.12); }

        .tests { margin-bottom: 18px; }

        .test-list { list-style: none; padding: 0; margin: 0 0 12px 0; }

        .test-list li { background: rgba(43,45,66,0.5); border: 1px solid var(--cool-gray); border-radius: 8px; margin-bottom: 10px; }

        .test-list a { display: block; padding: 14px 18px; text-decoration: none; color: var(--antiflash-white); font-weight: bold; }

        .test-list a:hover { background: rgba(141,153,174,0.12); }



        .results-table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; color: var(--space-cadet); border-radius: 8px; overflow: hidden; }

        .results-table th, .results-table td { padding: 12px 14px; border-bottom: 1px solid #eee; text-align: left; }

        .results-table th { background: var(--space-cadet); color: var(--antiflash-white); }

        .results-table tr:last-child td { border-bottom: none; }

        .no-data { color: var(--cool-gray); padding: 12px; background: rgba(255,255,255,0.04); border-radius: 6px; }



        /* Right column (attendance) */

        .attendance-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }

        .attendance-note { color: var(--cool-gray); font-size: 0.95rem; }



        /* small helpers */

        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; border-radius: 5px; }

        .muted { color: var(--cool-gray); font-size: 0.95rem; }

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



    <div class="layout" role="main" aria-live="polite">

        <!-- LEFT: Tests & Results -->

        <div>

            <div class="card tests">

                <h2 style="margin-top:0;">Available Tests</h2>

                <?php if (empty($tests)): ?>

                    <div class="no-data">You have no new tests assigned at this time.</div>

                <?php else: ?>

                    <ul class="test-list" aria-label="Available tests">

                        <?php foreach ($tests as $test): ?>

                            <li><a href="take-test.php?id=<?= htmlspecialchars($test['id']) ?>">Take Test: <?= htmlspecialchars($test['title']) ?></a></li>

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

                                    <td><?= htmlspecialchars($result['subject_name'] ?? '—') ?></td>

                                    <td><?= htmlspecialchars($result['test_name'] ?? '—') ?></td>

                                    <td><?= htmlspecialchars($result['marks'] ?? '—') ?></td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php endif; ?>

            </div>

        </div>



        <!-- RIGHT: Attendance -->

        <aside>

            <div class="card">

                <div class="attendance-header">

                    <div>

                        <h2 style="margin:0;">Attendance</h2>

                        <div class="attendance-note">Subject-wise attendance percentage</div>

                    </div>

                </div>



                <?php if (empty($attendance_summary)): ?>

                    <div class="no-data">Attendance data not available for your account.</div>

                    <div class="muted" style="margin-top:8px;">If you believe this is incorrect, ensure attendance is recorded in the <code>attendance</code> table (student_id, subject_id, status).</div>

                <?php else: ?>

                    <canvas id="attendanceChart" width="400" height="300" aria-label="Attendance chart" role="img"></canvas>



                    <div style="margin-top:12px;">

                        <table style="width:100%;border-collapse:collapse;background:#fff;color:var(--space-cadet);border-radius:6px;overflow:hidden;">

                            <thead>

                                <tr><th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:left">Subject</th>

                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:right">Present</th>

                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:right">Total</th>

                                    <th style="padding:8px 10px;background:var(--space-cadet);color:var(--antiflash-white);text-align:right">%</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($attendance_summary as $row): ?>

                                    <tr>

                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?= htmlspecialchars($row['subject']) ?></td>

                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;"><?= htmlspecialchars($row['present']) ?></td>

                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;"><?= htmlspecialchars($row['total']) ?></td>

                                        <td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right;"><?= htmlspecialchars($row['percent']) ?>%</td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </aside>

    </div>



    <!-- Chart.js CDN -->

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>

        (function() {

            const labels = <?= json_encode($chart_labels) ?>;

            const data = <?= json_encode($chart_data) ?>;



            if (labels && labels.length && data && data.length) {

                const ctx = document.getElementById('attendanceChart').getContext('2d');

                new Chart(ctx, {

                    type: 'bar',

                    data: {

                        labels: labels,

                        datasets: [{

                            label: 'Attendance (%)',

                            data: data,

                            borderWidth: 1,

                            // Do not set explicit colors — Chart.js will apply defaults.

                        }]

                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false,

                        scales: {

                            y: {

                                beginAtZero: true,

                                max: 100,

                                ticks: {

                                    callback: function(value) { return value + "%"; }

                                },

                                title: {

                                    display: true,

                                    text: 'Percentage'

                                }

                            }

                        },

                        plugins: {

                            legend: { display: false },

                            tooltip: {

                                callbacks: {

                                    label: function(context) {

                                        let label = context.dataset.label || '';

                                        if (label) label += ': ';

                                        if (context.parsed.y !== null) {

                                            label += context.parsed.y + '%';

                                        }

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
