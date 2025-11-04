<?php
// session_start(); // Enable if using sessions
require_once('db.php'); // Use PDO connection

// --- Security Check (Placeholder) ---
/*
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') { // Assuming staff or admin
    header("Location: login.php");
    exit;
}
*/

$message = ''; // For success/error messages

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $qp_id = filter_input(INPUT_POST, 'qp_id', FILTER_VALIDATE_INT);

    if ($class_id && $qp_id) {
        try {
            // PostgreSQL-compatible query to insert if not already exists
            $sql = "INSERT INTO test_allocation (class_id, qp_id) 
                    VALUES (:class_id, :qp_id) 
                    ON CONFLICT (class_id, qp_id) DO NOTHING";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':class_id' => $class_id, ':qp_id' => $qp_id]);

            if ($stmt->rowCount() > 0) {
                $message = "<p class='message success'>Test allocated successfully!</p>";
            } else {
                $message = "<p class='message error'>This test is already allocated to this class.</p>";
            }
        } catch (PDOException $e) {
            $message = "<p class='message error'>Database error: " . $e->getMessage() . "</p>";
        }
    } else {
        $message = "<p class='message error'>Invalid input. Please select a class and a question paper.</p>";
    }
}

// Fetch classes and question papers for the dropdowns
try {
    $class_stmt = $pdo->query("SELECT id, name FROM classes ORDER BY name");
    $classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);

    $qp_stmt = $pdo->query("SELECT id, title FROM question_papers ORDER BY title");
    $question_papers = $qp_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error fetching data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Test to Class</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Reusing styles from admin.php */
        :root { --space-cadet: #2b2d42; --cool-gray: #8d99ae; --antiflash-white: #edf2f4; --red-pantone: #ef233c; --fire-engine-red: #d90429; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: var(--space-cadet); color: var(--antiflash-white); }
        .back-link { display: block; max-width: 860px; margin: 0 auto 20px auto; text-align: right; font-weight: bold; color: var(--antiflash-white); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .container { max-width: 600px; margin: 20px auto; padding: 30px; background: rgba(141, 153, 174, 0.1); border-radius: 15px; border: 1px solid rgba(141, 153, 174, 0.2); }
        h2 { text-align: center; margin-bottom: 20px; }
        form { display: flex; flex-direction: column; gap: 10px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        select { width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 5px; border: 1px solid var(--cool-gray); background: rgba(43, 45, 66, 0.5); color: var(--antiflash-white); box-sizing: border-box; }
        button { padding: 12px 20px; border: none; border-radius: 5px; background-color: var(--fire-engine-red); color: var(--antiflash-white); font-weight: bold; cursor: pointer; width: 100%; font-size: 1.1em; margin-top: 10px; }
        button:hover { background-color: var(--red-pantone); }
        .message { padding: 10px; border-radius: 5px; margin-bottom: 1em; text-align: center; font-weight: bold; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <a href="/admin" class="back-link">&laquo; Back to Admin Dashboard</a>
    <div class="container">
        <h2>Assign Test to Class</h2>
        <?php if (!empty($message)) echo $message; ?>

        <form action="assign-test.php" method="POST">
            <label for="class_id">Select Class:</label>
            <select name="class_id" id="class_id" required>
                <option value="">-- Select a Class --</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= htmlspecialchars($class['id']) ?>">
                        <?= htmlspecialchars($class['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="qp_id">Select Question Paper:</label>
            <select name="qp_id" id="qp_id" required>
                <option value="">-- Select a Question Paper --</option>
                <?php foreach ($question_papers as $qp): ?>
                    <option value="<?= htmlspecialchars($qp['id']) ?>">
                        <?= htmlspecialchars($qp['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Assign Test</button>
        </form>
    </div>
</body>
</html>