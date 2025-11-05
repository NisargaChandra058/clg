<?php
session_start();
include('db-config.php'); // Must return a PDO connection ($conn)

// Ensure only admins can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

try {
    /* --------------------------------------------
       DELETE STUDENT + RELATED RECORDS
    -------------------------------------------- */
    if (isset($_GET['delete_student'])) {
        $student_id = intval($_GET['delete_student']);
        $conn->beginTransaction();

        // Delete related records (maintain foreign key integrity)
        $conn->prepare("DELETE FROM attendance WHERE student_id = ?")->execute([$student_id]);
        $conn->prepare("DELETE FROM ia_results WHERE student_id = ?")->execute([$student_id]);
        $conn->prepare("DELETE FROM assigned_tests WHERE student_id = ?")->execute([$student_id]);
        $conn->prepare("DELETE FROM daily_attendance WHERE student_id = ?")->execute([$student_id]);

        // Delete main record
        $conn->prepare("DELETE FROM students WHERE id = ?")->execute([$student_id]);

        $conn->commit();
        header("Location: view-students.php?status=deleted");
        exit;
    }

    /* --------------------------------------------
       PAGINATION + FILTER LOGIC
    -------------------------------------------- */
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $records_per_page = 10;
    $start_from = ($page - 1) * $records_per_page;

    // Optional semester filter
    $selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;

    // Count total students
    if ($selected_semester > 0) {
        $total_stmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE semester = :sem");
        $total_stmt->execute([':sem' => $selected_semester]);
    } else {
        $total_stmt = $conn->prepare("SELECT COUNT(*) FROM students");
        $total_stmt->execute();
    }
    $total_students = $total_stmt->fetchColumn();
    $total_pages = ceil($total_students / $records_per_page);

    // Fetch students
    if ($selected_semester > 0) {
        $student_stmt = $conn->prepare("
            SELECT id, usn, student_name AS name, email, semester 
            FROM students 
            WHERE semester = :sem 
            ORDER BY id ASC 
            LIMIT :limit OFFSET :offset
        ");
        $student_stmt->bindValue(':sem', $selected_semester, PDO::PARAM_INT);
    } else {
        $student_stmt = $conn->prepare("
            SELECT id, usn, student_name AS name, email, semester 
            FROM students 
            ORDER BY id ASC 
            LIMIT :limit OFFSET :offset
        ");
    }

    $student_stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $student_stmt->bindValue(':offset', $start_from, PDO::PARAM_INT);
    $student_stmt->execute();

    $students = $student_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// For serial numbering
$current_sl_no = $start_from + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Students</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; margin: 0; padding: 0; }
        .navbar { background-color: #007bff; padding: 1em; display: flex; justify-content: flex-end; gap: 1em; }
        .navbar a { color: #fff; text-decoration: none; padding: 0.5em 1em; border-radius: 5px; transition: background 0.3s ease; }
        .navbar a:hover { background-color: #0056b3; }
        .container { width: 90%; max-width: 1200px; margin: 20px auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h2 { color: #444; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; }
        form.filter { margin-bottom: 15px; text-align: right; }
        select { padding: 6px 10px; font-size: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; vertical-align: middle; }
        th { background-color: #007bff; color: #fff; }
        td:last-child { text-align: center; }
        .pagination { margin: 15px 0; text-align: center; }
        .pagination a { margin: 0 5px; padding: 6px 12px; border: 1px solid #007bff; border-radius: 4px; color: #007bff; text-decoration: none; }
        .pagination a.active { background-color: #007bff; color: #fff; }
        .action-btn { display: inline-block; padding: 6px 10px; border-radius: 4px; text-decoration: none; color: #fff; font-size: 14px; margin: 0 2px; transition: background 0.3s ease; }
        .edit-btn { background-color: #28a745; }
        .edit-btn:hover { background-color: #218838; }
        .remove-btn { background-color: #dc3545; }
        .remove-btn:hover { background-color: #b52d3b; }
        @media (max-width: 600px) {
            table { font-size: 13px; }
            .action-btn { padding: 5px 8px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <a href="admin-panel.php">Back to Dashboard</a>
        <a href="logout.php">Logout</a>
    </div>

    <div class="container">
        <h2>All Registered Students</h2>

        <!-- Semester Filter -->
        <form class="filter" method="get" action="">
            <label for="semester">Filter by Semester:</label>
            <select name="semester" id="semester" onchange="this.form.submit()">
                <option value="0">All Semesters</option>
                <?php for ($i = 1; $i <= 8; $i++): ?>
                    <option value="<?= $i ?>" <?= ($selected_semester == $i) ? 'selected' : '' ?>>Semester <?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>

        <!-- Students Table -->
        <table>
            <thead>
                <tr>
                    <th>Sl. No.</th>
                    <th>USN</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Semester</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= $current_sl_no ?></td>
                            <td><?= htmlspecialchars($student['usn'] ?? '') ?></td>
                            <td><?= htmlspecialchars($student['name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($student['email'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string)($student['semester'] ?? '-')) ?></td>
                            <td>
                                <a href="edit-student.php?id=<?= $student['id'] ?>" class="action-btn edit-btn">Edit</a>
                                <a href="?delete_student=<?= $student['id'] ?>" class="action-btn remove-btn" onclick="return confirm('Are you sure you want to delete this student?')">Remove</a>
                            </td>
                        </tr>
                        <?php $current_sl_no++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?= $i ?>&semester=<?= $selected_semester ?>" <?= ($i === $page) ? 'class="active"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
</body>
</html>