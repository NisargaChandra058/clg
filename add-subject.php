<?php
session_start();
require_once('db.php'); // PDO connection ($pdo)

// (Optional) Ensure only admin access
/*
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login");
    exit;
}
*/

$feedback_message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $subjects = $_POST['subjects'] ?? [];
    $branch = trim($_POST['branch'] ?? '');
    $semester = filter_input(INPUT_POST, 'semester', FILTER_VALIDATE_INT);
    $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT);

    if (empty($subjects) || empty($branch) || !$semester || !$year) {
        $feedback_message = "<p class='message error'>❌ Please fill in all fields, including at least one subject.</p>";
    } else {
        try {
            $pdo->beginTransaction();

            // ✅ Correct column name (semester, not semester_id)
            $sql = "
                INSERT INTO subjects (name, subject_code, branch, semester, year)
                VALUES (:subject_name, :subject_code, :branch, :semester, :year)
                ON CONFLICT (subject_code) DO NOTHING
            ";
            $stmt = $pdo->prepare($sql);

            $added_count = 0;
            foreach ($subjects as $subject) {
                $subject_name = trim($subject['subject_name'] ?? '');
                $subject_code = trim($subject['subject_code'] ?? '');

                if ($subject_name !== '' && $subject_code !== '') {
                    $stmt->execute([
                        ':subject_name' => htmlspecialchars($subject_name, ENT_QUOTES, 'UTF-8'),
                        ':subject_code' => htmlspecialchars($subject_code, ENT_QUOTES, 'UTF-8'),
                        ':branch' => htmlspecialchars($branch, ENT_QUOTES, 'UTF-8'),
                        ':semester' => $semester,
                        ':year' => $year
                    ]);

                    // Count only new inserts (not skipped by ON CONFLICT)
                    if ($stmt->rowCount() > 0) {
                        $added_count++;
                    }
                }
            }

            $pdo->commit();
            $feedback_message = "<p class='message success'>✅ $added_count subject(s) added successfully!</p>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $feedback_message = "<p class='message error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// Fetch semesters
try {
    $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching semesters: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Subjects</title>
    <style>
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        .back-link { display: block; max-width: 860px; margin: 0 auto 20px auto; text-align: right; font-weight: bold; color: var(--antiflash-white); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .container { max-width: 800px; margin: 20px auto; padding: 30px; background: rgba(141, 153, 174, 0.1); border-radius: 15px; border: 1px solid rgba(141, 153, 174, 0.2); }
        h2 { text-align: center; margin-bottom: 20px; }
        label { font-weight: bold; margin-top: 15px; display: block; }
        input, select { width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc; margin-top: 5px; background: rgba(43, 45, 66, 0.5); color: var(--antiflash-white); }
        button { width: 100%; margin-top: 20px; padding: 12px; background: var(--fire-engine-red); border: none; border-radius: 5px; color: white; font-weight: bold; font-size: 16px; cursor: pointer; }
        button:hover { background: var(--red-pantone); }
        .message { padding: 10px; border-radius: 5px; text-align: center; font-weight: bold; margin-bottom: 10px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .subject-form { border: 1px solid #666; padding: 10px; border-radius: 5px; margin-bottom: 15px; position: relative; }
        .remove-btn { position: absolute; top: 5px; right: 10px; color: #ef233c; background: none; border: none; font-size: 20px; cursor: pointer; }
    </style>
</head>
<body>
    <a href="/admin-panel.php" class="back-link">&laquo; Back to Admin Dashboard</a>

    <div class="container">
        <h2>Add New Subjects (Bulk)</h2>
        <?= $feedback_message ?>

        <form action="add-subject.php" method="POST">
            <label for="branch">Branch:</label>
            <select name="branch" id="branch" required>
                <option value="">-- Select a Branch --</option>
                <option value="CSE">Computer Science</option>
                <option value="ECE">Electronics</option>
                <option value="MECH">Mechanical</option>
                <option value="CIVIL">Civil</option>
            </select>

            <label for="semester">Semester:</label>
            <select name="semester" id="semester" required>
                <option value="">-- Select Semester --</option>
                <?php foreach ($semesters as $sem): ?>
                    <option value="<?= htmlspecialchars($sem['id']) ?>"><?= htmlspecialchars($sem['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="year">Year (1-4):</label>
            <input type="number" name="year" id="year" min="1" max="4" required>

            <h3>Subjects</h3>
            <div id="subject-form-container">
                <div class="subject-form">
                    <button type="button" class="remove-btn" onclick="this.parentElement.remove()">×</button>
                    <label>Subject Name:</label>
                    <input type="text" name="subjects[0][subject_name]" required>
                    <label>Subject Code:</label>
                    <input type="text" name="subjects[0][subject_code]" required>
                </div>
            </div>

            <button type="button" onclick="addSubjectForm()">+ Add Another Subject</button>
            <button type="submit">Add All Subjects</button>
        </form>
    </div>

    <script>
        let subjectCount = 1;
        function addSubjectForm() {
            const container = document.getElementById('subject-form-container');
            const newForm = document.createElement('div');
            newForm.classList.add('subject-form');
            newForm.innerHTML = `
                <button type="button" class="remove-btn" onclick="this.parentElement.remove()">×</button>
                <label>Subject Name:</label>
                <input type="text" name="subjects[${subjectCount}][subject_name]" required>
                <label>Subject Code:</label>
                <input type="text" name="subjects[${subjectCount}][subject_code]" required>
            `;
            container.appendChild(newForm);
            subjectCount++;
        }
    </script>
</body>
</html>