<?php
session_start();
require_once('db.php'); // Database connection ($pdo)

// Optional: Admin check
/*
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login");
    exit;
}
*/

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $semester_id = filter_input(INPUT_POST, 'semester_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $selected_students = $_POST['students'] ?? [];

    if ($semester_id && $subject_id && !empty($selected_students)) {
        try {
            $pdo->beginTransaction();

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
            $message = "<div class='message success'>✅ Assigned subject to $assigned_count new student(s)!</div>";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "<div class='message error'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='message error'>⚠️ Please select a semester, subject, and at least one student.</div>";
    }
}

// --- Fetch dropdown data ---
try {
    // 1. Semesters
    $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Subjects (Grouped by Semester)
    // Note: We use COALESCE to handle cases where semester_id might be in different columns based on previous migrations
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

    // 3. Students (Grouped by Semester)
    // CRITICAL: This queries the 'students' table, NOT 'users'.
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

// Convert to JSON for JS
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }
        h2 { text-align: center; margin-bottom: 20px; border-bottom: 2px solid var(--fire-engine-red); padding-bottom: 10px; display: inline-block; }
        form { display: flex; flex-direction: column; gap: 15px; }
        label { font-weight: bold; color: var(--cool-gray); }
        
        select, button {
            padding: 12px;
            border-radius: 6px;
            border: 1px solid var(--cool-gray);
            background: rgba(43,45,66,0.8);
            color: var(--antiflash-white);
            font-size: 1rem;
        }
        select:focus { outline: none; border-color: var(--red-pantone); }
        select:disabled { background: rgba(43,45,66,0.3); color: #666; cursor: not-allowed; }
        
        button {
            background-color: var(--fire-engine-red);
            border: none;
            cursor: pointer;
            font-weight: bold;
            margin-top: 10px;
            transition: background 0.3s;
        }
        button:hover { background-color: var(--red-pantone); }
        
        .message { padding: 15px; border-radius: 6px; text-align: center; font-weight: bold; margin-bottom: 20px; }
        .success { background: rgba(42, 157, 143, 0.2); color: #4ecdc4; border: 1px solid #2a9d8f; }
        .error { background: rgba(239, 35, 60, 0.2); color: #ffadad; border: 1px solid #ef233c; }
        
        .students-list {
            background: rgba(0,0,0,0.2);
            border-radius: 8px;
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--cool-gray);
        }
        .student-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            transition: background 0.2s;
        }
        .student-item:hover { background: rgba(255,255,255,0.05); }
        .student-item label { cursor: pointer; color: var(--antiflash-white); font-weight: normal; width: 100%; margin-left: 10px; }
        input[type="checkbox"] { transform: scale(1.2); cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <center><h2>Assign Subject to Students</h2></center>
    <?php if (!empty($message)) echo $message; ?>

    <form method="POST" action="assign-subject-student.php">
        <!-- Semester -->
        <label for="semester_id">1. Select Semester:</label>
        <select name="semester_id" id="semester_id" required>
            <option value="">-- Select Semester --</option>
            <?php foreach ($semesters as $sem): ?>
                <option value="<?= htmlspecialchars($sem['id']) ?>"><?= htmlspecialchars($sem['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Subject -->
        <label for="subject_id">2. Select Subject:</label>
        <select name="subject_id" id="subject_id" required disabled>
            <option value="">-- First select a Semester --</option>
        </select>

        <!-- Students List -->
        <div id="students_section" style="display:none;">
            <label>3. Select Students:</label>
            <div class="students-list" id="students_list">
                <!-- Populated by JS -->
            </div>
        </div>

        <button type="submit" id="submit_btn" disabled>Assign Selected Students</button>
    </form>
</div>

<script>
// Load data from PHP
const subjectsBySemester = <?= $subjects_json ?: '{}' ?>;
const studentsBySemester = <?= $students_json ?: '{}' ?>;

// Debugging: Check your console (F12) to see if data is loaded!
console.log("Subjects Data:", subjectsBySemester);
console.log("Students Data:", studentsBySemester);

const semesterSelect = document.getElementById('semester_id');
const subjectSelect = document.getElementById('subject_id');
const studentsSection = document.getElementById('students_section');
const studentsList = document.getElementById('students_list');
const submitBtn = document.getElementById('submit_btn');

semesterSelect.addEventListener('change', function() {
    const semId = this.value;
    console.log("Selected Semester ID:", semId);

    // 1. Reset Subject Dropdown
    subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
    subjectSelect.disabled = true;

    // 2. Reset Students List
    studentsList.innerHTML = '';
    studentsSection.style.display = 'none';
    submitBtn.disabled = true;

    if (!semId) return;

    // --- POPULATE SUBJECTS ---
    if (subjectsBySemester[semId]) {
        subjectSelect.disabled = false;
        subjectsBySemester[semId].forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = `${sub.subject_code} - ${sub.name}`;
            subjectSelect.appendChild(opt);
        });
    } else {
        const opt = document.createElement('option');
        opt.textContent = "-- No subjects found --";
        subjectSelect.appendChild(opt);
    }

    // --- POPULATE STUDENTS ---
    if (studentsBySemester[semId]) {
        studentsSection.style.display = 'block';
        submitBtn.disabled = false;
        
        // Add "Select All" option
        const selectAllDiv = document.createElement('div');
        selectAllDiv.className = 'student-item';
        selectAllDiv.style.borderBottom = "2px solid #666";
        selectAllDiv.innerHTML = `
            <input type="checkbox" id="select_all" checked>
            <label for="select_all" style="font-weight:bold;">Select All Students</label>
        `;
        studentsList.appendChild(selectAllDiv);

        // Add individual students
        studentsBySemester[semId].forEach(stu => {
            const div = document.createElement('div');
            div.className = 'student-item';
            div.innerHTML = `
                <input type="checkbox" name="students[]" value="${stu.id}" class="stu-checkbox" checked id="stu_${stu.id}">
                <label for="stu_${stu.id}">
                    ${stu.student_name}
                </label>
            `;
            studentsList.appendChild(div);
        });

        // Select All Logic
        document.getElementById('select_all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.stu-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

    } else {
        studentsSection.style.display = 'block';
        studentsList.innerHTML = '<p style="padding:10px;text-align:center;color:#ffadad;">No students found for this semester in the <strong>students</strong> table.<br><small>Did you run the SQL migration?</small></p>';
    }
});
</script>
</body>
</html>
