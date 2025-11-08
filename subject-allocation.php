<?php
// session_start(); // Enable if using sessions
require_once('db.php'); // Use PDO connection

// --- Security Check (Placeholder) ---
/*
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login");
    exit;
}
*/

$message = ''; // For success/error messages

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['allocate_subject'])) {
    $staff_id = filter_input(INPUT_POST, 'staff_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);

    if ($staff_id && $subject_id && $class_id) {
        try {
            // New query with class_id
            $sql = "INSERT INTO subject_allocation (staff_id, subject_id, class_id) 
                    VALUES (:staff_id, :subject_id, :class_id) 
                    ON CONFLICT (staff_id, subject_id, class_id) DO NOTHING";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':staff_id' => $staff_id,
                ':subject_id' => $subject_id,
                ':class_id' => $class_id
            ]);

            if ($stmt->rowCount() > 0) {
                $message = "<p class='message success'>Subject allocated successfully!</p>";
            } else {
                $message = "<p class='message error'>This subject is already allocated to this staff member for this class.</p>";
            }
        } catch (PDOException $e) {
            $message = "<p class='message error'>Database error: " . $e->getMessage() . "</p>";
        }
    } else {
        $message = "<p class='message error'>Invalid input. Please select a semester, class, subject, and staff member.</p>";
    }
}

// --- Fetch Data for Dropdowns ---
try {
    // Fetch all semesters
    $sem_stmt = $pdo->query("SELECT id, name FROM semesters ORDER BY name");
    $semesters = $sem_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all classes and group by semester
    $class_stmt = $pdo->query("SELECT id, name, semester_id FROM classes ORDER BY name");
    $classes_by_semester = [];
    while ($class = $class_stmt->fetch(PDO::FETCH_ASSOC)) {
        $classes_by_semester[$class['semester_id']][] = $class;
    }

    // Fetch all subjects and group by semester
    // This query no longer filters out allocated subjects
    $subject_stmt = $pdo->query("
        SELECT id, name AS subject_name, subject_code, semester_id
        FROM subjects
        ORDER BY subject_code
    ");
    $subjects_by_semester = [];
     while ($subject = $subject_stmt->fetch(PDO::FETCH_ASSOC)) {
        $subjects_by_semester[$subject['semester_id']][] = $subject;
    }

    // Fetch all staff members
    $staff_stmt = $pdo->query("SELECT id, first_name, surname FROM users WHERE role = 'staff' ORDER BY first_name");
    $staff_members = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}

// Pass data to JavaScript
$classes_json = json_encode($classes_by_semester);
$subjects_json = json_encode($subjects_by_semester);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Allocate Subject to Staff</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Reusing styles from admin.php */
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        .back-link { display: block; max-width: 860px; margin: 0 auto 20px auto; text-align: right; font-weight: bold; color: var(--antiflash-white); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .container { max-width: 600px; margin: 20px auto; padding: 30px; background: rgba(141, 153, 174, 0.1); border-radius: 15px; border: 1px solid rgba(141, 153, 174, 0.2); }
        h2 { text-align: center; margin-bottom: 20px; }
        form { display: flex; flex-direction: column; gap: 10px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        select { width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 5px; border: 1px solid var(--cool-gray); background: rgba(43, 45, 66, 0.5); color: var(--antiflash-white); box-sizing: border-box; }
        select:disabled { background: rgba(43, 45, 66, 0.2); color: var(--cool-gray); }
        button { padding: 12px 20px; border: none; border-radius: 5px; background-color: var(--fire-engine-red); color: var(--antiflash-white); font-weight: bold; cursor: pointer; width: 100%; font-size: 1.1em; margin-top: 10px; }
        button:hover { background-color: var(--red-pantone); }
        .message { padding: 10px; border-radius: 5px; margin-bottom: 1em; text-align: center; font-weight: bold; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <a href="/admin" class="back-link">&laquo; Back to Admin Dashboard</a>
    <div class="container">
        <h2>Allocate Subject to Staff</h2>

        <?php if (isset($message)) echo $message; ?>

        <form action="subject-allocation.php" method="POST">
            <label for="semester_id">Select Semester:</label>
            <select name="semester_id" id="semester_id" required>
                <option value="">-- Select a Semester --</option>
                <?php foreach ($semesters as $semester): ?>
                    <option value="<?= htmlspecialchars($semester['id']) ?>">
                        <?= htmlspecialchars($semester['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <label for="class_id">Select Class/Section:</label>
            <select name="class_id" id="class_id" required disabled>
                <option value="">-- First Select a Semester --</option>
            </select>

            <label for="subject_id">Select Subject:</label>
            <select name="subject_id" id="subject_id" required disabled>
                <option value="">-- First Select a Semester --</option>
            </select>
            
            <label for="staff_id">Select Staff:</label>
            <select name="staff_id" id="staff_id" required>
                <option value="">-- Select Staff --</option>
                <?php foreach ($staff_members as $staff): ?>
                    <option value="<?= htmlspecialchars($staff['id']) ?>">
                        <?= htmlspecialchars($staff['first_name'] . ' ' . $staff['surname']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="allocate_subject">Allocate Subject</button>
        </form>
    </div>

    <script>
        // Get the data from PHP
        const classesBySemester = <?= $classes_json ?>;
        const subjectsBySemester = <?= $subjects_json ?>;

        const semesterSelect = document.getElementById('semester_id');
        const classSelect = document.getElementById('class_id');
        const subjectSelect = document.getElementById('subject_id');

        semesterSelect.addEventListener('change', function() {
            const selectedSemesterId = this.value;
            
            // Clear dropdowns
            classSelect.innerHTML = '<option value="">-- Select a Class --</option>';
            subjectSelect.innerHTML = '<option value="">-- Select a Subject --</option>';
            
            // Enable/Disable
            classSelect.disabled = !selectedSemesterId;
            subjectSelect.disabled = !selectedSemesterId;

            // Populate Classes
            if (selectedSemesterId && classesBySemester[selectedSemesterId]) {
                classesBySemester[selectedSemesterId].forEach(function(classItem) {
                    const option = document.createElement('option');
                    option.value = classItem.id;
                    option.textContent = classItem.name;
                    classSelect.appendChild(option);
                });
            }

            // Populate Subjects
            if (selectedSemesterId && subjectsBySemester[selectedSemesterId]) {
                subjectsBySemester[selectedSemesterId].forEach(function(subjectItem) {
                    const option = document.createElement('option');
                    option.value = subjectItem.id;
                    option.textContent = subjectItem.subject_code + ' - ' + subjectItem.subject_name;
                    subjectSelect.appendChild(option);
                });
            }
        });
    </script>
</body>
</html>