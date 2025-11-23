<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once('db.php'); // Make sure this connects to PostgreSQL via PDO

// --- Check if student is logged in ---
if (!isset($_SESSION['student_id'])) {
    header('Location: student-login.php');
    exit;
}

$student_id = $_SESSION['student_id'];

// --- Ensure test ID ---
$test_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// --- Setup tables and sample test ---
try {
    // Create question_papers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_papers (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            staff_id INT DEFAULT 1,
            subject_id INT DEFAULT 1
        )
    ");

    // Create ia_results table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ia_results (
            id SERIAL PRIMARY KEY,
            student_id INT NOT NULL,
            qp_id INT NOT NULL,
            marks INT,
            content TEXT,
            UNIQUE(student_id, qp_id)
        )
    ");

    // Insert a sample test if none exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM question_papers");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO question_papers (title, content, staff_id, subject_id)
            VALUES (
                'Sample Test',
                '1. What is PHP?\n2. Explain sessions.\n3. Write a SQL query to select all students.',
                1,
                1
            )
        ");
    }

    // If no ID provided, pick first test automatically
    if (!$test_id) {
        $stmt = $pdo->query("SELECT id FROM question_papers ORDER BY id ASC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $test_id = $row['id'] ?? 0;
    }

    if (!$test_id) {
        die("No test available.");
    }

    // --- Fetch test content ---
    $stmt = $pdo->prepare("SELECT * FROM question_papers WHERE id = :id");
    $stmt->execute([':id' => $test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        die("Test not found.");
    }

    // Decode the JSON content into questions array
    $questions = json_decode($test['content'], true);
    if (!$questions || !is_array($questions)) {
        die("Invalid test content.");
    }

    // --- Check if already submitted ---
    $stmt = $pdo->prepare("SELECT id FROM ia_results WHERE student_id = :student_id AND qp_id = :qp_id");
    $stmt->execute([':student_id' => $student_id, ':qp_id' => $test_id]);
    $submitted = $stmt->fetch();

    // --- Handle test submission ---
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted) {
        $answers = $_POST['answers'] ?? [];
        // Calculate marks based on correct answers
        $total_marks = 0;
        $max_marks = count($questions);
        foreach ($questions as $index => $question) {
            $user_answer = $answers[$index] ?? '';
            if ($user_answer === $question['correct']) {
                $total_marks += $question['marks'];
            }
        }

        $answers_json = json_encode($answers);

        $sql = "INSERT INTO ia_results (student_id, qp_id, marks, content)
                VALUES (:student_id, :qp_id, :marks, :content)
                ON CONFLICT (student_id, qp_id)
                DO UPDATE SET marks = EXCLUDED.marks, content = EXCLUDED.content";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':student_id' => $student_id,
            ':qp_id' => $test_id,
            ':marks' => $total_marks,
            ':content' => $answers_json
        ]);

        $message = "<p style='color:green;'>Test submitted successfully! You scored {$total_marks}/{$max_marks}. <a href='dashboard.php'>Back to Dashboard</a></p>";
        $submitted = true; // Mark as submitted to prevent further display
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Take Test: <?= htmlspecialchars($test['title']) ?></title>
<style>
body { font-family: Arial, sans-serif; padding: 20px; background: #f0f0f0; }
.container { max-width: 800px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; }
.question { margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; }
.options { margin-left: 20px; }
button { padding: 10px 20px; margin-top: 10px; }
.message { font-weight: bold; margin-bottom: 15px; }
</style>
</head>
<body>
<div class="container">
    <a href="dashboard.php">&laquo; Back to Dashboard</a>
    <h1><?= htmlspecialchars($test['title']) ?></h1>

    <?php if ($message): ?>
        <div class="message"><?= $message ?></div>
    <?php elseif ($submitted): ?>
        <div class="message"><p style='color:red;'>You have already submitted this test. <a href='dashboard.php'>Back to Dashboard</a></p></div>
    <?php else: ?>
        <form method="POST">
            <?php foreach ($questions as $index => $question): ?>
                <div class="question">
                    <p><strong>Question <?= $index + 1 ?>:</strong> <?= htmlspecialchars($question['question']) ?> (<?= $question['marks'] ?> mark<?= $question['marks'] > 1 ? 's' : '' ?>)</p>
                    <div class="options">
                        <?php foreach ($question['options'] as $key => $option): ?>
                            <label>
                                <input type="radio" name="answers[<?= $index ?>]" value="<?= htmlspecialchars($key) ?>" required>
                                <?= htmlspecialchars($key) ?>. <?= htmlspecialchars($option) ?>
                            </label><br>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="submit">Submit Test</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
