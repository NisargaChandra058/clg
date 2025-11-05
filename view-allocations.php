<?php
session_start();
require_once('db.php'); // Use our PDO connection ($pdo)

// --- Security Check (Placeholder) ---
/*
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login");
    exit;
}
*/

try {
    /* --------------------------------------------
       DELETE ALLOCATION
    -------------------------------------------- */
    if (isset($_GET['delete_id'])) {
        $allocation_id = intval($_GET['delete_id']);
        
        $stmt = $pdo->prepare("DELETE FROM subject_allocation WHERE id = ?");
        $stmt->execute([$allocation_id]);

        header("Location: view-allocations.php?status=deleted");
        exit;
    }

    /* --------------------------------------------
       FETCH ALL ALLOCATIONS
    -------------------------------------------- */
    $alloc_stmt = $pdo->query("
        SELECT 
            sa.id, 
            u.first_name, 
            u.surname, 
            s.subject_code, 
            s.name as subject_name
        FROM 
            subject_allocation sa
        JOIN 
            users u ON sa.staff_id = u.id
        JOIN 
            subjects s ON sa.subject_id = s.id
        ORDER BY 
            u.first_name, s.subject_code
    ");
    $allocations = $alloc_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// For serial numbering
$current_sl_no = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Subject Allocations</title>
    <style>
        /* Reusing styles from view-students.php */
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto 20px auto;
            padding: 10px 20px;
            background: rgba(141, 153, 174, 0.1);
            border-radius: 10px;
        }
        .navbar h2 { margin: 0; }
        .navbar-links a {
            font-weight: bold;
            color: var(--antiflash-white);
            text-decoration: none;
            margin-left: 20px;
        }
        .navbar-links a:hover { text-decoration: underline; }

        .container { 
            width: 90%; 
            max-width: 1200px; 
            margin: 20px auto; 
            background: #fff; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); 
            color: var(--space-cadet);
        }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: var(--space-cadet); color: #fff; }
        tr:nth-child(even) { background-color: #f9f9f9; }

        .action-btn { 
            padding: 5px 10px; 
            border-radius: 4px; 
            text-decoration: none; 
            color: #fff; 
            margin-right: 5px; 
            font-size: 0.9em;
            display: inline-block;
        }
        .remove-btn { background-color: #dc3545; }
        .remove-btn:hover { background-color: #b52d3b; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>Subject Allocations</h2>
        <div class="navbar-links">
            <a href="/admin-panel.php">Back to Dashboard</a>
            <a href="/logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <table>
            <thead>
                <tr>
                    <th>Sl. No.</th>
                    <th>Staff Name</th>
                    <th>Subject Code</th>
                    <th>Subject Name</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allocations)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;">No subject allocations found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($allocations as $alloc): ?>
                        <tr>
                            <td><?= $current_sl_no++ ?></td>
                            <td><?= htmlspecialchars($alloc['first_name'] . ' ' . $alloc['surname']) ?></td>
                            <td><?= htmlspecialchars($alloc['subject_code']) ?></td>
                            <td><?= htmlspecialchars($alloc['subject_name']) ?></td>
                            <td>
                                <a href="?delete_id=<?= $alloc['id'] ?>" class="action-btn remove-btn" onclick="return confirm('Are you sure you want to remove this allocation?')">Remove</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
