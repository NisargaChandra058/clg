<?php
session_start(); // uncomment if you use sessions and authentication

// Optional: restrict to admins
/*
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login");
    exit;
}
*/

require_once('db.php'); // $pdo PDO instance

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate_subject'])) {
    // sanitize inputs
    $staff_id   = filter_input(INPUT_POST, 'staff_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $raw_section = $_POST['section'] ?? '';
    $section = trim((string)$raw_section);
    if ($section === '') $section = null;

    if (!$staff_id || !$subject_id) {
        $message = "<p class='message error'>Please select a staff member and a subject.</p>";
    } else {
        try {
            // detect if subject_allocation has a 'section' column
            $colStmt = $pdo->prepare("
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'subject_allocation'
                  AND column_name = 'section'
                LIMIT 1
            ");
            $colStmt->execute();
            $hasSection = (bool)$colStmt->fetchColumn();

            if ($hasSection) {
                $sql = "
                    INSERT INTO subject_allocation (staff_id, subject_id, section)
                    SELECT :staff_id, :subject_id, :section
                    WHERE NOT EXISTS (
                        SELECT 1 FROM subject_allocation
                        WHERE staff_id = :staff_id AND subject_id = :subject_id
                          AND ( (section IS NULL AND :section IS NULL) OR section = :section )
                    )
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':staff_id', $staff_id, PDO::PARAM_INT);
                $stmt->bindValue(':subject_id', $subject_id, PDO::PARAM_INT);
                if ($section === null) {
                    $stmt->bindValue(':section', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':section', $section, PDO::PARAM_STR);
                }
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $message = "<p class='message success'>Subject allocated successfully (with section)!</p>";
                } else {
                    $message = "<p class='message error'>This allocation already exists for the selected staff/subject/section.</p>";
                }
            } else {
                // table doesn't have section column; insert staff+subject only
                $sql = "
                    INSERT INTO subject_allocation (staff_id, subject_id)
                    SELECT :staff_id, :subject_id
                    WHERE NOT EXISTS (
                        SELECT 1 FROM subject_allocation
                        WHERE staff_id = :staff_id AND subject_id = :subject_id
                    )
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':staff_id', $staff_id, PDO::PARAM_INT);
                $stmt->bindValue(':subject_id', $subject_id, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $message = "<p class='message success'>Subject allocated successfully!</p>";
                } else {
                    $message = "<p class='message error'>This allocation already exists for the selected staff and subject.</p>";
                }
            }
        } catch (PDOException $e) {
            $message = "<p class='message error'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// Fetch data for the page
try {
    // Semesters (used by the semester select)
    $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Subjects: use 'semester' column (not semester_id)
    $subjectStmt = $pdo->query("
        SELECT id, name AS subject_name, subject_code, semester
        FROM subjects
        ORDER BY subject_code
    ");
    $subjects_by_sem = [];
    while ($s = $subjectStmt->fetch(PDO::FETCH_ASSOC)) {
        // normalize key: if null -> 'unassigned' else string of semester value
        $key = ($s['semester'] === null) ? 'unassigned' : (string)$s['semester'];
        if (!isset($subjects_by_sem[$key])) $subjects_by_sem[$key] = [];
        $subjects_by_sem[$key][] = [
            'id' => (int)$s['id'],
            'subject_name' => $s['subject_name'],
            'subject_code' => $s['subject_code']
        ];
    }

    // staff members
    $staff_members = $pdo->query("SELECT id, first_name, surname FROM users WHERE role = 'staff' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error fetching data: " . htmlspecialchars($e->getMessage()));
}

// Safe JSON for JS
$subjects_json = json_encode($subjects_by_sem, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Allocate Subject to Staff</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
    :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; margin:0; padding:20px; background:var(--space-cadet); color:var(--antiflash-white); }
    .container { max-width:720px; margin:20px auto; padding:28px; background:rgba(141,153,174,0.07); border-radius:12px; border:1px solid rgba(141,153,174,0.12); }
    h2 { text-align:center; margin-bottom:18px; }
    label { display:block; margin-top:10px; font-weight:600; }
    select,input[type="text"] { width:100%; padding:10px; border-radius:6px; border:1px solid var(--cool-gray); background:rgba(43,45,66,0.5); color:var(--antiflash-white); box-sizing:border-box; margin-top:6px; }
    select:disabled { background:rgba(43,45,66,0.2); color:var(--cool-gray); }
    .actions { margin-top:16px; display:flex; gap:12px; }
    button { flex:1; padding:12px; border-radius:8px; border:none; background:var(--fire-engine-red); color:white; font-weight:700; cursor:pointer; }
    button.secondary { background:transparent; border:1px solid rgba(255,255,255,0.08); color:var(--antiflash-white); }
    .message { margin-bottom:14px; padding:10px; border-radius:6px; font-weight:700; text-align:center; }
    .success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .note { margin-top:10px; color:var(--cool-gray); font-size:0.95rem; }
</style>
</head>
<body>
    <div class="container">
        <h2>Allocate Subject to Staff</h2>

        <?php if ($message) echo $message; ?>

        <form method="post" id="allocForm" action="subject-allocation.php">
            <label for="semester_select">Select Semester</label>
            <select id="semester_select" name="semester_id" required>
                <option value="">-- Select Semester --</option>
                <?php foreach ($semesters as $sem): ?>
                    <option value="<?= htmlspecialchars($sem['id']) ?>"><?= htmlspecialchars($sem['name']) ?></option>
                <?php endforeach; ?>
                <option value="unassigned">-- Unassigned / No Semester --</option>
            </select>

            <label for="section">Section (enter here)</label>
            <input type="text" id="section" name="section" placeholder="e.g. A or A1 — leave blank if not needed" autocomplete="off" />

            <label for="subject_select">Select Subject (loaded by semester)</label>
            <select id="subject_select" name="subject_id" disabled required>
                <option value="">-- First select a semester --</option>
            </select>

            <label for="staff_select">Select Staff</label>
            <select id="staff_select" name="staff_id" required>
                <option value="">-- Select Staff --</option>
                <?php foreach ($staff_members as $st): ?>
                    <option value="<?= htmlspecialchars($st['id']) ?>"><?= htmlspecialchars($st['first_name'] . ' ' . $st['surname']) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="actions">
                <button type="submit" name="allocate_subject">Allocate Subject</button>
                <button type="button" class="secondary" onclick="resetForm()">Reset</button>
            </div>

            <div class="note">Subjects are loaded from the database by the <strong>semester</strong> value (the <code>semester</code> column in the <code>subjects</code> table).</div>
        </form>
    </div>

<script>
    const subjectsBySemester = <?= $subjects_json ?: '{}' ?>;
    const semesterSelect = document.getElementById('semester_select');
    const subjectSelect = document.getElementById('subject_select');

    // initialize
    resetSubjectSelect('-- First select a semester --');
    subjectSelect.disabled = true;

    console.log('subjectsBySemester:', subjectsBySemester); // debug, remove in production

    semesterSelect.addEventListener('change', function() {
        const raw = this.value;
        const key = (raw === 'unassigned') ? 'unassigned' : String(raw);

        resetSubjectSelect('-- Select a subject --');

        if (!key) {
            subjectSelect.disabled = true;
            return;
        }

        const list = subjectsBySemester[key];
        if (Array.isArray(list) && list.length) {
            list.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = (s.subject_code ? `${s.subject_code} - ` : '') + s.subject_name;
                subjectSelect.appendChild(opt);
            });
            subjectSelect.disabled = false;
        } else {
            // no subjects found
            resetSubjectSelect('-- No subjects found for this semester --');
            subjectSelect.disabled = true;
        }
    });

    function resetSubjectSelect(text) {
        subjectSelect.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = text;
        subjectSelect.appendChild(opt);
    }

    function resetForm() {
        document.getElementById('allocForm').reset();
        resetSubjectSelect('-- First select a semester --');
        subjectSelect.disabled = true;
    }

    // optional: prevent submit if subjectSelect disabled or empty
    document.getElementById('allocForm').addEventListener('submit', function(e) {
        if (subjectSelect.disabled || subjectSelect.value === '') {
            e.preventDefault();
            alert('Please choose a semester to load subjects and then select a subject.');
        }
    });
</script>
</body>
</html>
