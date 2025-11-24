<?php
session_start();
require_once('db.php'); // PDO connection ($pdo)

// Optional: Ensure only admin access
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

    // Basic validation
    if (empty($subjects) || empty($branch) || !$semester || !$year) {
        $feedback_message = "<div class='message error'>❌ Please fill in all fields, including at least one subject.</div>";
    } else {
        try {
            $pdo->beginTransaction();

            // Note: Ensure your DB has columns: name, subject_code, branch, semester_id, year
            // Changed :semester to :semester_id based on previous context, change back if using 'semester' column
            $sql = "
                INSERT INTO subjects (name, subject_code, branch, semester_id, year)
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
                        ':subject_name' => $subject_name,
                        ':subject_code' => $subject_code,
                        ':branch'       => $branch,
                        ':semester'     => $semester, // or semester_id
                        ':year'         => $year
                    ]);

                    if ($stmt->rowCount() > 0) {
                        $added_count++;
                    }
                }
            }

            $pdo->commit();
            $feedback_message = "<div class='message success'>✅ $added_count subject(s) added successfully!</div>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $feedback_message = "<div class='message error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Fetch semesters for dropdown
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
        :root {
            --bg-dark: #2b2d42;
            --card-bg: #3a3d55;
            --input-bg: #454860;
            --text-light: #edf2f4;
            --text-muted: #8d99ae;
            --primary: #d90429;
            --primary-hover: #ef233c;
            --success: #2a9d8f;
            --error: #ef233c;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            padding: 40px 20px;
            line-height: 1.6;
        }

        /* Header & Back Link */
        .header-area {
            max-width: 800px;
            margin: 0 auto 20px;
            display: flex;
            justify-content: flex-end;
        }

        .back-link {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: var(--text-light);
            text-decoration: underline;
        }

        /* Main Container */
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: var(--card-bg);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
        }

        h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-light);
            border-bottom: 2px solid var(--primary);
            display: inline-block;
            padding-bottom: 10px;
            width: 100%;
        }

        /* Messages */
        .message {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 25px;
        }
        .success { background-color: rgba(42, 157, 143, 0.2); color: #4ecdc4; border: 1px solid var(--success); }
        .error { background-color: rgba(239, 35, 60, 0.2); color: #ffadad; border: 1px solid var(--error); }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-light);
        }

        input[type="text"], 
        input[type="number"], 
        select {
            width: 100%;
            padding: 12px 15px;
            background-color: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-light);
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        input:focus, 
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(217, 4, 41, 0.2);
        }

        /* Subject Repeater Styling */
        .subjects-section-title {
            margin-top: 30px;
            margin-bottom: 15px;
            font-size: 1.2rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .subject-form {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            position: relative;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }

        .remove-btn:hover {
            color: var(--primary);
        }

        /* Action Buttons */
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        button {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.1s, background-color 0.3s;
        }

        button:active {
            transform: scale(0.98);
        }

        .btn-secondary {
            background-color: transparent;
            border: 2px solid var(--text-muted);
            color: var(--text-light);
        }

        .btn-secondary:hover {
            border-color: var(--text-light);
            background-color: rgba(255,255,255,0.05);
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            box-shadow: 0 4px 12px rgba(217, 4, 41, 0.4);
        }

        /* Mobile Responsiveness */
        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }
            .btn-group {
                flex-direction: column;
            }
            .subject-form {
                padding-top: 35px; /* Make room for X button */
            }
        }
    </style>
</head>
<body>

    <div class="header-area">
        <a href="admin-panel.php" class="back-link">&laquo; Back to Admin Dashboard</a>
    </div>

    <div class="container">
        <h2>Add New Subjects (Bulk)</h2>
        
        <?= $feedback_message ?>

        <form action="add-subject.php" method="POST">
            <div class="form-group">
                <label for="branch">Branch:</label>
                <select name="branch" id="branch" required>
                    <option value="">-- Select a Branch --</option>
                    <option value="CSE">Computer Science (CSE)</option>
                    <option value="ECE">Electronics (ECE)</option>
                    <option value="MECH">Mechanical (MECH)</option>
                    <option value="CIVIL">Civil (CIVIL)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="semester">Semester:</label>
                <select name="semester" id="semester" required>
                    <option value="">-- Select Semester --</option>
                    <?php foreach ($semesters as $sem): ?>
                        <option value="<?= htmlspecialchars($sem['id']) ?>">
                            <?= htmlspecialchars($sem['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="year">Year (1-4):</label>
                <input type="number" name="year" id="year" min="1" max="4" required placeholder="e.g. 1">
            </div>

            <h3 class="subjects-section-title">Subjects List</h3>
            
            <div id="subject-form-container">
                <!-- Initial Subject Item -->
                <div class="subject-form">
                    <!-- No remove button for the first one to ensure at least one exists -->
                    <div class="form-group">
                        <label>Subject Name:</label>
                        <input type="text" name="subjects[0][subject_name]" required placeholder="e.g. Mathematics I">
                    </div>
                    <div class="form-group">
                        <label>Subject Code:</label>
                        <input type="text" name="subjects[0][subject_code]" required placeholder="e.g. MAT101">
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="btn-secondary" onclick="addSubjectForm()">+ Add Another Subject</button>
                <button type="submit" class="btn-primary">Save All Subjects</button>
            </div>
        </form>
    </div>

    <script>
        let subjectCount = 1;

        function addSubjectForm() {
            const container = document.getElementById('subject-form-container');
            const newForm = document.createElement('div');
            newForm.classList.add('subject-form');
            
            newForm.innerHTML = `
                <button type="button" class="remove-btn" onclick="this.parentElement.remove()" title="Remove Subject">×</button>
                <div class="form-group">
                    <label>Subject Name:</label>
                    <input type="text" name="subjects[${subjectCount}][subject_name]" required placeholder="e.g. Physics">
                </div>
                <div class="form-group">
                    <label>Subject Code:</label>
                    <input type="text" name="subjects[${subjectCount}][subject_code]" required placeholder="e.g. PHY102">
                </div>
            `;
            
            container.appendChild(newForm);
            subjectCount++;
        }
    </script>
</body>
</html>
