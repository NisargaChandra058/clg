<?php
/**
 * db.php
 * Database connection + Automatic Migrations for Neon (PostgreSQL)
 * * Features:
 * - Robust Transaction Handling (Fixes SQLSTATE[25P02])
 * - Idempotent Migrations (Can be run multiple times safely)
 * - Complete Schema Definition
 */

// =================================================================
// 1. DATABASE CREDENTIALS
// =================================================================
$database_url = "postgresql://neondb_owner:npg_STKDhH8lomb7@ep-steep-grass-a4zzp7i4-pooler.us-east-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require";

if (getenv('DATABASE_URL')) {
    $database_url = getenv('DATABASE_URL');
}

if (empty($database_url) || (strpos($database_url, 'postgres://') === false && strpos($database_url, 'postgresql://') === false)) {
    die("❌ Error: Invalid connection string. Please check db.php.");
}

// Parse connection string
$db_parts = parse_url($database_url);
$host = $db_parts['host'];
$port = $db_parts['port'] ?? '5432';
$dbname = ltrim($db_parts['path'], '/');
$user = $db_parts['user'];
$password = $db_parts['pass'];
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";

/**
 * Helper function to run migrations safely
 * Checks existence -> Starts Transaction -> Executes -> Logs -> Commits
 */
function run_migration($pdo, $migration_id, $sql) {
    // 1. Check if already run (Outside transaction to prevent poisoning)
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM db_migrations WHERE migration_id = ?");
        $stmt->execute([$migration_id]);
        if ($stmt->fetch()) {
            return; // Migration already applied
        }
    } catch (PDOException $e) {
        // Table might not exist yet, proceed to creation
    }

    // 2. Execute Migration
    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $log = $pdo->prepare("INSERT INTO db_migrations (migration_id) VALUES (?)");
        $log->execute([$migration_id]);
        $pdo->commit();
    } catch (PDOException $e) {
        // 3. Rollback if needed
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Ignore "Already Exists" errors (Idempotency)
        $safe_errors = ['42P07', '42701', '23505', '42710']; 
        if (in_array($e->getCode(), $safe_errors)) {
            // Log it as done since it already exists
            try {
                $log = $pdo->prepare("INSERT INTO db_migrations (migration_id) VALUES (?) ON CONFLICT DO NOTHING");
                $log->execute([$migration_id]);
            } catch (Exception $ex) { /* ignore */ }
        } else {
            // Critical Error
            die("❌ Migration Failed ($migration_id): " . $e->getMessage());
        }
    }
}

try {
    // =================================================================
    // 2. CONNECT TO DATABASE
    // =================================================================
    $pdo = new PDO($dsn, $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Disable emulation for better Postgres error reporting
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // =================================================================
    // 3. RUN MIGRATIONS
    // =================================================================

    // Setup Migrations Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_migrations (
        migration_id VARCHAR(255) PRIMARY KEY,
        run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    // --- CORE TABLES ---
    run_migration($pdo, 'create_table_semesters', "CREATE TABLE IF NOT EXISTS semesters (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE);");
    
    run_migration($pdo, 'create_table_users', "
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY, 
            first_name VARCHAR(100), 
            surname VARCHAR(100), 
            email VARCHAR(255) UNIQUE NOT NULL, 
            password VARCHAR(255) NOT NULL, 
            role VARCHAR(20) NOT NULL DEFAULT 'student'
        );
    ");

    run_migration($pdo, 'create_table_students', "CREATE TABLE IF NOT EXISTS students (id SERIAL PRIMARY KEY, student_id_text VARCHAR(20) UNIQUE);");
    
    run_migration($pdo, 'create_table_subjects', "CREATE TABLE IF NOT EXISTS subjects (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, subject_code VARCHAR(20) NOT NULL);");
    
    run_migration($pdo, 'create_table_classes', "CREATE TABLE IF NOT EXISTS classes (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, semester_id INT);");
    
    // --- ALLOCATION TABLES ---
    run_migration($pdo, 'create_table_subject_allocation', "CREATE TABLE IF NOT EXISTS subject_allocation (id SERIAL PRIMARY KEY, staff_id INT NOT NULL, subject_id INT NOT NULL);");
    
    run_migration($pdo, 'create_table_student_subject_allocation', "
        CREATE TABLE IF NOT EXISTS student_subject_allocation (
            id SERIAL PRIMARY KEY, 
            student_id INT NOT NULL REFERENCES students(id) ON DELETE CASCADE, 
            subject_id INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE, 
            UNIQUE(student_id, subject_id)
        );
    ");

    // --- EXAM & RESULT TABLES ---
    run_migration($pdo, 'create_table_question_papers', "CREATE TABLE IF NOT EXISTS question_papers (id SERIAL PRIMARY KEY, title VARCHAR(255) NOT NULL, content TEXT);");
    
    run_migration($pdo, 'create_table_test_allocation', "CREATE TABLE IF NOT EXISTS test_allocation (id SERIAL PRIMARY KEY, class_id INT NOT NULL, qp_id INT NOT NULL, UNIQUE(class_id, qp_id));");
    
    run_migration($pdo, 'create_table_ia_results', "CREATE TABLE IF NOT EXISTS ia_results (id SERIAL PRIMARY KEY, student_id INT, qp_id INT, marks INT, content TEXT, UNIQUE(student_id, qp_id));");

    // --- ATTENDANCE TABLES ---
    run_migration($pdo, 'create_table_attendance', "CREATE TABLE IF NOT EXISTS attendance (id SERIAL PRIMARY KEY, student_id INT, status VARCHAR(20));");
    run_migration($pdo, 'create_table_daily_attendance', "CREATE TABLE IF NOT EXISTS daily_attendance (id SERIAL PRIMARY KEY, student_id INT, date DATE);");
    
    // --- NOTIFICATION TABLE ---
    run_migration($pdo, 'create_table_notifications', "CREATE TABLE IF NOT EXISTS notifications (id SERIAL PRIMARY KEY, message TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);");

    // --- TIMETABLE TABLE ---
    run_migration($pdo, 'create_table_timetable', "
        CREATE TABLE IF NOT EXISTS timetable (
            id SERIAL PRIMARY KEY, 
            year INT, 
            semester INT, 
            branch VARCHAR(50), 
            day_of_week VARCHAR(20), 
            start_time TIME, 
            end_time TIME, 
            subject_id INT, 
            staff_id INT
        );
    ");

    // --- UPDATES (COLUMNS) ---
    
    // Students Table Columns
    run_migration($pdo, 'add_students_columns_batch_1', "
        ALTER TABLE students
        ADD COLUMN IF NOT EXISTS usn VARCHAR(20),
        ADD COLUMN IF NOT EXISTS student_name VARCHAR(255),
        ADD COLUMN IF NOT EXISTS email VARCHAR(255),
        ADD COLUMN IF NOT EXISTS semester INT,
        ADD COLUMN IF NOT EXISTS section VARCHAR(10);
    ");

    // Subjects Table Columns (CRITICAL FIX: Added 'semester' column)
    run_migration($pdo, 'add_subjects_columns_full', "
        ALTER TABLE subjects 
        ADD COLUMN IF NOT EXISTS semester_id INT,
        ADD COLUMN IF NOT EXISTS semester INT,
        ADD COLUMN IF NOT EXISTS branch VARCHAR(100),
        ADD COLUMN IF NOT EXISTS year INT;
    ");

    // Subject Allocation Columns
    run_migration($pdo, 'add_allocation_columns', "
        ALTER TABLE subject_allocation 
        ADD COLUMN IF NOT EXISTS class_id INT,
        ADD COLUMN IF NOT EXISTS section VARCHAR(10);
    ");

    // Question Paper Columns
    run_migration($pdo, 'add_qp_subject_id', "ALTER TABLE question_papers ADD COLUMN IF NOT EXISTS subject_id INT;");

    // --- CONSTRAINTS & INDEXES ---
    
    // Fix Subject Allocation Unique Constraint
    run_migration($pdo, 'fix_subject_allocation_constraint', "
        ALTER TABLE subject_allocation DROP CONSTRAINT IF EXISTS subject_allocation_staff_id_subject_id_key;
        ALTER TABLE subject_allocation ADD CONSTRAINT subject_allocation_staff_subject_class_key UNIQUE (staff_id, subject_id, class_id);
    ");

    run_migration($pdo, 'add_constraint_students_email', "ALTER TABLE students ADD CONSTRAINT students_email_unique UNIQUE (email);");
    run_migration($pdo, 'add_constraint_students_usn', "ALTER TABLE students ADD CONSTRAINT students_usn_unique UNIQUE (usn);");
    run_migration($pdo, 'add_constraint_subjects_code', "ALTER TABLE subjects ADD CONSTRAINT subjects_subject_code_key UNIQUE (subject_code);");

    // --- SEED DATA ---
    run_migration($pdo, 'seed_semesters', "
        INSERT INTO semesters (name) VALUES
        ('Semester 1'), ('Semester 2'), ('Semester 3'), ('Semester 4'),
        ('Semester 5'), ('Semester 6'), ('Semester 7'), ('Semester 8')
        ON CONFLICT (name) DO NOTHING;
    ");

} catch (PDOException $e) {
    die("❌ Database Connection Failed: " . $e->getMessage());
}
?>
