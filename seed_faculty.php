<?php
// seed_faculty.php — Run once from terminal: php seed_faculty.php
// Creates default faculty accounts. Change passwords immediately after!

require_once __DIR__ . '/db.php';
$pdo = getDB();

$faculty = [
    ['Prof. Ana Cruz',      'prof.cruz@cics.edu.ph',  'Faculty@2026'],
    ['Dean Jose Santos',    'dean@cics.edu.ph',        'Dean@CICS26'],
    ['Admin CICS',          'admin@cics.edu.ph',       'Admin@CICS26'],
];

echo "=== CICS SRMS — Faculty Seeder ===\n\n";
foreach ($faculty as [$name, $email, $pass]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('INSERT IGNORE INTO users (full_name, email, password, role) VALUES (?,?,?,?)');
    $stmt->execute([$name, $email, $hash, 'faculty']);
    if ($stmt->rowCount() > 0) {
        echo "✓ Created: $email  (password: $pass)\n";
    } else {
        echo "  Skipped: $email  (already exists)\n";
    }
}
echo "\nDone! Change these passwords after first login.\n";
