<?php
session_start();
require_once('db.php'); // this gives you $pdo (PostgreSQL PDO connection)

// only admin can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';

// ---------- HANDLE CSV UPLOAD ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['student_csv'])) {
    $fileName = $_FILES['student_csv']['tmp_name'];

    if ($_FILES['student_csv']['size'] > 0) {
        $file = fopen($fileName, "r");
        $stmt = $pdo->prepare("
            INSERT INTO students (usn, student_name, email, password, dob, allotted_branch_management)
            VALUES (:usn, :student_name, :email, :password, :dob, :branch)
        ");

        $rowCount = 0;
        while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
            if ($rowCount === 0) { // skip header if present
                $rowCount++;
                continue;
            }
            $usn = trim($data[0]);
            $name = trim($data[1]);
            $email = trim($data[2]);
            $dob = !empty($data[3]) ? $data[3] : null;
            $branch = trim($data[4]);
            $password = password_hash($data[5], PASSWORD_DEFAULT);

            if (!empty($usn) && !empty($name) && !empty($email)) {
                $stmt->execute([
                    ':usn' => $usn,
                    ':student_name' => $name,
                    ':email' => $email,
                    ':password' => $password,
                    ':dob' => $dob,
                    ':branch' => $branch
                ]);
            }
            $rowCount++;
        }
        fclose($file);
        $message = "<p class='success-message'>✅ $rowCount students imported successfully!</p>";
    } else {
        $message = "<p class='error-message'>❌ Please upload a valid CSV file.</p>";
    }
}

// ---------- HANDLE MANUAL STUDENT ADD ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usn'])) {
    $usn       = trim($_POST['usn'] ?? '');
    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $dob       = $_POST['dob'] ?? null;
    $address   = trim($_POST['address'] ?? '');
    $branch    = trim($_POST['branch'] ?? '');
    $semester  = (int)($_POST['semester'] ?? 1);
    $password  = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);

    if ($usn && $name && $email) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO students (usn, student_name, email, password, dob, semester, allotted_branch_management)
                VALUES (:usn, :name, :email, :password, :dob, :semester, :branch)
            ");
            $stmt->execute([
                ':usn'      => $usn,
                ':name'     => $name,
                ':email'    => $email,
                ':password' => $password,
                ':dob'      => $dob,
                ':semester' => $semester,
                ':branch'   => $branch
            ]);
            $message = "<p class='success-message'>✅ Student added successfully!</p>";
        } catch (PDOException $e) {
            $message = "<p class='error-message'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        $message = "<p class='error-message'>❌ Please fill all required fields.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Student</title>
<style>
    body { font-family: Arial; background: #f4f7fc; }
    .content { width: 85%; margin: 30px auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .navbar { background: #333; padding: 10px; text-align: right; }
    .navbar a { color: white; text-decoration: none; margin: 0 10px; }
    h2 { text-align: center; }
    form { text-align: center; margin-top: 15px; }
    input, select, textarea, button { width: 80%; max-width: 400px; padding: 10px; margin: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; }
    button { background: #28a745; color: white; cursor: pointer; border: none; }
    button:hover { background: #218838; }
    .success-message { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; }
    .error-message { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; }
</style>
</head>
<body>

<div class="navbar">
    <a href="admin-panel.php">Back to Admin Panel</a>
</div>

<div class="content">
    <h2>Add Students</h2>
    <?= $message ?>

    <h3>📁 Upload CSV File</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="student_csv" accept=".csv" required><br>
        <button type="submit">Upload CSV</button>
    </form>

    <hr>

    <h3>✏️ Manually Add a Student</h3>
    <form method="POST">
        <input type="text" name="usn" placeholder="USN" required><br>
        <input type="text" name="name" placeholder="Full Name" required><br>
        <input type="email" name="email" placeholder="Email" required><br>
        <input type="date" name="dob"><br>
        <textarea name="address" placeholder="Address"></textarea><br>

        <label>Branch:</label>
        <select name="branch" required>
            <option value="CSE">CSE</option>
            <option value="ECE">ECE</option>
            <option value="MECH">MECH</option>
            <option value="CIVIL">CIVIL</option>
        </select><br>

        <label>Semester:</label>
        <select name="semester" required>
            <?php
            $semesters = $pdo->query("SELECT id, name FROM semesters ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($semesters as $sem) {
                echo "<option value='{$sem['id']}'>{$sem['name']}</option>";
            }
            ?>
        </select><br>

        <input type="password" name="password" placeholder="Password" required><br>
        <button type="submit">Add Student</button>
    </form>
</div>
</body>
</html>