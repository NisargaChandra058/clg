<?php
// debug-session.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Check Session Path
$savePath = session_save_path();
echo "<h1>Session Debug</h1>";
echo "<strong>Session Save Path:</strong> " . ($savePath ? $savePath : "Default (System Temp)") . "<br>";

if ($savePath && !is_writable($savePath)) {
    echo "<strong style='color:red'>❌ Error: Session path is NOT writable!</strong><br>";
} else {
    echo "<strong style='color:green'>✅ Session path is writable.</strong><br>";
}

// 2. Try to Set a Session
session_start();
if (!isset($_SESSION['test_count'])) {
    $_SESSION['test_count'] = 0;
}
$_SESSION['test_count']++;

echo "<strong>Session ID:</strong> " . session_id() . "<br>";
echo "<strong>Test Count:</strong> " . $_SESSION['test_count'] . "<br>";
echo "<p>Refresh this page. If 'Test Count' increases (1, 2, 3...), sessions are working.</p>";
echo "<p>If it stays at 1, sessions are BROKEN on your server.</p>";
?>
