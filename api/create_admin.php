<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run in a terminal.
");
    exit(1);
}

$email = trim((string)($argv[1] ?? ''));
$name = trim((string)($argv[2] ?? ''));
$password = $argv[3] ?? '';

if ($email === '' || $name === '' || strlen($password) < 8) {
    fwrite(STDERR, "Usage: php api/create_admin.php email full-name password
Password must be at least 8 characters.
");
    exit(1);
}

$pdo = db();
$roleId = $pdo->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
if (!$roleId) {
    fwrite(STDERR, "Admin role not found. Apply database-schema.sql first.
");
    exit(1);
}

$stmt = $pdo->prepare('INSERT INTO users (role_id, full_name, email, password_hash) VALUES (:role_id, :full_name, :email, :password_hash) RETURNING id');
$stmt->execute([
    'role_id' => $roleId,
    'full_name' => $name,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

fwrite(STDOUT, "Admin user created: {$stmt->fetchColumn()}
");
