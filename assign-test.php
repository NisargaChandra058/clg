<?php
session_start();
require_once('db.php');

// 1. Handle Assignment
$message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $qp_id = filter_input(INPUT_POST, 'qp_id', FILTER_VALIDATE_INT);
    $selected_students = $_POST['students'] ?? [];

    if ($qp_id && !empty($selected_students)) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO student_test_allocation (student_id, qp_id) VALUES (:sid, :qid) ON CONFLICT (student_id, qp_id) DO NOTHING");
            $count = 0;
            foreach ($selected_students as $sid) {
                $stmt->execute([':sid' => $sid, ':qid' => $qp_id]);
                if ($stmt->rowCount() > 0) $count++;
            }
            $pdo->commit();
            $message = "<div class='message success'>✅ Assigned to $count student(s)!</div>";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "<div class='message error'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        $message = "<div class='message error'>⚠️ Please select a test and at least one student.</div>";
    }
}

// 2. Fetch Data
try {
    // Subjects (Grouped by Semester)
    // COALESCE ensures we get a semester number even if one column is missing
    $sub_stmt = $pdo->query("SELECT id, name, subject_code, COALESCE(semester, semester_id) as sem_num FROM subjects WHERE semester IS NOT NULL OR semester_id IS NOT NULL ORDER BY name");
    $subjects_by_sem = [];
    while ($row = $sub_stmt->fetch(PDO::FETCH_ASSOC)) {
        $subjects_by_sem[$row['sem_num']][] = $row;
    }

    // Question Papers (Grouped by Subject)
    $qp_stmt = $pdo->query("SELECT id, title, subject_id FROM question_papers WHERE subject_id IS NOT NULL ORDER BY title");
    $qps_by_sub = [];
    while ($row = $qp_stmt->fetch(PDO::FETCH_ASSOC)) {
        $qps_by_sub[$row['subject_id']][] = $row;
    }

    // Students (Grouped by Semester)
    // We rely on the 'semester' column being a simple number (1, 2, 3...)
    $stu_stmt = $pdo->query("SELECT id, student_name, semester, usn FROM students WHERE semester IS NOT NULL ORDER BY student_name");
    $students_by_sem = [];
    while ($row = $stu_stmt->fetch(PDO::FETCH_ASSOC)) {
        $students_by_sem[$row['semester']][] = $row;
    }

} catch (Exception $e) {
    die("Data Error: " . $e->getMessage());
}

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
        body { font-family: 'Segoe UI', sans-serif; background: #2b2d42; color: white; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: rgba(255,255,255,0.1); padding: 30px; border-radius: 10px; }
        h2 { text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 10px; margin-bottom: 20px; }
        
        label { display: block; font-weight: bold; margin-bottom: 8px; color: #ccc; }
        select { width: 100%; padding: 12px; margin-bottom: 20px; border-radius: 5px; border: none; font-size: 16px; }
        select:disabled { background: #ddd; color: #666; cursor: not-allowed; }

        .student-box { background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; max-height: 300px; overflow-y: auto; border: 1px solid #555; }
        .student-item { padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; }
        .student-item label { margin-left: 10px; cursor: pointer; width: 100%; font-weight: normal; }
        
        button { width: 100%; padding: 15px; background: #007bff; color: white; border: none; border-radius: 5px; font-size: 18px; font-weight: bold; cursor: pointer; margin-top: 20px; }
        button:hover { background: #0056b3; }
        button:disabled { background: #555; cursor: not-allowed; }

        .message { padding: 15px; text-align: center; border-radius: 5px; margin-bottom: 20px; font-weight: bold; }
        .success { background: #d4edda; color: #155724; } 
        .error { background: #f8d7da; color: #721c24; }
        
        .debug-bar { background: #333; color: #0f0; padding: 10px; font-family: monospace; font-size: 12px; margin-bottom: 20px; overflow-x: auto; white-space: nowrap; }
    </style>
</head>
<body>

<a href="admin-panel.php" style="color:#aaa; text-decoration:none;">&laquo; Back to Dashboard</a>

<div class="container">
    <h2>Assign Test</h2>
    <?= $message ?>

    <!-- Debug info to verify data loading -->
    <div class="debug-bar">
        DEBUG: Students Loaded for Semesters: <?= implode(', ', array_keys($students_by_sem)) ?>
    </div>

    <form method="POST">
        <!-- 1. Semester (Hardcoded to match DB values 1-8) -->
        <label>1. Select Semester:</label>
        <select id="sem_id">
            <option value="">-- Select Semester --</option>
            <option value="1">Semester 1</option>
            <option value="2">Semester 2</option>
            <option value="3">Semester 3</option>
            <option value="4">Semester 4</option>
            <option value="5">Semester 5</option>
            <option value="6">Semester 6</option>
            <option value="7">Semester 7</option>
            <option value="8">Semester 8</option>
        </select>

        <!-- 2. Subject -->
        <label>2. Select Subject:</label>
        <select id="sub_id" disabled>
            <option value="">-- Select Semester First --</option>
        </select>

        <!-- 3. Question Paper -->
        <label>3. Select Test:</label>
        <select id="qp_id" name="qp_id" disabled required>
            <option value="">-- Select Subject First --</option>
        </select>

        <!-- 4. Students -->
        <div id="stu_div" style="display:none;">
            <label>4. Select Students:</label>
            <div id="stu_list" class="student-box"></div>
        </div>

        <button id="btn" disabled>Assign Test</button>
    </form>
</div>

<script>
const subs = <?= $json_subjects ?: '{}' ?>;
const qps = <?= $json_qps ?: '{}' ?>;
const stus = <?= $json_students ?: '{}' ?>;

const elSem = document.getElementById('sem_id');
const elSub = document.getElementById('sub_id');
const elQp = document.getElementById('qp_id');
const elList = document.getElementById('stu_list');
const elDiv = document.getElementById('stu_div');
const elBtn = document.getElementById('btn');

// ON SEMESTER CHANGE
elSem.addEventListener('change', function() {
    const id = this.value; // This will be "1", "2", etc.

    // Reset everything
    elSub.innerHTML = '<option value="">-- Select Subject --</option>'; elSub.disabled = true;
    elQp.innerHTML = '<option value="">-- Select Test --</option>'; elQp.disabled = true;
    elList.innerHTML = ''; elDiv.style.display = 'none'; elBtn.disabled = true;

    if (!id) return;

    // Load Subjects
    if (subs[id]) {
        elSub.disabled = false;
        subs[id].forEach(s => {
            elSub.add(new Option(s.name + ' (' + s.subject_code + ')', s.id));
        });
    } else {
        elSub.add(new Option("-- No subjects found --", ""));
    }

    // Load Students
    if (stus[id]) {
        elDiv.style.display = 'block';
        
        // "Select All" Option
        elList.innerHTML = `
            <div class="student-item" style="border-bottom: 2px solid #777;">
                <input type="checkbox" id="select_all" checked> 
                <label for="select_all" style="font-weight:bold">Select All Students</label>
            </div>
        `;

        stus[id].forEach(s => {
            let usn = s.usn ? `(${s.usn})` : '';
            let html = `
                <div class="student-item">
                    <input type="checkbox" name="students[]" class="stu-chk" value="${s.id}" checked id="st_${s.id}">
                    <label for="st_${s.id}">${s.student_name} ${usn}</label>
                </div>`;
            elList.insertAdjacentHTML('beforeend', html);
        });

        // "Select All" Logic
        document.getElementById('select_all').addEventListener('change', function() {
            document.querySelectorAll('.stu-chk').forEach(cb => cb.checked = this.checked);
        });

    } else {
        elDiv.style.display = 'block';
        elList.innerHTML = `<div style="padding:10px; text-align:center; color:#ff6b6b;">No students found for Semester ${id}.</div>`;
    }
});

// ON SUBJECT CHANGE
elSub.addEventListener('change', function() {
    const id = this.value;
    elQp.innerHTML = '<option value="">-- Select Test --</option>'; elQp.disabled = true; elBtn.disabled = true;
    
    if (!id) return;

    if (qps[id]) {
        elQp.disabled = false;
        qps[id].forEach(q => {
            elQp.add(new Option(q.title, q.id));
        });
    } else {
        elQp.add(new Option("-- No tests found for this subject --", ""));
    }
});

// ON TEST CHANGE
elQp.addEventListener('change', function() {
    elBtn.disabled = !this.value;
});
</script>

</body>
</html>
