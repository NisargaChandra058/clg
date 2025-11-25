<?php
session_start();
require_once 'db.php'; // PDO Connection

// 1. Authorization Check
// We use 'user_id' because that is what login.php sets
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id']; // This is the ID from the 'users' table
$student = null;
$attendance_stats = ['total' => 0, 'present' => 0, 'percentage' => 0];
$notifications = [];
$results = [];
$tests = [];

try {
    // 2. Fetch Student Details
    // We need to find the student record linked to this user email
    // First, get email from users table
    $stmt_user = $pdo->prepare("SELECT email FROM users WHERE id = :id");
    $stmt_user->execute(['id' => $user_id]);
    $user_email = $stmt_user->fetchColumn();

    if ($user_email) {
        // Now get student details using email
        $stmt = $pdo->prepare("SELECT * FROM students WHERE email = :email");
        $stmt->execute(['email' => $user_email]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$student) {
        // If student record missing in 'students' table
        die("Error: Student profile not found. Please contact Admin to sync your account.");
    }

    $student_id_academic = $student['id']; // This is the ID in 'students' table

    // 3. Fetch Notifications
    $notif_stmt = $pdo->query("SELECT message, created_at FROM notifications ORDER BY created_at DESC LIMIT 5");
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch IA Results
    $res_stmt = $pdo->prepare("
        SELECT s.name as subject_name, qp.title as test_title, ir.marks, ir.max_marks
        FROM ia_results ir
        JOIN question_papers qp ON ir.qp_id = qp.id
        LEFT JOIN subjects s ON qp.subject_id = s.id
        WHERE ir.student_id = :sid
        ORDER BY ir.created_at DESC
    ");
    $res_stmt->execute(['sid' => $student_id_academic]);
    $results = $res_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Fetch Assigned Tests (THE FIX)
    // We look in 'student_test_allocation', NOT 'test_allocation'
    // We also filter out tests that are already in 'ia_results' (completed)
    $test_stmt = $pdo->prepare("
        SELECT qp.id, qp.title, COALESCE(s.name, 'General') as subject_name
        FROM student_test_allocation sta
        JOIN question_papers qp ON sta.qp_id = qp.id
        LEFT JOIN subjects s ON qp.subject_id = s.id
        WHERE sta.student_id = :sid
        AND qp.id NOT IN (SELECT qp_id FROM ia_results WHERE student_id = :sid)
    ");
    $test_stmt->execute(['sid' => $student_id_academic]);
    $tests = $test_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Fetch Attendance Stats
    $att_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
        FROM attendance 
        WHERE student_id = :sid
    ");
    $att_stmt->execute(['sid' => $student_id_academic]);
    $att_data = $att_stmt->fetch(PDO::FETCH_ASSOC);

    if ($att_data && $att_data['total'] > 0) {
        $attendance_stats['total'] = $att_data['total'];
        $attendance_stats['present'] = $att_data['present'];
        $attendance_stats['percentage'] = round(($att_data['present'] / $att_data['total']) * 100);
    }

} catch (PDOException $e) {
    die("Dashboard Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <style>
        :root { --bg: #f4f7f6; --sidebar: #2b2d42; --active: #3b82f6; --text: #333; --card: #fff; }
        body { font-family: 'Segoe UI', sans-serif; margin: 0; background: var(--bg); display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: 250px; background: var(--sidebar); color: white; display: flex; flex-direction: column; padding: 20px; position: fixed; height: 100%; }
        .sidebar h2 { margin-bottom: 30px; text-align: center; color: #edf2f4; font-size: 1.5rem; }
        .sidebar a { text-decoration: none; color: #8d99ae; padding: 12px; margin: 5px 0; border-radius: 5px; transition: 0.3s; font-weight: 500; display: block; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.1); color: white; }
        .logout { margin-top: auto; color: #ef233c !important; }

        /* Main Content */
        .main { flex: 1; padding: 30px; margin-left: 250px; }
        
        /* Header */
        .header-card { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2); margin-bottom: 30px; }
        .header-card h1 { margin: 0; font-size: 24px; }
        .header-card p { opacity: 0.9; margin-top: 5px; }

        /* Grid Layout */
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        
        /* Cards */
        .card { background: var(--card); padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; color: var(--sidebar); font-size: 1.1rem; }
        
        /* Profile Details */
        .profile-row { display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px dashed #eee; padding-bottom: 5px; }
        .profile-label { color: #777; font-size: 0.9em; }
        .profile-val { font-weight: 600; color: #333; }

        /* Notifications */
        .notif-item { background: #fffbe6; border-left: 4px solid #ffc107; padding: 10px; margin-bottom: 10px; border-radius: 4px; font-size: 0.95rem; }
        .notif-date { font-size: 0.8em; color: #888; display: block; margin-top: 4px; }

        /* Attendance Circle */
        .att-circle { width: 100px; height: 100px; border-radius: 50%; background: conic-gradient(#22c55e <?= $attendance_stats['percentage'] ?>%, #eee 0); margin: 20px auto; display: flex; align-items: center; justify-content: center; position: relative; }
        .att-circle::before { content: '<?= $attendance_stats['percentage'] ?>%'; position: absolute; background: white; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.4em; color: #333; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 0.9rem; }
        th { color: #555; font-weight: 600; }
        
        .btn-take { background: #3b82f6; color: white; padding: 5px 10px; text-decoration: none; border-radius: 4px; font-size: 0.85rem; }
        .btn-take:hover { background: #2563eb; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; flex-direction: row; overflow-x: auto; }
            .main { margin-left: 0; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>Student Portal</h2>
        <a href="#" class="active">Dashboard</a>
        <a href="#">My Subjects</a>
        <a href="#">Timetable</a>
        <a href="logout.php" class="logout">Logout</a>
    </div>

    <div class="main">
        <div class="header-card">
            <h1>Welcome back, <?= htmlspecialchars($student['student_name']) ?>!</h1>
            <p>Semester <?= htmlspecialchars($student['semester']) ?> | <?= htmlspecialchars($student['usn']) ?></p>
        </div>

        <div class="grid">
            
            <!-- 1. Profile Card -->
            <div class="card">
                <h3>My Profile</h3>
                <div class="profile-row"><span class="profile-label">USN</span> <span class="profile-val"><?= htmlspecialchars($student['usn']) ?></span></div>
                <div class="profile-row"><span class="profile-label">Email</span> <span class="profile-val"><?= htmlspecialchars($student['email']) ?></span></div>
                <div class="profile-row"><span class="profile-label">DOB</span> <span class="profile-val"><?= htmlspecialchars($student['dob']) ?></span></div>
                <div class="profile-row"><span class="profile-label">Semester</span> <span class="profile-val"><?= htmlspecialchars($student['semester']) ?></span></div>
            </div>

            <!-- 2. Attendance Card -->
            <div class="card">
                <h3>Attendance Overview</h3>
                <div class="att-circle"></div>
                <p style="text-align:center; color:#666; font-weight:500;">
                    <?= $attendance_stats['present'] ?> / <?= $attendance_stats['total'] ?> Classes Attended
                </p>
            </div>

            <!-- 3. Pending Tests -->
            <div class="card">
                <h3>📝 Pending Tests</h3>
                <?php if (empty($tests)): ?>
                    <p style="color:#999; font-style:italic; padding:10px;">No pending tests.</p>
                <?php else: ?>
                    <table>
                        <tr><th>Subject</th><th>Test</th><th>Action</th></tr>
                        <?php foreach ($tests as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['subject_name']) ?></td>
                            <td><?= htmlspecialchars($t['title']) ?></td>
                            <td><a href="take-test.php?id=<?= $t['id'] ?>" class="btn-take">Start</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <!-- 4. Recent Results -->
            <div class="card">
                <h3>🏆 Recent Results</h3>
                <?php if (empty($results)): ?>
                    <p style="color:#999; font-style:italic; padding:10px;">No results yet.</p>
                <?php else: ?>
                    <table>
                        <tr><th>Subject</th><th>Marks</th><th>Max</th></tr>
                        <?php foreach ($results as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['subject_name']) ?></td>
                            <td style="font-weight:bold; color:#333;"><?= htmlspecialchars($r['marks']) ?></td>
                            <td style="color:#777;"><?= htmlspecialchars($r['max_marks']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <!-- 5. Notifications -->
            <div class="card" style="grid-column: 1 / -1;">
                <h3>📢 Announcements</h3>
                <?php if (empty($notifications)): ?>
                    <p style="color:#999; font-style:italic;">No new notifications.</p>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <div class="notif-item">
                            <?= htmlspecialchars($notif['message']) ?>
                            <span class="notif-date"><?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

</body>
</html>
