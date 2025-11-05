<?php
// ----------------------------------------------
// Database Connection (Render or Local)
// ----------------------------------------------
$database_url = getenv('DATABASE_URL');

if ($database_url === false) {
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME') ?: 'admission_db';
    $user = getenv('DB_USER') ?: 'user';
    $password = getenv('DB_PASSWORD') ?: 'password';
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=prefer";
} else {
    $db_parts = parse_url($database_url);
    $host = $db_parts['host'];
    $port = $db_parts['port'] ?? '5432';
    $dbname = ltrim($db_parts['path'], '/');
    $user = $db_parts['user'];
    $password = $db_parts['pass'];
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
}

// ----------------------------------------------
// Migration Helper
// ----------------------------------------------
function run_migration(PDO $pdo, string $migration_id, string $sql) {
    try {
        // Check if already executed
        $stmt = $pdo->prepare("SELECT 1 FROM db_migrations WHERE migration_id = ?");
        $stmt->execute([$migration_id]);

        if ($stmt->fetch() === false) {
            $pdo->exec($sql);
            $log_stmt = $pdo->prepare("INSERT INTO db_migrations (migration_id) VALUES (?)");
            $log_stmt->execute([$migration_id]);
        }
    } catch (PDOException $e) {
        // Handle known duplicate errors safely
        // 42P07 = duplicate_table, 42701 = duplicate_column, 42710 = duplicate_object (constraint already exists)
        if (in_array($e->getCode(), ['42P07', '42701', '42710'])) {
            $log_stmt = $pdo->prepare("INSERT INTO db_migrations (migration_id) VALUES (?) ON CONFLICT (migration_id) DO NOTHING");
            $log_stmt->execute([$migration_id]);
        } else {
            die("Migration failed ($migration_id): " . $e->getMessage());
        }
    }
}

// ----------------------------------------------
// Database Setup and Migrations
// ----------------------------------------------
try {
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create migrations log table
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_migrations (
        migration_id VARCHAR(255) PRIMARY KEY,
        run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    // -------------------------------
    // 1. Core Tables
    // -------------------------------
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id SERIAL PRIMARY KEY,
        student_id_text VARCHAR(20) UNIQUE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        first_name VARCHAR(100),
        surname VARCHAR(100),
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'student'
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS semesters (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS classes (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        subject_code VARCHAR(20) UNIQUE NOT NULL
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subject_allocation (
        id SERIAL PRIMARY KEY,
        staff_id INT NOT NULL,
        subject_id INT NOT NULL,
        UNIQUE(staff_id, subject_id)
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS question_papers (
        id SERIAL PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS test_allocation (
        id SERIAL PRIMARY KEY,
        class_id INT NOT NULL,
        qp_id INT NOT NULL,
        UNIQUE(class_id, qp_id)
    );");

    // -------------------------------
    // 2. Add/Update Columns via Migrations
    // -------------------------------

    // --- Students Table Columns ---
    run_migration($pdo, 'add_students_usn', "ALTER TABLE students ADD COLUMN IF NOT EXISTS usn VARCHAR(20);");
    run_migration($pdo, 'add_students_student_name', "ALTER TABLE students ADD COLUMN IF NOT EXISTS student_name VARCHAR(255);");
    run_migration($pdo, 'add_students_dob', "ALTER TABLE students ADD COLUMN IF NOT EXISTS dob DATE;");
    run_migration($pdo, 'add_students_father_name', "ALTER TABLE students ADD COLUMN IF NOT EXISTS father_name VARCHAR(255);");
    run_migration($pdo, 'add_students_mother_name', "ALTER TABLE students ADD COLUMN IF NOT EXISTS mother_name VARCHAR(255);");
    run_migration($pdo, 'add_students_mobile_number', "ALTER TABLE students ADD COLUMN IF NOT EXISTS mobile_number VARCHAR(20);");
    run_migration($pdo, 'add_students_parent_mobile', "ALTER TABLE students ADD COLUMN IF NOT EXISTS parent_mobile_number VARCHAR(20);");
    run_migration($pdo, 'add_students_email', "ALTER TABLE students ADD COLUMN IF NOT EXISTS email VARCHAR(255);");
    run_migration($pdo, 'add_students_password', "ALTER TABLE students ADD COLUMN IF NOT EXISTS password VARCHAR(255);");
    run_migration($pdo, 'add_students_permanent_address', "ALTER TABLE students ADD COLUMN IF NOT EXISTS permanent_address TEXT;");
    run_migration($pdo, 'add_students_previous_college', "ALTER TABLE students ADD COLUMN IF NOT EXISTS previous_college VARCHAR(255);");
    run_migration($pdo, 'add_students_previous_combination', "ALTER TABLE students ADD COLUMN IF NOT EXISTS previous_combination VARCHAR(50);");
    run_migration($pdo, 'add_students_category', "ALTER TABLE students ADD COLUMN IF NOT EXISTS category VARCHAR(50);");
    run_migration($pdo, 'add_students_sub_caste', "ALTER TABLE students ADD COLUMN IF NOT EXISTS sub_caste VARCHAR(100);");
    run_migration($pdo, 'add_students_admission_through', "ALTER TABLE students ADD COLUMN IF NOT EXISTS admission_through VARCHAR(50);");
    run_migration($pdo, 'add_students_cet_number', "ALTER TABLE students ADD COLUMN IF NOT EXISTS cet_number VARCHAR(100);");
    run_migration($pdo, 'add_students_seat_allotted', "ALTER TABLE students ADD COLUMN IF NOT EXISTS seat_allotted VARCHAR(100);");
    run_migration($pdo, 'add_students_allotted_branch_kea', "ALTER TABLE students ADD COLUMN IF NOT EXISTS allotted_branch_kea VARCHAR(100);");
    run_migration($pdo, 'add_students_allotted_branch_mgmt', "ALTER TABLE students ADD COLUMN IF NOT EXISTS allotted_branch_management VARCHAR(100);");
    run_migration($pdo, 'add_students_cet_rank', "ALTER TABLE students ADD COLUMN IF NOT EXISTS cet_rank VARCHAR(50);");
    run_migration($pdo, 'add_students_photo_url', "ALTER TABLE students ADD COLUMN IF NOT EXISTS photo_url TEXT;");
    run_migration($pdo, 'add_students_marks_card_url', "ALTER TABLE students ADD COLUMN IF NOT EXISTS marks_card_url TEXT;");
    run_migration($pdo, 'add_students_aadhaar_front_url', "ALTER TABLE students ADD COLUMN IF NOT EXISTS aadhaar_front_url TEXT;");
    run_migration($pdo, 'add_students_aadhaar_back_url', "ALTER TABLE students ADD COLUMN IF NOT EXISTS aadhaar_back_url TEXT;");
    run_migration($pdo, 'add_students_caste_income_url', "ALTER TABLE students ADD COLUMN IF NOT EXISTS caste_income_url TEXT;");
    run_migration($pdo, 'add_students_submission_date', "ALTER TABLE students ADD COLUMN IF NOT EXISTS submission_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP;");
    run_migration($pdo, 'add_students_class_id', "ALTER TABLE students ADD COLUMN IF NOT EXISTS class_id INT;");
    run_migration($pdo, 'add_students_semester_id', "ALTER TABLE students ADD COLUMN IF NOT EXISTS semester_id INT;");

    // --- Other Tables ---
    run_migration($pdo, 'add_classes_semester_id', "ALTER TABLE classes ADD COLUMN IF NOT EXISTS semester_id INT;");
    run_migration($pdo, 'add_subjects_semester_id', "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS semester_id INT;");
    run_migration($pdo, 'add_qp_subject_id', "ALTER TABLE question_papers ADD COLUMN IF NOT EXISTS subject_id INT;");

    // -------------------------------
    // 3. Constraints & Foreign Keys
    // -------------------------------
    run_migration($pdo, 'add_constraint_students_email', "ALTER TABLE students ADD CONSTRAINT students_email_unique UNIQUE (email);");
    run_migration($pdo, 'add_constraint_students_usn', "ALTER TABLE students ADD CONSTRAINT students_usn_unique UNIQUE (usn);");

    // Foreign Keys (safe)
    run_migration($pdo, 'fk_classes_semester', "ALTER TABLE classes ADD CONSTRAINT fk_classes_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL;");
    run_migration($pdo, 'fk_students_class', "ALTER TABLE students ADD CONSTRAINT fk_students_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL;");
    run_migration($pdo, 'fk_students_semester', "ALTER TABLE students ADD CONSTRAINT fk_students_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL;");
    run_migration($pdo, 'fk_subjects_semester', "ALTER TABLE subjects ADD CONSTRAINT fk_subjects_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL;");

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>