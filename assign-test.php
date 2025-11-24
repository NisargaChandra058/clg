<?php
require_once('db.php'); // Database connection (PDO in $pdo)

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $semester_id = filter_input(INPUT_POST, 'semester_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $selected_students = $_POST['students'] ?? [];

    if ($semester_id && $subject_id && !empty($selected_students)) {
        try {
            $pdo->beginTransaction();

            // PostgreSQL insert with ON CONFLICT skip duplicates
            $stmt = $pdo->prepare("
                INSERT INTO student_subject_allocation (student_id, subject_id)
                VALUES (:student_id, :subject_id)
                ON CONFLICT (student_id, subject_id) DO NOTHING
            ");

            $assigned_count = 0;
            foreach ($selected_students as $student_id) {
                if (filter_var($student_id, FILTER_VALIDATE_INT)) {
                    $stmt->execute([
                        ':student_id' => $student_id,
                        ':subject_id' => $subject_id
                    ]);
                    if ($stmt->rowCount() > 0) {
                        $assigned_count++;
                    }
                }
            }

            $pdo->commit();
            $message = "<p class='message success'>✅ Assigned subject to $assigned_count new student(s)!</p>";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "<p class='message error'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        $message = "<p class='message error'>⚠️ Please select a semester, subject, and at least one student.</p>";
    }
}

// --- Fetch dropdown data ---
try {
    $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Use COALESCE to handle subjects with either semester_id or semester value
    $subjects_stmt = $pdo->query("
        SELECT id, name, subject_code, COALESCE(semester_id, semester) AS semester_id
        FROM subjects
        WHERE semester_id IS NOT NULL OR semester IS NOT NULL
        ORDER BY name
    ");
    $subjects_by_semester = [];
    while ($subject = $subjects_stmt->fetch(PDO::FETCH_ASSOC)) {
        $subjects_by_semester[$subject['semester_id']][] = $subject;
    }

    // Fetch students grouped by semester
    $students_stmt = $pdo->query("
        SELECT id, student_name, semester
        FROM students
        WHERE semester IS NOT NULL
        ORDER BY student_name
    ");
    $students_by_semester = [];
    while ($student = $students_stmt->fetch(PDO::FETCH_ASSOC)) {
        $students_by_semester[$student['semester']][] = $student;
    }

} catch (PDOException $e) {
    die("Error fetching data: " . htmlspecialchars($e->getMessage()));
}

// Convert PHP arrays to JSON for JavaScript
$subjects_json = json_encode($subjects_by_semester);
$students_json = json_encode($students_by_semester);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Subject to Students</title>
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
            max-width: 700px;
            margin: 20px auto;
            padding: 30px;
            background: rgba(141,153,174,0.1);
            border-radius: 15px;
            border: 1px solid rgba(141,153,174,0.2);
        }
        h2 { text-align: center; margin-bottom: 20px; }
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
        .students-list {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            padding: 10px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--cool-gray);
        }
        .student-item {
            display: flex;
            align-items: center;
            padding: 4px 0;
        }
        .student-item label {
            font-weight: normal;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Assign Subject to Students</h2>
    <?php if (!empty($message)) echo $message; ?>

    <form method="POST" action="assign-subject-student.php">
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

        <div id="students_section" style="display:none;">
            <label>Students (checked = assign):</label>
            <div class="students-list" id="students_list"></div>
        </div>

        <button type="submit">Assign Selected Students</button>
    </form>
</div>

<script>
const subjectsBySemester = <?= $subjects_json ?>;
const studentsBySemester = <?= $students_json ?>;

const semesterSelect = document.getElementById('semester_id');
const subjectSelect = document.getElementById('subject_id');
const studentsSection = document.getElementById('students_section');
const studentsList = document.getElementById('students_list');

semesterSelect.addEventListener('change', function() {
    const semId = this.value;

    // Reset subject dropdown
    subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
    subjectSelect.disabled = true;

    // Reset students
    studentsList.innerHTML = '';
    studentsSection.style.display = 'none';

    // ✅ Populate Subjects (support COALESCE mapping)
    if (semId && subjectsBySemester[semId]) {
        subjectSelect.disabled = false;
        subjectsBySemester[semId].forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = `${sub.subject_code} - ${sub.name}`;
            subjectSelect.appendChild(opt);
        });

        // Auto-select the first subject
        if (subjectSelect.options.length > 1) {
            subjectSelect.selectedIndex = 1;
        }
    } else {
        console.warn("No subjects found for semester ID:", semId);
    }

    // ✅ Populate Students
    if (semId && studentsBySemester[semId]) {
        studentsSection.style.display = 'block';
        studentsBySemester[semId].forEach(stu => {
            const div = document.createElement('div');
            div.className = 'student-item';
            div.innerHTML = `
                <label>
                    <input type="checkbox" name="students[]" value="${stu.id}" checked>
                    ${stu.student_name}
                </label>
            `;
            studentsList.appendChild(div);
        });
    } else if (semId) {
        studentsSection.style.display = 'block';
        studentsList.innerHTML = '<p style="padding:10px;text-align:center;">No students found for this semester.</p>';
    }
});
</script>
</body>
</html>
