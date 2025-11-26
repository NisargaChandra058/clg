<?php
session_start();
// 1. Correct the include path - assuming ia-results.php is in the root
include('db-config.php'); 

// Check if a student is logged in
if (!isset($_SESSION['student_id'])) {
    // Redirect to the correct student login page (adjust path if needed)
    header("Location: student/student-login.php"); 
    exit;
}

$student_id = $_SESSION['student_id'];
$results = []; // Initialize an empty array for results

try {
    // Prepare and execute the query using PDO
    $stmt = $conn->prepare("SELECT subject, marks FROM ia_results WHERE student_id = ? ORDER BY subject ASC");
    $stmt->execute([$student_id]);
    
    // Fetch all results
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Handle database errors
    die("Error: Could not retrieve IA results at this time. Please try again later. " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA Results</title>
    <style>
        /* Consistent Styling from student dashboard */
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            text-align: center; 
            background: #f4f7f6; 
            margin: 0;
            padding: 20px;
        }
        .container { 
            max-width: 800px; 
            margin: 40px auto; 
            background: white; 
            padding: 25px; 
            border-radius: 8px; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); 
        }
        h2 { 
            color: #333; 
            margin-bottom: 25px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 12px; 
            text-align: center; 
        }
        th { 
            background: #007bff; 
            color: white; 
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .back-link { 
            text-decoration: none; 
            color: white; 
            background: #6c757d; 
            padding: 10px 20px; 
            display: inline-block; 
            border-radius: 5px; 
            margin-top: 25px; 
            transition: background-color 0.3s;
        }
        .back-link:hover { 
            background: #5a6268; 
        }
        .no-records {
            color: #777;
            font-style: italic;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Internal Assessment (IA) Results</h2>
        <table>
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Marks Obtained</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="2" class="no-records">No IA results have been uploaded for you yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['subject']) ?></td>
                            <td><?= htmlspecialchars($row['marks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <!-- Adjust path if student dashboard is elsewhere -->
        <a href="student/student-dashboard.php" class="back-link">Back to Dashboard</a> 
    </div>
</body>
</html>
