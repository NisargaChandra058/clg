<?php
// TEMP: Enable for debugging - REMOVE IN PRODUCTION
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session_config.php';
session_start();

// Function to handle role-based redirects
function redirectByRole($role) {
    $role = strtolower(trim($role));
    switch ($role) {
        case 'admin':
            safe_redirect('admin-panel.php');
            break;
        case 'student':
            safe_redirect('student-dashboard.php');
            break;
        case 'staff':
            safe_redirect('staff-panel.php');
            break;
        case 'hod':
            safe_redirect('hod-panel.php');
            break;
        case 'principal':
            safe_redirect('principal-panel.php');
            break;
        default:
            safe_redirect('index.php');
            break;
    }
}

require_once __DIR__ . '/db.php';

function safe_redirect($url) {
    session_write_close();
    header("Location: $url");
    exit;
}

// TEMP DEBUG: Check if includes loaded
echo "<!-- DEBUG: Includes loaded successfully -->";

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    redirectByRole($_SESSION['role']);
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<!-- DEBUG: POST received -->";
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            if (!$pdo) {
                throw new Exception("Database connection failed.");
            }
            // Select only needed columns
            $stmt = $pdo->prepare("SELECT id, password, role, first_name, surname, branch FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<!-- DEBUG: User fetched: " . json_encode($user) . " -->";

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['surname'] ?? ''));
                $_SESSION['branch'] = $user['branch'] ?? 'N/A';

                redirectByRole($user['role']);
            } else {
                $error = "Invalid email or password.";
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System Error: Please try again later.";
        }
    }
}
?>
<!-- Rest of your HTML remains the same -->
