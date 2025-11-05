<?php
/**
 * db.php
 * Database connection + automatic migrations
 * ✅ Works with Neon / Render (PostgreSQL) or local Docker
 */

$database_url = getenv('DATABASE_URL');

if ($database_url === false) {
    // Local development fallback
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '5432';
    $dbname = getenv('DB_NAME') ?: 'admission_db';
    $user = getenv('DB_USER') ?: 'user';
    $password = getenv('DB_PASSWORD') ?: 'password';
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=prefer";
} else {
    // Cloud connection (Render / Neon)
    $parts = parse_url($database_url);
    $host = $parts['host'];
    $port = $parts['port'] ?? '5432';
    $dbname = ltrim($parts['path'], '/');
    $user = $parts['user'];
    $password = $parts['pass'];
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
}

/**
 * Run a single migration safely.
 */
function run_migration(PDO $pdo, string $id, string $sql): void {
    try {
        $pdo->beginTransaction();

        // Migration history table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS db_migrations (
                migration_id VARCHAR(255) PRIMARY KEY,
                run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Skip if migration already exists
        $stmt = $pdo->prepare("SELECT 1 FROM db_migrations WHERE migration_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetch() === false) {
            $pdo->exec($sql);
            $insert = $pdo->prepare("INSERT INTO db_migrations (migration_id) VALUES (?)");
            $insert->execute([$id]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Ignore known duplicates (column/table/constraint exists)
        if (!in_array($e->getCode(), ['42701', '42P07', '23505'])) {
            die("Migration failed ($id): " . $e->getMessage());
        }
    }
}

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    /* =========================================================
       BASE TABLES
    ========================================================= */

    // 1️⃣ Semesters
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS semesters (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE
        );
    ");

    // 2️⃣ Classes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS classes (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            semester_id INT REFERENCES semesters(id)
        );
    ");

    // 3️⃣ Students
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS students (
            id SERIAL PRIMARY KEY,
            usn VARCHAR(20) UNIQUE,
            student_name VARCHAR(255),
            email VARCHAR(255) UNIQUE,
            password VARCHAR(255),
            dob DATE,
            semester INT,                -- ✅ Semester column used by add-student.php
            class_id INT REFERENCES classes(id)
        );
    ");

    // 4️⃣ Users (admins, staff)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            first_name VARCHAR(100),
            surname VARCHAR(100),
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'student'
        );
    ");

    // 5️⃣ Subjects (Option 1 — branch/semester/year fields)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subjects (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            subject_code VARCHAR(20) UNIQUE NOT NULL
        );
    ");

    run_migration($pdo, 'add_subjects_branch', "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS branch VARCHAR(100);");
    run_migration($pdo, 'add_subjects_semester', "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS semester INT;");
    run_migration($pdo, 'add_subjects_year', "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS year INT;");

    // 6️⃣ Subject Allocation
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subject_allocation (
            id SERIAL PRIMARY KEY,
            staff_id INT NOT NULL REFERENCES users(id),
            subject_id INT NOT NULL REFERENCES subjects(id),
            UNIQUE (staff_id, subject_id)
        );
    ");

    // 7️⃣ Question Papers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_papers (
            id SERIAL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT,
            subject_id INT REFERENCES subjects(id)
        );
    ");

    // 8️⃣ Test Allocation
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS test_allocation (
            id SERIAL PRIMARY KEY,
            class_id INT NOT NULL REFERENCES classes(id),
            qp_id INT NOT NULL REFERENCES question_papers(id),
            UNIQUE (class_id, qp_id)
        );
    ");

    /* =========================================================
       EXTRA STUDENT DETAILS
    ========================================================= */
    run_migration($pdo, 'add_students_father_name', "ALTER TABLE students ADD COLUMN IF NOT EXISTS father_name VARCHAR(255);");
    run_migration($pdo, 'add_students_mother_name', "ALTER TABLE students ADD COLUMN IF NOT EXISTS mother_name VARCHAR(255);");
    run_migration($pdo, 'add_students_mobile_number', "ALTER TABLE students ADD COLUMN IF NOT EXISTS mobile_number VARCHAR(20);");
    run_migration($pdo, 'add_students_parent_mobile', "ALTER TABLE students ADD COLUMN IF NOT EXISTS parent_mobile_number VARCHAR(20);");
    run_migration($pdo, 'add_students_category', "ALTER TABLE students ADD COLUMN IF NOT EXISTS category VARCHAR(50);");
    run_migration($pdo, 'add_students_branch_kea', "ALTER TABLE students ADD COLUMN IF NOT EXISTS allotted_branch_kea VARCHAR(100);");
    run_migration($pdo, 'add_students_branch_mgmt', "ALTER TABLE students ADD COLUMN IF NOT EXISTS allotted_branch_management VARCHAR(100);");

    /* =========================================================
       DEFAULT SEMESTERS (1 → 8)
    ========================================================= */
    $count = (int)$pdo->query("SELECT COUNT(*) FROM semesters")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("INSERT INTO semesters (name) VALUES (?)");
        for ($i = 1; $i <= 8; $i++) {
            $stmt->execute(["Semester $i"]);
        }
    }

} catch (PDOException $e) {
    die("❌ Database connection failed: " . htmlspecialchars($e->getMessage()));
}
?>