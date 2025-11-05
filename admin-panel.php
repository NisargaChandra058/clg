<?php
session_start();
// 1. Include the correct PDO database connection
include('db-config.php'); 

// Ensure only admins can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

try {
    // --- USER MANAGEMENT LOGIC ---

    // Handle user deletion safely with prepared statements
    if (isset($_GET['delete_user'])) {
        $user_id = intval($_GET['delete_user']); 

        $conn->beginTransaction();

        // Step 1: Remove subject allocations for this user
        $stmt = $conn->prepare("DELETE FROM subject_allocation WHERE staff_id = ?");
        $stmt->execute([$user_id]);

        // Step 2: Now delete the user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);

        $conn->commit();

        header("Location: admin_dashboard.php");
        exit;
    }

    // --- SUBJECT MANAGEMENT LOGIC ---

    // Handle subject deletion
    if (isset($_GET['delete_subject'])) {
        $subject_id = intval($_GET['delete_subject']);

        $conn->beginTransaction();

        // Step 1: Remove any allocations of this subject
        $stmt = $conn->prepare("DELETE FROM subject_allocation WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        
        // Step 2: Remove related question papers
        $stmt_qp = $conn->prepare("DELETE FROM question_papers WHERE subject_id = ?");
        $stmt_qp->execute([$subject_id]);

        // Step 3: Remove related questions
        $stmt_q = $conn->prepare("DELETE FROM questions WHERE subject_id = ?");
        $stmt_q->execute([$subject_id]);
        
        // Step 4: Remove related timetable entries (handle potential NULL subject_id)
        $stmt_tt = $conn->prepare("UPDATE timetables SET subject_id = NULL WHERE subject_id = ?");
        $stmt_tt->execute([$subject_id]);

        // Step 5: Delete the subject itself
        $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
        $stmt->execute([$subject_id]);

        $conn->commit();

        header("Location: admin_dashboard.php");
        exit;
    }


    // --- DATA FETCHING FOR DISPLAY ---

    // User Pagination
    $user_page = isset($_GET['user_page']) ? (int)$_GET['user_page'] : 1;
    $records_per_page = 5; // Reduced for better viewing on one screen
    $user_start_from = ($user_page - 1) * $records_per_page;

    // Fetch total user count (excluding admins)
    $total_users_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role != 'admin'");
    $total_users_stmt->execute();
    $total_users = $total_users_stmt->fetchColumn();
    $total_user_pages = ceil($total_users / $records_per_page);

    // Fetch user details for the current page
    $user_stmt = $conn->prepare("SELECT id, first_name, surname, branch, email, created_at, role FROM users WHERE role != 'admin' ORDER BY id ASC LIMIT :limit OFFSET :offset");
    $user_stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $user_stmt->bindValue(':offset', $user_start_from, PDO::PARAM_INT);
    $user_stmt->execute();
    $users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Subject Pagination
    $subject_page = isset($_GET['subject_page']) ? (int)$_GET['subject_page'] : 1;
    $subject_start_from = ($subject_page - 1) * $records_per_page;

    // Fetch total subject count
    $total_subjects_stmt = $conn->prepare("SELECT COUNT(*) FROM subjects");
    $total_subjects_stmt->execute();
    $total_subjects = $total_subjects_stmt->fetchColumn();
    $total_subject_pages = ceil($total_subjects / $records_per_page);

    // Fetch subject details for the current page
    $subject_stmt = $conn->prepare("SELECT id, name, subject_code, branch, semester, year FROM subjects ORDER BY id ASC LIMIT :limit OFFSET :offset");
    $subject_stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $subject_stmt->bindValue(':offset', $subject_start_from, PDO::PARAM_INT);
    $subject_stmt->execute();
    $subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);


} catch (PDOException $e) {
    // If any database error occurs, stop the script and show a generic error
    die("Database error: " . $e->getMessage());
}

// --- Initialize Serial Numbers for display ---
$current_user_sl_no = $user_start_from + 1;
$current_subject_sl_no = $subject_start_from + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f9; color: #333; }
        .navbar { background-color: #007bff; padding: 1em; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 1em; } /* Added flex-wrap */
        .navbar a { color: #fff; text-decoration: none; padding: 0.5em 1em; border-radius: 5px; transition: background-color 0.3s ease; white-space: nowrap; } /* Added nowrap */
        .navbar a:hover { background-color: #0056b3; }
        .container { width: 90%; max-width: 1200px; margin: 20px auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h2 { color: #444; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 1em; table-layout: fixed; /* Helps with consistent column width */ word-wrap: break-word; /* Prevents long text overflow */ }
        th, td { border: 1px solid #ddd; padding: 0.75em; text-align: left; }
        th { background-color: #007bff; color: #fff; }
        .pagination { margin: 1em 0; text-align: center; padding: 10px 0; }
        .pagination a { margin: 0 3px; text-decoration: none; padding: 5px 10px; border: 1px solid #007bff; border-radius: 5px; color: #007bff; transition: background-color 0.3s, color 0.3s; }
        .pagination a.active { background-color: #007bff; color: #fff; border-color: #007bff; }
        .pagination a:hover:not(.active) { background-color: #e9ecef; color: #0056b3; } /* Subtle hover */
        .action-btn { text-decoration: none; padding: 5px 10px; border-radius: 5px; font-size: 14px; color: white; margin-right: 5px; display: inline-block; transition: opacity 0.3s; }
        .action-btn:hover { opacity: 0.8; }
        .edit-btn { background-color: #28a745; }
        .remove-btn { background-color: #dc3545; }
        td { vertical-align: middle; } /* Align content vertically */
        /* Responsive Table */
        @media screen and (max-width: 768px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { border: 1px solid #ccc; margin-bottom: 5px; }
            td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 50%; white-space: normal; text-align:right; }
            td:before { position: absolute; top: 6px; left: 6px; width: 45%; padding-right: 10px; white-space: nowrap; text-align:left; font-weight: bold; }
            /* Label the data */
            td:nth-of-type(1):before { content: "Sl. No."; }
            td:nth-of-type(2):before { content: "Name"; }
            td:nth-of-type(3):before { content: "Branch"; }
            td:nth-of-type(4):before { content: "Email"; }
            td:nth-of-type(5):before { content: "Joining Date"; }
            td:nth-of-type(6):before { content: "Role"; }
            td:nth-of-type(7):before { content: "Actions"; }
            
            /* Subject Table Specific Labels */
            .subject-table td:nth-of-type(1):before { content: "Sl. No."; }
            .subject-table td:nth-of-type(2):before { content: "Subject Name"; }
            .subject-table td:nth-of-type(3):before { content: "Subject Code"; }
            .subject-table td:nth-of-type(4):before { content: "Branch"; }
            .subject-table td:nth-of-type(5):before { content: "Semester"; }
            .subject-table td:nth-of-type(6):before { content: "Year"; }
            .subject-table td:nth-of-type(7):before { content: "Actions"; }

            .pagination a { padding: 8px 12px; } /* Slightly larger touch targets */
        }
    </style>
</head>
<body>
    <div class="navbar">
        <a href="add-staff.php">Add Staff</a>
        <a href="add-student.php">Add Student</a>
        <a href="view-students.php">View Students</a>
        <a href="add-subject.php">Add Subjects</a>
        <a href="subject-allocation.php">Subjects Allocation</a>
        <a href="view-allocations.php">View Allocation</a>
        <a href="create-question.php">Generate Paper</a>
        <a href="logout.php">Logout</a>
    </div>

    <div class="container">
        <h2>Admin Dashboard</h2>
        
        <!-- Users Section -->
        <h2>All Users (Excluding Admins)</h2>
        <table>
            <thead>
                <tr>
                    <th>Sl. No.</th>
                    <th>Name</th>
                    <th>Branch</th>
                    <th>Email</th>
                    <th>Joining Date</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $current_user_sl_no ?></td>
                        <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['surname']) ?></td>
                        <td><?= htmlspecialchars($user['branch'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars(date('d-m-Y', strtotime($user['created_at']))) ?></td>
                        <td><?= htmlspecialchars(ucfirst($user['role'])) ?></td>
                        <td>
                            <a href="edit-staff.php?id=<?= $user['id'] ?>" class="action-btn edit-btn">Edit</a>
                            <a href="?delete_user=<?= $user['id'] ?>" class="action-btn remove-btn" onclick="return confirm('Are you sure you want to delete this user? This cannot be undone.')">Remove</a>
                        </td>
                    </tr>
                <?php $current_user_sl_no++; ?>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center;">No users found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- User Pagination -->
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_user_pages; $i++): ?>
                <a href="?user_page=<?= $i ?>&subject_page=<?= $subject_page /* Preserve subject page */ ?>" <?= $i === $user_page ? 'class="active"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
        </div>

        <hr style="margin: 40px 0;">

        <!-- Subjects Section -->
        <h2>All Subjects</h2>
        <table class="subject-table"> <!-- Added class for responsive CSS targeting -->
            <thead>
                <tr>
                    <th>Sl. No.</th>
                    <th>Subject Name</th>
                    <th>Subject Code</th>
                    <th>Branch</th>
                    <th>Semester</th>
                    <th>Year</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><?= $current_subject_sl_no ?></td>
                        <td><?= htmlspecialchars($subject['name']) ?></td>
                        <td><?= htmlspecialchars($subject['subject_code']) ?></td>
                        <td><?= htmlspecialchars($subject['branch']) ?></td>
                        <td><?= htmlspecialchars($subject['semester']) ?></td>
                        <td><?= htmlspecialchars($subject['year']) ?></td>
                        <td>
                            <a href="edit-subject.php?id=<?= $subject['id'] ?>" class="action-btn edit-btn">Edit</a>
                            <a href="?delete_subject=<?= $subject['id'] ?>" class="action-btn remove-btn" onclick="return confirm('Are you sure you want to delete this subject? This cannot be undone.')">Remove</a>
                        </td>
                    </tr>
                <?php $current_subject_sl_no++; ?>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center;">No subjects found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Subject Pagination -->
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_subject_pages; $i++): ?>
                <a href="?subject_page=<?= $i ?>&user_page=<?= $user_page /* Preserve user page */ ?>" <?= $i === $subject_page ? 'class="active"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
</body>
</html>
