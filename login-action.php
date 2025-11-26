<?php
// Start session and include your PDO database connection
session_start();
require_once 'db-config.php';

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure we have a PDO connection in $conn (map common variable names)
if (!isset($conn)) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $conn = $pdo;
    } elseif (isset($dbh) && $dbh instanceof PDO) {
        $conn = $dbh;
    }
}

// Handle POST request
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validate that fields exist
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $input_password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (!$email) {
        $_SESSION['error'] = 'Please provide a valid email address.';
        header("Location: login.php");
        exit;
    }

    if ($input_password === '') {
        $_SESSION['error'] = 'Please provide your password.';
        header("Location: login.php");
        exit;
    }

    // Ensure DB connection exists
    if (!isset($conn) || !($conn instanceof PDO)) {
        error_log("Database connection not found in db-config.php. Expected \$conn or \$pdo or \$dbh.");
        $_SESSION['error'] = 'Server configuration error. Please contact the administrator.';
        header("Location: login.php");
        exit;
    }

    try {
        $query = "SELECT id, email, password, role, branch FROM users WHERE email = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Get the stored

