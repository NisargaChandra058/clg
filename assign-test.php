<?php
// session_start(); // Uncomment if using authentication
require_once('db.php'); // Your PDO connection from db.php

$message = ''; // For success/error messages

// Handle form submission (optional)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $semester_id = filter_input(INPUT_POST, 'semester_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);

    if ($semester_id && $subject_id && $student_id) {
        try {
            // Example insert – you can replace this with your test allocation logic
            $sql = "INSERT INTO test_allocation (class_id, qp_id)
                    VALUES (:class_id, :qp_id)
                    ON CONFLICT (class_id, qp_id) DO NOTHING";
            // Placeholder (modify for your use case)
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':class_id' => $semester_id, ':qp_id' => $subject_id]); // just example
            $message = "<p class='message success'>Assigned successfully!</p>";
        } catch (PDOException $e) {
            $message = "<p class='message error'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        $message = "<p class='message error'>Please select all required fields.</p>";
    }
}

// --- Fetch semesters for dropdown ---
try {
    $sem_stmt = $pdo->query("SELECT id, name FROM semesters ORDER BY name");
    $semesters = $sem_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch subjects grouped by semester
    $subjects_stmt = $pdo->query("SELECT id, name, semester_id FROM subjects ORDER BY name");
    $subjects_by_semester = [];
    while ($subject = $subjects_stmt->fetch(PDO::FETCH_ASSOC)) {
        $subjects_by_semester[$subject['semester_id']][] = $subject;
    }

    // Fetch students grouped by semester (assuming 'classes' link students to semesters)
    $students_stmt = $pdo->query("
        SELECT s.id, s.student_name, c.semester_id
        FROM students s
        JOIN classes c ON s.allotted_branch_management IS NOT NULL OR s.allotted_branch_kea IS NOT NULL
        LEFT JOIN classes cl ON c.id = cl.id
        WHERE c.semester_id IS NOT NULL
        ORDER BY s.student_name
    ");
    $students_by_semester = [];
    while ($row = $students_stmt->fetch(PDO::FETCH_ASSOC)) {
        $students_by_semester[$row['semester_id']][] = $row;
    }

} catch (PDOException $e) {
    die("Error fetching data: " . htmlspecialchars($e->getMessage()));
}

// Pass data to JS
$subjects_json = json_encode($subjects_by_semester);
$students_json = json_encode($students_by_semester);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Test / Subject / Student</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --space-cadet: #2b2d42;
            --cool-gray: #8d99ae;
            --antiflash-white: #edf2f4;
            --red-pantone: #ef233c;
            --fire-engine-red: #d90429;
        }
        body {
            font-family: 'Segoe UI', sans-serif;
            margin: 0; padding: 20px;
            background: var(--space-cadet);
            color: var(--antiflash-white);
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            padding: 30px;
            background: rgba(141,153,174,0.1);
            border-radius: 15px;
            border: 1px solid rgba(141,153,174,0.2);
        }
        h2 { text-align: center; }
        form { display: flex; flex-direction: column; gap: 10px; }
        label { font-weight: bold; }
        select, button {
            padding: 10px;
            border-radius: 5px;
            border: 1px solid var(--cool-gray);
            background: rgba(43,45,66,0.5);
            color: var(--antiflash-white);
        }
        select:disabled {
            background: rgba(43,45,66,0.2);
            color: var(--cool-gray);
        }
        button {
            background-color: var(--fire-engine-red);
            border: none;
            cursor: pointer;
            font-weight: bold;
            font-size: 1em;
        }
        button:hover { background-color: var(--red-pantone); }
        .message {
            text-align: center;
            padding: 10px;
            border-radius: 5px;
            font-weight: bold;
        }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

    <div class="container">
        <h2>Assign Based on Semester</h2>
        <?php if (!empty($message)) echo $message; ?>

        <form method="POST" action="">
            <label for="semester_id">Select Semester:</label>
            <select name="semester_id" id="semester_id" required>
                <option value="">-- Select Semester --</option>
                <?php foreach ($semesters as $sem): ?>
                    <option value="<?= htmlspecialchars($sem['id']) ?>"><?= htmlspecialchars($sem['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="subject_id">Select Subject:</label>
            <select name="subject_id" id="subject_id" required disabled>
                <option value="">-- First select a Semester --</option>
            </select>

            <label for="student_id">Select Student:</label>
            <select name="student_id" id="student_id" required disabled>
                <option value="">-- First select a Semester --</option>
            </select>

            <button type="submit">Assign</button>
        </form>
    </div>

    <script>
        const subjectsBySemester = <?= $subjects_json ?>;
        const studentsBySemester = <?= $students_json ?>;

        const semesterSelect = document.getElementById('semester_id');
        const subjectSelect = document.getElementById('subject_id');
        const studentSelect = document.getElementById('student_id');

        semesterSelect.addEventListener('change', function() {
            const semesterId = this.value;

            // Reset subject dropdown
            subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
            studentSelect.innerHTML = '<option value="">-- Select Student --</option>';
            subjectSelect.disabled = true;
            studentSelect.disabled = true;

            if (semesterId && subjectsBySemester[semesterId]) {
                subjectSelect.disabled = false;
                subjectsBySemester[semesterId].forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name;
                    subjectSelect.appendChild(opt);
                });
            }

            if (semesterId && studentsBySemester[semesterId]) {
                studentSelect.disabled = false;
                studentsBySemester[semesterId].forEach(stu => {
                    const opt = document.createElement('option');
                    opt.value = stu.id;
                    opt.textContent = stu.student_name;
                    studentSelect.appendChild(opt);
                });
            }
        });
    </script>
</body>
</html>