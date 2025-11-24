<?php
session_start();
require_once('db.php'); // PDO connection ($pdo)

// --- 1. AUTO-SETUP TABLE (Self-Healing) ---
// Ensures the table for linking Students to Tests exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_test_allocation (
            id SERIAL PRIMARY KEY,
            student_id INT NOT NULL,
            qp_id INT NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(student_id, qp_id),
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY (qp_id) REFERENCES question_papers(id) ON DELETE CASCADE
        );
    ");
} catch (PDOException $e) {
    die("Table Setup Error: " . $e->getMessage());
}

$message = '';

// --- 2. HANDLE FORM SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $qp_id = filter_input(INPUT_POST, 'qp_id', FILTER_VALIDATE_INT);
    $selected_students = $_POST['students'] ?? [];

    if ($qp_id && !empty($selected_students)) {
        try {
            $pdo->beginTransaction();
            
            // Insert statement with ON CONFLICT to avoid duplicates
            $stmt = $pdo->prepare("
                INSERT INTO student_test_allocation (student_id, qp_id) 
                VALUES (:student_id, :qp_id) 
                ON CONFLICT (student_id, qp_id) DO NOTHING
            ");

            $count = 0;
            foreach ($selected_students as $sid) {
                $stmt->execute([':student_id' => $sid, ':qp_id' => $qp_id]);
                if ($stmt->rowCount() > 0) $count++;
            }
            $pdo->commit();
            
            if ($count > 0) {
                $message = "<div class='message success'>✅ Test assigned to $count student(s) successfully!</div>";
            } else {
                $message = "<div class='message warning'>⚠️ These students were already assigned this test.</div>";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "<div class='message error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='message error'>⚠️ Please select a Question Paper and at least one Student.</div>";
    }
}

// --- 3. FETCH DATA FOR DROPDOWNS ---
try {
    // A. Semesters
    $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // B. Subjects (Grouped by Semester)
    // Uses 'semester_id' to match your database structure
    $sub_stmt = $pdo->query("SELECT id, name, subject_code, semester_id FROM subjects WHERE semester_id IS NOT NULL ORDER BY name");
    $subjects_by_sem = [];
    while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
        $subjects_by_sem[$row['semester_id']][] = $row;
    }

    // C. Question Papers (Grouped by Subject)
    $qp_stmt = $pdo->query("SELECT id, title, subject_id FROM question_papers WHERE subject_id IS NOT NULL ORDER BY title");
    $qps_by_sub = [];
    while ($row = $qp_stmt->fetch(PDO::FETCH_ASSOC)) {
        $qps_by_sub[$row['subject_id']][] = $row;
    }

    // D. Students (Grouped by Semester)
    // This fetches the data you just confirmed in your screenshot
    $stu_stmt = $pdo->query("SELECT id, student_name, semester FROM students WHERE semester IS NOT NULL ORDER BY student_name");
    $students_by_sem = [];
    while ($row = $stu_stmt->fetch(PDO::FETCH_ASSOC)) {
        $students_by_sem[$row['semester']][] = $row;
    }

} catch (Exception $e) {
    die("Data Fetch Error: " . $e->getMessage());
}

// Prepare JSON for JavaScript
$json_subjects = json_encode($subjects_by_sem);
$json_qps = json_encode($qps_by_sub);
$json_students = json_encode($students_by_sem);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root { --bg-dark: #2b2d42; --card-bg: #3a3d55; --text-light: #edf2f4; --primary: #007bff; --success: #28a745; --danger: #dc3545; --warning: #ffc107; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg-dark); color: var(--text-light); padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: rgba(255,255,255,0.05); padding: 30px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); }
        h2 { text-align: center; border-bottom: 2px solid var(--primary); padding-bottom: 10px; margin-bottom: 20px; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 8px; color: #ccc; }
        select { width: 100%; padding: 12px; background: #fff; border-radius: 5px; border: none; font-size: 16px; }
        select:disabled { background: #ddd; color: #666; cursor: not-allowed; }

        .student-box { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; max-height: 300px; overflow-y: auto; border: 1px solid #555; }
        .student-item { padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; }
        .student-item label { margin: 0 0 0 10px; font-weight: normal; color: #fff; cursor: pointer; width: 100%; }
        input[type="checkbox"] { transform: scale(1.3); cursor: pointer; }

        button { width: 100%; padding: 15px; background: var(--primary); color: white; border: none; border-radius: 5px; font-size: 18px; font-weight: bold; cursor: pointer; margin-top: 10px; transition: 0.3s; }
        button:hover { background: #0056b3; }
        button:disabled { background: #555; cursor: not-allowed; }

        .message { padding: 15px; text-align: center; border-radius: 5px; margin-bottom: 20px; font-weight: bold; }
        .success { background: rgba(40, 167, 69, 0.2); border: 1px solid var(--success); color: #2ecc71; }
        .error { background: rgba(220, 53, 69, 0.2); border: 1px solid var(--danger); color: #ff6b6b; }
        .warning { background: rgba(255, 193, 7, 0.2); border: 1px solid var(--warning); color: #f1c40f; }
        
        .nav-link { display: block; text-align: right; color: #aaa; text-decoration: none; margin-bottom: 10px; }
        .nav-link:hover { color: #fff; }
    </style>
</head>
<body>

<a href="admin-panel.php" class="nav-link">&laquo; Back to Dashboard</a>

<div class="container">
    <h2>Assign Test / Question Paper</h2>
    <?= $message ?>

    <form method="POST">
        <!-- 1. Semester -->
        <div class="form-group">
            <label>1. Select Semester:</label>
            <select id="semester_id">
                <option value="">-- Select Semester --</option>
                <?php foreach ($semesters as $sem): ?>
                    <option value="<?= $sem['id'] ?>"><?= htmlspecialchars($sem['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 2. Subject -->
        <div class="form-group">
            <label>2. Select Subject:</label>
            <select id="subject_id" disabled>
                <option value="">-- Select Semester First --</option>
            </select>
        </div>

        <!-- 3. Question Paper -->
        <div class="form-group">
            <label>3. Select Question Paper (Test):</label>
            <select id="qp_id" name="qp_id" disabled required>
                <option value="">-- Select Subject First --</option>
            </select>
        </div>

        <!-- 4. Students -->
        <div class="form-group" id="student_section" style="display:none;">
            <label>4. Select Students:</label>
            <div class="student-box" id="student_list"></div>
        </div>

        <button type="submit" id="submit_btn" disabled>Assign Test</button>
    </form>
</div>

<script>
// Load PHP Data
const subjectsData = <?= $json_subjects ?: '{}' ?>;
const qpsData = <?= $json_qps ?: '{}' ?>;
const studentsData = <?= $json_students ?: '{}' ?>;

// Debugging - check console to confirm data loaded
console.log("Students Data:", studentsData);

const elSem = document.getElementById('semester_id');
const elSub = document.getElementById('subject_id');
const elQp = document.getElementById('qp_id');
const elList = document.getElementById('student_list');
const elSection = document.getElementById('student_section');
const elBtn = document.getElementById('submit_btn');

// 1. On Semester Change -> Load Subjects AND Students
elSem.addEventListener('change', function() {
    const semId = this.value;

    // Reset Subject
    elSub.innerHTML = '<option value="">-- Select Subject --</option>';
    elSub.disabled = true;
    
    // Reset QP
    elQp.innerHTML = '<option value="">-- Select Subject First --</option>';
    elQp.disabled = true;

    // Reset Students
    elList.innerHTML = '';
    elSection.style.display = 'none';
    elBtn.disabled = true;

    if (!semId) return;

    // Populate Subjects
    if (subjectsData[semId]) {
        elSub.disabled = false;
        subjectsData[semId].forEach(s => {
            elSub.add(new Option(s.name + ' (' + s.subject_code + ')', s.id));
        });
    } else {
        elSub.add(new Option("-- No subjects found for this sem --", ""));
    }

    // Populate Students (Based on your DB screenshot data)
    if (studentsData[semId]) {
        elSection.style.display = 'block';
        
        // Select All Header
        let allDiv = document.createElement('div');
        allDiv.className = 'student-item';
        allDiv.style.borderBottom = '2px solid #777';
        allDiv.innerHTML = `<input type="checkbox" id="select_all" checked> <label for="select_all" style="font-weight:bold">Select All Students</label>`;
        elList.appendChild(allDiv);

        studentsData[semId].forEach(s => {
            let div = document.createElement('div');
            div.className = 'student-item';
            div.innerHTML = `<input type="checkbox" name="students[]" class="stu-chk" value="${s.id}" checked id="s_${s.id}"> <label for="s_${s.id}">${s.student_name} (${s.usn || 'No USN'})</label>`;
            elList.appendChild(div);
        });

        // Select All Logic
        document.getElementById('select_all').addEventListener('change', function() {
            document.querySelectorAll('.stu-chk').forEach(cb => cb.checked = this.checked);
        });
    } else {
        elSection.style.display = 'block';
        elList.innerHTML = '<div style="padding:10px; text-align:center; color:#ff6b6b;">No students found in this semester.</div>';
    }
});

// 2. On Subject Change -> Load Question Papers
elSub.addEventListener('change', function() {
    const subId = this.value;
    
    elQp.innerHTML = '<option value="">-- Select Question Paper --</option>';
    elQp.disabled = true;
    elBtn.disabled = true;

    if (!subId) return;

    if (qpsData[subId]) {
        elQp.disabled = false;
        qpsData[subId].forEach(q => {
            elQp.add(new Option(q.title, q.id));
        });
    } else {
        elQp.add(new Option("-- No tests created for this subject --", ""));
    }
});

// 3. Enable Submit only if QP is selected
elQp.addEventListener('change', function() {
    if (this.value) {
        elBtn.disabled = false;
    } else {
        elBtn.disabled = true;
    }
});

</script>

</body>
</html>
