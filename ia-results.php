<?php
// view-result.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once('db.php'); // expects $pdo (PDO) connected to your PostgreSQL DB

// Config
$PASS_PERCENT = 40; // pass threshold (in percent)

// Require student login
if (!isset($_SESSION['student_id'])) {
    header('Location: student-login.php');
    exit;
}
$student_id = (int) $_SESSION['student_id'];

// Get test id from GET
$test_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$test_id) {
    die("Invalid or missing test id. Open the page as: view-result.php?id=1");
}

try {
    // Fetch question paper
    $qStmt = $pdo->prepare("SELECT id, title, content FROM question_papers WHERE id = :id LIMIT 1");
    $qStmt->execute([':id' => $test_id]);
    $test = $qStmt->fetch(PDO::FETCH_ASSOC);
    if (!$test) {
        throw new Exception("Test not found (id={$test_id}).");
    }

    $raw_content = $test['content'] ?? '';

    // Try to decode questions as JSON. If not JSON, treat as plain-text single-item test.
    $questions = null;
    $decoded = json_decode($raw_content, true);
    if (is_array($decoded)) {
        // Expect an array of question objects
        $questions = $decoded;
    } else {
        // Plain text fallback: create a single-question array (essay type)
        $questions = [
            [
                'question' => (string)$raw_content,
                'options'  => null,
                'correct'  => null,
                'marks'    => null
            ]
        ];
    }

    // Fetch student's submission (if any)
    $resStmt = $pdo->prepare("SELECT id, marks, content, created_at, updated_at FROM ia_results WHERE student_id = :sid AND qp_id = :qid LIMIT 1");
    $resStmt->execute([':sid' => $student_id, ':qid' => $test_id]);
    $submission = $resStmt->fetch(PDO::FETCH_ASSOC);
    $has_submission = (bool)$submission;

    // Decode student's saved answers/content if JSON; otherwise keep as plain text
    $student_answers = null;
    if ($has_submission) {
        $saved = $submission['content'] ?? '';
        $decodedStudent = json_decode($saved, true);
        if ($decodedStudent !== null) {
            $student_answers = $decodedStudent;
        } else {
            // keep raw text (essay-style)
            $student_answers = $saved;
        }
    }

    // Prepare per-question grading info
    $max_marks = 0;
    $recomputed_marks = 0;
    $per_question = [];

    foreach ($questions as $idx => $q) {
        $q_text = $q['question'] ?? '';
        $q_options = $q['options'] ?? null; // array e.g. ['a'=>'..','b'=>'..']
        $q_correct = array_key_exists('correct', $q) ? $q['correct'] : null;
        $q_marks = isset($q['marks']) ? (int)$q['marks'] : 1; // default 1 if not provided

        $max_marks += $q_marks;

        // Determine student's answer for this index
        $student_choice = null;
        if (is_array($student_answers)) {
            // try numeric index and string index
            if (array_key_exists($idx, $student_answers)) $student_choice = $student_answers[$idx];
            elseif (array_key_exists((string)$idx, $student_answers)) $student_choice = $student_answers[(string)$idx];
            else {
                // sometimes answers might be keyed by question id; try question id if present
                if (isset($q['id']) && array_key_exists((string)$q['id'], $student_answers)) {
                    $student_choice = $student_answers[(string)$q['id']];
                }
            }
        } elseif (is_string($student_answers) || is_numeric($student_answers)) {
            // essay-style: use whole string for first question
            if ($idx === 0) $student_choice = $student_answers;
        }

        $is_correct = null;
        if ($q_correct !== null && $student_choice !== null) {
            // strict string comparison
            if ((string)$student_choice === (string)$q_correct) {
                $is_correct = true;
                $recomputed_marks += $q_marks;
            } else {
                $is_correct = false;
            }
        }

        $per_question[$idx] = [
            'question' => $q_text,
            'options'  => $q_options,
            'correct'  => $q_correct,
            'student'  => $student_choice,
            'marks'    => $q_marks,
            'is_correct' => $is_correct
        ];
    }

    // Determine marks to display: prefer stored marks in DB, else recomputed
    $stored_marks = $submission['marks'] ?? null;
    $display_marks = (is_numeric($stored_marks) ? (int)$stored_marks : $recomputed_marks);
    $percentage = ($max_marks > 0) ? round(($display_marks / $max_marks) * 100, 2) : 0;
    $passed = $percentage >= $PASS_PERCENT;

} catch (Exception $e) {
    // Friendly error page
    $err = htmlspecialchars($e->getMessage());
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Error</title></head>
<body style="font-family:Arial,sans-serif;padding:20px;">
<h2>Error</h2>
<p>{$err}</p>
<p><a href="student-dashboard.php">&laquo; Back to Dashboard</a></p>
</body></html>
HTML;
    exit;
}

// Render the result page
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Result — <?= htmlspecialchars($test['title']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
    :root { --bg:#2b2d42; --muted:#8d99ae; --accent:#d90429; --light:#fff; }
    body{font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--light);margin:0;padding:20px;}
    .wrap{max-width:900px;margin:18px auto;}
    .card{background:rgba(141,153,174,0.06);padding:18px;border-radius:10px;border:1px solid rgba(141,153,174,0.12);}
    h1{margin:0 0 8px}
    .meta{color:var(--muted);margin-bottom:12px}
    .score-box{background:#fff;color:#111;padding:12px;border-radius:8px;margin-bottom:16px}
    .q{background:#fff;color:#111;padding:12px;border-radius:6px;margin-bottom:10px}
    .q .q-head{font-weight:700;margin-bottom:8px}
    .option{margin-left:12px}
    .correct{color:green;font-weight:700}
    .wrong{color:#b52d3b;font-weight:700}
    .note{color:var(--muted);font-size:0.95rem}
    a.back{display:inline-block;margin-top:10px;color:var(--muted);text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Result: <?= htmlspecialchars($test['title']) ?></h1>
        <div class="meta">Student ID: <?= htmlspecialchars($student_id) ?> &nbsp; | &nbsp; Test ID: <?= htmlspecialchars($test_id) ?></div>

        <?php if (!$has_submission): ?>
            <div class="score-box">
                <strong>No submission found.</strong>
                <p class="note">You haven't submitted this test yet. <a href="take-test.php?id=<?= htmlspecialchars($test_id) ?>">Take the test now</a>.</p>
            </div>
        <?php else: ?>
            <div class="score-box">
                <div><strong>Marks:</strong> <?= htmlspecialchars($display_marks) ?> / <?= htmlspecialchars($max_marks) ?></div>
                <div><strong>Percentage:</strong> <?= htmlspecialchars($percentage) ?>%</div>
                <div><strong>Result:</strong> 
                    <?php if ($passed): ?>
                        <span style="color:green;font-weight:700">Passed</span>
                    <?php else: ?>
                        <span style="color:#b52d3b;font-weight:700">Failed</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($submission['updated_at'])): ?>
                    <div class="meta">Submitted: <?= htmlspecialchars($submission['updated_at']) ?></div>
                <?php elseif (!empty($submission['created_at'])): ?>
                    <div class="meta">Submitted: <?= htmlspecialchars($submission['created_at']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Per-question breakdown -->
            <?php foreach ($per_question as $i => $pq): ?>
                <div class="q">
                    <div class="q-head">Q<?= $i + 1 ?>. <?= htmlspecialchars($pq['question']) ?> <span style="font-weight:600"> (<?= $pq['marks'] ?> mark<?= $pq['marks'] > 1 ? 's' : '' ?>)</span></div>

                    <?php if (is_array($pq['options']) && count($pq['options'])): ?>
                        <?php foreach ($pq['options'] as $optKey => $optText): ?>
                            <?php
                                $isStudent = ((string)$optKey === (string)$pq['student']);
                                $isCorrectOpt = ($pq['correct'] !== null && (string)$optKey === (string)$pq['correct']);
                                $cls = $isCorrectOpt ? 'correct' : ($isStudent && !$isCorrectOpt ? 'wrong' : '');
                            ?>
                            <div class="option">
                                <span class="<?= $cls ?>"><?= htmlspecialchars($optKey) ?>. <?= htmlspecialchars($optText) ?></span>
                                <?php if ($isStudent): ?><strong> &nbsp; ← Your answer</strong><?php endif; ?>
                                <?php if ($isCorrectOpt): ?><strong> &nbsp; ← Correct answer</strong><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- No options (subjective) -->
                        <div><strong>Your answer:</strong></div>
                        <div style="background:#f8f8f8;color:#111;padding:10px;border-radius:6px;margin-top:8px;"><?= nl2br(htmlspecialchars((string)$pq['student'])) ?></div>
                        <?php if ($pq['correct'] !== null): ?>
                            <div style="margin-top:8px"><strong>Model answer (if available):</strong> <?= nl2br(htmlspecialchars((string)$pq['correct'])) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div style="margin-top:8px">
                        <?php if ($pq['is_correct'] === true): ?>
                            <span class="correct">Correct</span> — awarded <?= $pq['marks'] ?> mark<?= $pq['marks'] > 1 ? 's' : '' ?>.
                        <?php elseif ($pq['is_correct'] === false): ?>
                            <span class="wrong">Incorrect</span> — awarded 0 marks.
                        <?php else: ?>
                            <span class="note">Not auto-graded (subjective)</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <a class="back" href="student-dashboard.php">&laquo; Back to Dashboard</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
