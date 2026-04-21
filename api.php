<?php
// api.php — CICS SRMS v3  (Role-Based REST API)
// ─────────────────────────────────────────────
// POST actions: login | logout | student_register | faculty_register |
//               create | update | delete | unlock
// GET  resources: students | student | activity

require_once __DIR__ . '/db.php';

define('FACULTY_CODE', 'CICS-FACULTY-2026');   // Change this to your secret

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Helpers ───────────────────────────────────────────────────────────────────
function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function validateStudent(array $d): ?string {
    if (empty(trim($d['full_name']  ?? ''))) return 'Full name is required.';
    if (empty(trim($d['student_no'] ?? ''))) return 'Student number is required.';
    if (empty(trim($d['email']      ?? ''))) return 'Email is required.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) return 'Invalid email address.';
    $programs = ['BS Information Technology','BS Information Systems'];
    if (!in_array($d['program'] ?? '', $programs, true)) return 'Invalid program selected.';
    $yr = intval($d['year_level'] ?? 0);
    if ($yr < 1 || $yr > 4) return 'Year level must be between 1 and 4.';
    $validStatus = ['Active','Inactive','Graduated','LOA'];
    if (!empty($d['status']) && !in_array($d['status'], $validStatus, true)) return 'Invalid status.';
    $validSem = ['1st','2nd','Summer'];
    if (!empty($d['semester']) && !in_array($d['semester'], $validSem, true)) return 'Invalid semester.';
    if (!empty($d['birthday'])) {
        if (!DateTime::createFromFormat('Y-m-d', $d['birthday'])) return 'Invalid birthday format.';
    }
    return null;
}

function studentFields(array $d): array {
    return [
        ':sno'     => trim($d['student_no']),
        ':name'    => trim($d['full_name']),
        ':email'   => strtolower(trim($d['email'])),
        ':prog'    => $d['program'],
        ':yr'      => intval($d['year_level']),
        ':sec'     => trim($d['section']     ?? ''),
        ':sy'      => trim($d['school_year'] ?? ''),
        ':sem'     => in_array($d['semester'] ?? '', ['1st','2nd','Summer']) ? $d['semester'] : '1st',
        ':stat'    => in_array($d['status']   ?? '', ['Active','Inactive','Graduated','LOA']) ? $d['status'] : 'Active',
        ':contact' => trim($d['contact_no']  ?? ''),
        ':bday'    => !empty($d['birthday'])  ? $d['birthday'] : null,
        ':addr'    => trim($d['address']     ?? ''),
        ':photo'   => !empty($d['photo'])     ? $d['photo'] : null,
    ];
}

function logActivity(PDO $pdo, string $action, ?int $sid, ?string $sno, ?string $sname, string $by = '', string $details = ''): void {
    try {
        $pdo->prepare('INSERT INTO activity_log (action,student_id,student_no,student_name,performed_by,details) VALUES (?,?,?,?,?,?)')
            ->execute([$action, $sid, $sno, $sname, $by, $details]);
    } catch (Exception $e) {}
}

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ══════════════════════════════════════════════════════════════════════════════
// GET
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $resource = $_GET['resource'] ?? '';

    if ($resource === 'students') {
        $where  = ['1=1'];
        $params = [];
        if (!empty($_GET['search'])) {
            $where[]  = '(full_name LIKE :search OR student_no LIKE :search OR email LIKE :search OR section LIKE :search)';
            $params[':search'] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['program'])) { $where[] = 'program=:program'; $params[':program'] = $_GET['program']; }
        if (!empty($_GET['year']))    { $where[] = 'year_level=:year'; $params[':year']    = intval($_GET['year']); }
        if (!empty($_GET['status']))  { $where[] = 'status=:status';   $params[':status']  = $_GET['status']; }
        if (!empty($_GET['school_year'])) { $where[] = 'school_year=:sy'; $params[':sy'] = $_GET['school_year']; }

        $cols = 'id,student_no,full_name,email,program,year_level,section,school_year,semester,status,contact_no,birthday,address,is_locked,user_id,created_at,updated_at';
        $stmt = $pdo->prepare("SELECT $cols FROM students WHERE ".implode(' AND ',$where).' ORDER BY id DESC');
        $stmt->execute($params);
        $students = $stmt->fetchAll();

        $stats = $pdo->query("SELECT
            COUNT(*) AS total,
            SUM(program='BS Information Technology') AS bsit,
            SUM(program='BS Information Systems')    AS bsis,
            SUM(status='Active')    AS active,
            SUM(status='Inactive')  AS inactive,
            SUM(status='Graduated') AS graduated,
            SUM(status='LOA')       AS loa,
            SUM(year_level=1) AS yr1, SUM(year_level=2) AS yr2,
            SUM(year_level=3) AS yr3, SUM(year_level=4) AS yr4,
            SUM(is_locked=1) AS self_registered
        FROM students")->fetch();

        respond(['success'=>true,'students'=>$students,'stats'=>$stats]);
    }

    if ($resource === 'student') {
        $id   = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM students WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $s = $stmt->fetch();
        if (!$s) respond(['success'=>false,'error'=>'Student not found.'],404);
        respond(['success'=>true,'student'=>$s]);
    }

    if ($resource === 'activity') {
        $logs = $pdo->query('SELECT * FROM activity_log ORDER BY performed_at DESC LIMIT 100')->fetchAll();
        respond(['success'=>true,'logs'=>$logs]);
    }

    respond(['error'=>'Unknown resource.'],400);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $data['action'] ?? '';

    // ── LOGIN ─────────────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = strtolower(trim($data['email']    ?? ''));
        $password = $data['password'] ?? '';
        if (!$email || !$password) respond(['success'=>false,'error'=>'Email and password required.'],422);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password']))
            respond(['success'=>false,'error'=>'Invalid email or password.'],401);

        respond(['success'=>true,'user'=>[
            'id'         => $user['id'],
            'full_name'  => $user['full_name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'student_id' => $user['student_id'],
            'program'    => $user['program'],
        ]]);
    }

    // ── STUDENT SELF-REGISTRATION ──────────────────────────────────────────────
    if ($action === 'student_register') {
        $err = validateStudent($data);
        if ($err) respond(['success'=>false,'error'=>$err],422);

        $password = $data['password'] ?? '';
        $confirm  = $data['confirm_password'] ?? '';
        if (strlen($password) < 8)
            respond(['success'=>false,'error'=>'Password must be at least 8 characters.'],422);
        if ($password !== $confirm)
            respond(['success'=>false,'error'=>'Passwords do not match.'],422);

        $pdo->beginTransaction();
        try {
            $f = studentFields($data);
            $pdo->prepare(
                'INSERT INTO students (student_no,full_name,email,program,year_level,section,school_year,semester,status,contact_no,birthday,address,photo,is_locked)
                 VALUES (:sno,:name,:email,:prog,:yr,:sec,:sy,:sem,:stat,:contact,:bday,:addr,:photo,1)'
            )->execute($f);
            $studentId = $pdo->lastInsertId();

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('INSERT INTO users (full_name,email,password,role,student_id,program) VALUES (?,?,?,?,?,?)')
                ->execute([trim($data['full_name']), strtolower(trim($data['email'])), $hash, 'student', $studentId, $data['program']]);
            $userId = $pdo->lastInsertId();

            $pdo->prepare('UPDATE students SET user_id=? WHERE id=?')->execute([$userId,$studentId]);
            $pdo->commit();

            logActivity($pdo,'SELF-REGISTER',$studentId,trim($data['student_no']),trim($data['full_name']),trim($data['full_name']),'Student self-registered');

            respond(['success'=>true,'message'=>'Registration successful! Your profile has been locked.','user'=>[
                'id'         => $userId,
                'full_name'  => trim($data['full_name']),
                'email'      => strtolower(trim($data['email'])),
                'role'       => 'student',
                'student_id' => $studentId,
                'program'    => $data['program'],
            ]],201);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = str_contains($e->getMessage(),'Duplicate') ? 'Student number or email already exists.' : 'Database error.';
            respond(['success'=>false,'error'=>$msg],409);
        }
    }

    // ── FACULTY REGISTRATION ───────────────────────────────────────────────────
    if ($action === 'faculty_register') {
        $name     = trim($data['full_name']    ?? '');
        $email    = strtolower(trim($data['email']  ?? ''));
        $password = $data['password']          ?? '';
        $code     = $data['faculty_code']      ?? '';

        if (!$name||!$email||!$password||!$code)
            respond(['success'=>false,'error'=>'All fields are required.'],422);
        if ($code !== FACULTY_CODE)
            respond(['success'=>false,'error'=>'Invalid faculty registration code.'],403);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL))
            respond(['success'=>false,'error'=>'Invalid email address.'],422);
        if (strlen($password) < 8)
            respond(['success'=>false,'error'=>'Password must be at least 8 characters.'],422);

        try {
            $hash = password_hash($password,PASSWORD_BCRYPT);
            $pdo->prepare('INSERT INTO users (full_name,email,password,role) VALUES (?,?,?,?)')->execute([$name,$email,$hash,'faculty']);
            $id = $pdo->lastInsertId();
            logActivity($pdo,'FACULTY-REGISTER',null,null,$name,$name,'Faculty account created');
            respond(['success'=>true,'message'=>'Faculty account created!','user'=>[
                'id'=>$id,'full_name'=>$name,'email'=>$email,'role'=>'faculty','student_id'=>null,'program'=>null
            ]],201);
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(),'Duplicate') ? 'This email is already registered.' : 'Database error.';
            respond(['success'=>false,'error'=>$msg],409);
        }
    }

    // ── CREATE (faculty) ──────────────────────────────────────────────────────
    if ($action === 'create') {
        $err = validateStudent($data);
        if ($err) respond(['success'=>false,'error'=>$err],422);
        try {
            $f = studentFields($data);
            $pdo->prepare(
                'INSERT INTO students (student_no,full_name,email,program,year_level,section,school_year,semester,status,contact_no,birthday,address,photo,is_locked)
                 VALUES (:sno,:name,:email,:prog,:yr,:sec,:sy,:sem,:stat,:contact,:bday,:addr,:photo,0)'
            )->execute($f);
            $id  = $pdo->lastInsertId();
            $row = $pdo->prepare('SELECT * FROM students WHERE id=?'); $row->execute([$id]); $row=$row->fetch();
            logActivity($pdo,'CREATE',$id,$row['student_no'],$row['full_name'],$data['faculty_name']??'Faculty',"Added by faculty");
            respond(['success'=>true,'message'=>'Student added successfully.','student'=>$row],201);
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(),'Duplicate') ? 'Student number or email already exists.' : 'Database error.';
            respond(['success'=>false,'error'=>$msg],409);
        }
    }

    // ── UPDATE (faculty; blocks locked records from student) ─────────────────
    if ($action === 'update') {
        $id   = intval($data['id'] ?? 0);
        $role = $data['requester_role'] ?? 'faculty';
        if (!$id) respond(['success'=>false,'error'=>'Student ID is required.'],400);

        // Lock enforcement
        $lock = $pdo->prepare('SELECT is_locked FROM students WHERE id=?');
        $lock->execute([$id]);
        $lr = $lock->fetch();
        if ($lr && $lr['is_locked'] && $role !== 'faculty')
            respond(['success'=>false,'error'=>'This profile is locked and cannot be edited.'],403);

        $err = validateStudent($data);
        if ($err) respond(['success'=>false,'error'=>$err],422);

        try {
            $f = studentFields($data);
            $photoClause = '';
            if (!empty($data['photo'])) { $photoClause = ', photo=:photo'; }
            else { unset($f[':photo']); }

            $pdo->prepare(
                "UPDATE students SET student_no=:sno,full_name=:name,email=:email,program=:prog,year_level=:yr,
                 section=:sec,school_year=:sy,semester=:sem,status=:stat,contact_no=:contact,birthday=:bday,address=:addr
                 $photoClause WHERE id=:id"
            )->execute(array_merge($f,[':id'=>$id]));

            logActivity($pdo,'UPDATE',$id,trim($data['student_no']),trim($data['full_name']),$data['faculty_name']??'Faculty','Record updated');
            respond(['success'=>true,'message'=>'Student updated successfully.']);
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(),'Duplicate') ? 'Student number or email already exists.' : 'Database error.';
            respond(['success'=>false,'error'=>$msg],409);
        }
    }

    // ── DELETE (faculty) ──────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = intval($data['id'] ?? 0);
        if (!$id) respond(['success'=>false,'error'=>'Student ID is required.'],400);
        $r = $pdo->prepare('SELECT student_no,full_name,user_id FROM students WHERE id=?');
        $r->execute([$id]); $s=$r->fetch();
        $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$id]);
        // Also remove linked user account if any
        if ($s && $s['user_id']) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$s['user_id']]);
        if ($s) logActivity($pdo,'DELETE',$id,$s['student_no'],$s['full_name'],$data['faculty_name']??'Faculty','Record deleted');
        respond(['success'=>true,'message'=>'Student deleted successfully.']);
    }

    // ── UNLOCK (faculty) ──────────────────────────────────────────────────────
    if ($action === 'unlock') {
        $id = intval($data['id'] ?? 0);
        if (!$id) respond(['success'=>false,'error'=>'Student ID is required.'],400);
        $pdo->prepare('UPDATE students SET is_locked=0 WHERE id=?')->execute([$id]);
        $r=$pdo->prepare('SELECT student_no,full_name FROM students WHERE id=?'); $r->execute([$id]); $s=$r->fetch();
        if ($s) logActivity($pdo,'UNLOCK',$id,$s['student_no'],$s['full_name'],$data['faculty_name']??'Faculty','Record unlocked by faculty');
        respond(['success'=>true,'message'=>'Student record unlocked.']);
    }

    respond(['error'=>'Unknown action.'],400);
}

respond(['error'=>'Method not allowed.'],405);
