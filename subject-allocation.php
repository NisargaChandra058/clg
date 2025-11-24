<?php
session_start();
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('db.php'); // Includes $pdo

$message = '';

// 1. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_subject'])) {
    $staff_id   = filter_input(INPUT_POST, 'staff_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $raw_section = $_POST['section'] ?? '';
    $section = trim((string)$raw_section);
    
    // If section is empty string, treat as NULL
    if ($section === '') $section = null;

    if (!$staff_id || !$subject_id) {
        $message = "<p class='message error'>Please select a staff member and a subject.</p>";
    } else {
        try {
            // Check if 'section' column exists (Dynamic check)
            $colStmt = $pdo->prepare("
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'subject_allocation'
                  AND column_name = 'section'
                LIMIT 1
            ");
            $colStmt->execute();
            $hasSection = (bool)$colStmt->fetchColumn();

            // --- CRITICAL FIX FOR POSTGRESQL ERROR 42P18 ---
            // We split the logic in PHP instead of using complex SQL "OR ? IS NULL"
            $exists = false;

            if ($hasSection) {
                if ($section !== null) {
                    // Case A: Checking for a specific section (e.g., "A")
                    $checkStmt = $pdo->prepare("SELECT 1 FROM subject_allocation WHERE staff_id = ? AND subject_id = ? AND section = ?");
                    $checkStmt->execute([$staff_id, $subject_id, $section]);
                } else {
                    // Case B: Checking for NULL section
                    $checkStmt = $pdo->prepare("SELECT 1 FROM subject_allocation WHERE staff_id = ? AND subject_id = ? AND section IS NULL");
                    $checkStmt->execute([$staff_id, $subject_id]);
                }
                if ($checkStmt->fetch()) $exists = true;
            } else {
                // Old schema (no section column)
                $checkStmt = $pdo->prepare("SELECT 1 FROM subject_allocation WHERE staff_id = ? AND subject_id = ?");
                $checkStmt->execute([$staff_id, $subject_id]);
                if ($checkStmt->fetch()) $exists = true;
            }

            if ($exists) {
                $message = "<p class='message error'>This subject is already allocated to this staff member.</p>";
            } else {
                // Insert
                if ($hasSection) {
                    $ins = $pdo->prepare("INSERT INTO subject_allocation (staff_id, subject_id, section) VALUES (?, ?, ?)");
                    $ins->execute([$staff_id, $subject_id, $section]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO subject_allocation (staff_id, subject_id) VALUES (?, ?)");
                    $ins->execute([$staff_id, $subject_id]);
                }
                $message = "<p class='message success'>✅ Subject allocated successfully!</p>";
            }
        } catch (PDOException $e) {
            $message = "<p class='message error'>Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// 2. Fetch Data for Dropdowns
try {
    $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Subjects (Grouped by Semester ID)
    $subjectStmt = $pdo->query("
        SELECT id, name AS subject_name, subject_code, COALESCE(semester_id, semester) as sem_id
        FROM subjects
        ORDER BY subject_code
    ");
    
    $subjects_by_sem = [];
    while ($s = $subjectStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = ($s['sem_id'] === null) ? 'unassigned' : (string)$s['sem_id'];
        $subjects_by_sem[$key][] = [
            'id' => (int)$s['id'],
            'subject_name' => $s['subject_name'],
            'subject_code' => $s['subject_code']
        ];
    }

    $staff_members = $pdo->query("SELECT id, first_name, surname FROM users WHERE role = 'staff' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error loading data: " . htmlspecialchars($e->getMessage()));
}

$subjects_json = json_encode($subjects_by_sem, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Allocate Subject</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
    :root { --bg-color: #f4f4f9; --text-color: #333; --primary: #007bff; --card-bg: #fff; }
    body { font-family: 'Segoe UI', Tahoma, sans-serif; background: var(--bg-color); color: var(--text-color); margin: 0; padding: 20px; display: flex; justify-content: center; }
    .container { width: 100%; max-width: 600px; background: var(--card-bg); padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    h2 { text-align: center; margin-top: 0; color: var(--primary); }
    
    label { display: block; margin-top: 15px; font-weight: 600; font-size: 0.9rem; color: #555; }
    select, input[type="text"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px; box-sizing: border-box; font-size: 1rem; }
    select:disabled { background: #e9ecef; cursor: not-allowed; }
    
    button { width: 100%; padding: 12px; background: var(--primary); color: #fff; border: none; border-radius: 5px; font-size: 1rem; cursor: pointer; margin-top: 20px; transition: background 0.3s; }
    button:hover { background: #0056b3; }
    button.secondary { background: transparent; color: #666; border: 1px solid #ccc; margin-top: 10px; }
    
    .message { padding: 12px; border-radius: 5px; margin-bottom: 20px; text-align: center; font-weight: bold; }
    .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .note { font-size: 0.85rem; color: #777; margin-top: 15px; text-align: center; }
</style>
</head>
<body>

<div class="container">
    <h2>Allocate Subject to Staff</h2>

    <?php if ($message) echo $message; ?>

    <form method="post" id="allocForm">
        <!-- 1. Select Semester -->
        <label for="semester_select">Select Semester</label>
        <select id="semester_select" name="semester_id" required>
            <option value="">-- Select Semester --</option>
            <?php foreach ($semesters as $sem): ?>
                <option value="<?= htmlspecialchars($sem['id']) ?>"><?= htmlspecialchars($sem['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- 2. Select Subject -->
        <label for="subject_select">Select Subject</label>
        <select id="subject_select" name="subject_id" disabled required>
            <option value="">-- First select a semester --</option>
        </select>

        <!-- 3. Select Staff -->
        <label for="staff_select">Select Staff</label>
        <select id="staff_select" name="staff_id" required>
            <option value="">-- Select Staff Member --</option>
            <?php foreach ($staff_members as $st): ?>
                <option value="<?= htmlspecialchars($st['id']) ?>">
                    <?= htmlspecialchars($st['first_name'] . ' ' . $st['surname']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- 4. Section -->
        <label for="section">Section (Optional)</label>
        <input type="text" id="section" name="section" placeholder="e.g. A, B, C (Leave blank if not applicable)">

        <button type="submit" name="allocate_subject">Allocate Subject</button>
        <button type="button" class="secondary" onclick="window.location.href='admin-panel.php'">Back to Admin Panel</button>
    </form>
</div>

<script>
    const subjectsBySemester = <?= $subjects_json ?: '{}' ?>;
    const semesterSelect = document.getElementById('semester_select');
    const subjectSelect = document.getElementById('subject_select');

    semesterSelect.addEventListener('change', function() {
        const semesterId = this.value;
        
        subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';
        subjectSelect.disabled = true;

        if (!semesterId) return;

        const subjects = subjectsBySemester[semesterId];

        if (subjects && subjects.length > 0) {
            subjectSelect.disabled = false;
            subjects.forEach(s => {
                const option = document.createElement('option');
                option.value = s.id;
                option.textContent = (s.subject_code ? s.subject_code + ' - ' : '') + s.subject_name;
                subjectSelect.appendChild(option);
            });
        } else {
            const option = document.createElement('option');
            option.text = "-- No subjects found for this semester --";
            subjectSelect.appendChild(option);
        }
    });
</script>

</body>
</html>
