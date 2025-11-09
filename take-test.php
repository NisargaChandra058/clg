<?php
session_save_path('/var/www/sessions');
session_start();
require_once('db.php'); // Use PDO connection

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: student-login.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$test_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$message = '';

if (!$test_id) {
    die("Invalid Test ID specified.");
}

try {
    // --- Handle Test Submission ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $answers = $_POST['answers'] ?? 'No answer provided.';
        $marks = rand(70, 95); // Placeholder: assign a random mark
        
        $sql = "INSERT INTO ia_results (student_id, qp_id, marks, content) 
                VALUES (:student_id, :qp_id, :marks, :content)
                ON CONFLICT (student_id, qp_id) DO UPDATE SET
                marks = EXCLUDED.marks, content = EXCLUDED.content";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':student_id' => $student_id,
            ':qp_id' => $test_id,
            ':marks' => $marks,
            ':content' => $answers
        ]);
        
        $message = "<p class='message success'>Your test has been submitted successfully! <a href='student-dashboard.php'>Back to Dashboard</a></p>";
    }

    // --- Fetch Test Content ---
    $stmt = $pdo->prepare("SELECT title, content FROM question_papers WHERE id = :id");
    $stmt->execute([':id' => $test_id]);
    $test = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$test) {
        die("Test not found.");
    }
    
    // Check if test was already submitted
    $check_stmt = $pdo->prepare("SELECT id FROM ia_results WHERE student_id = :student_id AND qp_id = :qp_id");
    $check_stmt->execute([':student_id' => $student_id, ':qp_id' => $test_id]);
    if ($check_stmt->fetch()) {
        $message = "<p class='message error'>You have already submitted this test. <a href='student-dashboard.php'>Back to Dashboard</a></p>";
    }


} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Take Test: <?= htmlspecialchars($test['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        .back-link { display: block; max-width: 1000px; margin: 0 auto 20px auto; text-align: right; font-weight: bold; color: var(--antiflash-white); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .container { max-width: 1000px; margin: 20px auto; padding: 30px; background: rgba(141, 153, 174, 0.1); border-radius: 15px; border: 1px solid rgba(141, 153, 174, 0.2); }
        .question-paper { background: #fff; color: #333; padding: 30px; border-radius: 8px; }
        .question-paper h1 { margin-top: 0; }
        .question-paper .content { font-size: 1.1em; line-height: 1.7; white-space: pre-wrap; /* Preserves formatting */ }
        textarea { width: 100%; min-height: 200px; padding: 10px; font-size: 1em; border: 1px solid var(--cool-gray); border-radius: 5px; margin-top: 20px; }
        button { padding: 12px 20px; border: none; border-radius: 5px; background-color: var(--fire-engine-red); color: var(--antiflash-white); font-weight: bold; cursor: pointer; width: 100%; font-size: 1.1em; margin-top: 20px; }
        button:hover { background-color: var(--red-pantone); }
        .message { padding: 10px; border-radius: 5px; margin-bottom: 1em; text-align: center; font-weight: bold; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message a { color: #0056b3; font-weight: bold; }
    </style>
</head>
<body>
    <a href="student-dashboard.php" class="back-link">&laquo; Back to Dashboard</a>

    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="message-container"><?= $message ?></div>
        <?php else: ?>
            <div class="question-paper">
                <h1><?= htmlspecialchars($test['title']) ?></h1>
                <hr style="margin: 15px 0;">
                <div class="content"><?= nl2br(htmlspecialchars($test['content'])) ?></div>
            </div>

            <form method="POST">
                <label for="answers" style="font-weight: bold; font-size: 1.2em; margin-top: 20px; display: block;">Your Answers:</label>
                <textarea id="answers" name="answers" placeholder="Type your answers here..."></textarea>
                <button type="submit">Submit Test</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
