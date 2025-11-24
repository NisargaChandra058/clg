<?php

// student-dashboard.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once('db.php'); // PDO connection ($pdo)

// Ensure PDO throws exceptions
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Check login
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

    // Fetch student details
    $stmt = $pdo->prepare("SELECT student_name, email, class_id FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $student_name = $student['student_name'] ?? $student_name;
        $class_id = $student['class_id'] ?? null;

        // Fetch tests
        if ($class_id) {
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

        // Fetch results
        $results_stmt = $pdo->prepare("
            SELECT 
                s.name AS subject_name,
                qp.title AS test_name,
                ir.marks,
                ir.created_at
            FROM ia_results ir
            JOIN question_papers qp ON ir.qp_id = qp.id
            LEFT JOIN subjects s ON qp.subject_id = s.id
            WHERE ir.student_id = :student_id
            ORDER BY ir.created_at DESC
            LIMIT 50
        ");
        $results_stmt->execute([':student_id' => $student_id]);
        $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch attendance
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
            $page_error .= "<br><strong>DEBUG (Attendance Query Error):</strong> " . $attEx->getMessage();
        }

    } else {
        $page_error = "Student record not found.";
    }

} catch (PDOException $e) {

    // Base message
    $page_error = "Error: Could not retrieve data at this time. Please try again later.";

    // Debug info (IMPORTANT: remove after fixing)
    $page_error .= "<br><br><strong>DEV DEBUG:</strong> " . htmlspecialchars($e->getMessage());
    $page_error .= "<br><strong>File:</strong> " . htmlspecialchars($e->getFile());
    $page_error .= "<br><strong>Line:</strong> " . $e->getLine();
    $page_error .= "<br><strong>SQLSTATE:</strong> " . $e->getCode();

    // Log to server
    error_log("[student-dashboard] " . $e->getMessage());
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
<html>
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard (DEBUG MODE)</title>
</head>
<body style="font-family: Arial; padding:20px; background:#111; color:#fff;">

<h1>Welcome, <?= htmlspecialchars($student_name) ?></h1>

<?php if (!empty($page_error)): ?>
    <div style="background:#ffdddd; color:#900; padding:15px; border-radius:5px; margin-bottom:20px;">
        <?= $page_error ?>
    </div>
<?php endif; ?>

<h2>Available Tests</h2>
<?php if (empty($tests)): ?>
    <p>No tests available.</p>
<?php else: ?>
    <ul>
        <?php foreach ($tests as $t): ?>
            <li><?= htmlspecialchars($t['title']) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Results</h2>
<?php if (empty($results)): ?>
    <p>No results found.</p>
<?php else: ?>
    <ul>
        <?php foreach ($results as $r): ?>
            <li><?= htmlspecialchars($r['subject_name']) ?> — <?= htmlspecialchars($r['marks']) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Attendance</h2>
<?php if (empty($attendance_summary)): ?>
    <p>No attendance data.</p>
<?php else: ?>
    <ul>
        <?php foreach ($attendance_summary as $row): ?>
            <li><?= $row['subject'] ?> — <?= $row['percent'] ?>%</li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

</body>
</html>
