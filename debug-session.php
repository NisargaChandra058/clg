<?php
// debug-session.php
// session_start() must be the VERY FIRST THING, before any HTML output
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Check Session Path
$savePath = session_save_path();
$writable = $savePath ? is_writable($savePath) : true; // Default/temp is usually writable

// 2. Increment Counter
if (!isset($_SESSION['test_count'])) {
    $_SESSION['test_count'] = 0;
}
$_SESSION['test_count']++;
?>
<!DOCTYPE html>
<html>
<head><title>Session Debug</title></head>
<body>
    <h1>Session Debugger</h1>
    
    <p>
        <strong>Session Save Path:</strong> 
        <?php echo $savePath ? htmlspecialchars($savePath) : "Default (System Temp)"; ?>
    </p>

    <?php if ($writable): ?>
        <p style="color:green; font-weight:bold;">✅ Session path is writable.</p>
    <?php else: ?>
        <p style="color:red; font-weight:bold;">❌ Error: Session path is NOT writable!</p>
    <?php endif; ?>

    <p>
        <strong>Session ID:</strong> <?php echo session_id(); ?><br>
        <strong>Test Count:</strong> <?php echo $_SESSION['test_count']; ?>
    </p>

    <hr>
    <h3>Instructions:</h3>
    <ol>
        <li>Refresh this page 3 times.</li>
        <li><strong>Working:</strong> The number goes 1, 2, 3, 4...</li>
        <li><strong>Broken:</strong> The number stays at 1.</li>
    </ol>
</body>
</html>
