<?php

function load_project_env(string $path): void
{
    if (!is_file($path)) {
        fwrite(STDERR, "Missing LavaLust/.env. Copy .env.example and configure the database first.\n");
        exit(1);
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) > 1 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if (!getenv($key)) putenv($key . '=' . $value);
    }
}

load_project_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
$email = strtolower(trim((string) getenv('TARA_ADMIN_EMAIL')));
$name = trim((string) getenv('TARA_ADMIN_NAME'));
$password = (string) getenv('TARA_ADMIN_PASSWORD');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12) {
    fwrite(STDERR, "Set TARA_ADMIN_EMAIL, TARA_ADMIN_NAME, and a TARA_ADMIN_PASSWORD of at least 12 characters.\n");
    exit(1);
}

$database = getenv('DB_NAME') ?: '';

if ($database !== 'tara_trabaho') {
    fwrite(STDERR, "Database settings must target tara_trabaho in LavaLust/.env.\n");
    exit(1);
}

$nameParts = preg_split('/\s+/', $name, 2);
$firstName = $nameParts[0] ?? '';
$lastName = $nameParts[1] ?? '';
if (strlen($firstName) > 100 || strlen($lastName) > 100) {
    fwrite(STDERR, "The administrator's first and last names must each be 100 characters or fewer.\n");
    exit(1);
}

try {
    $db = require __DIR__ . DIRECTORY_SEPARATOR . 'database.php';
    $db->table('Users')->insert([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
    ]);
    fwrite(STDOUT, "Administrator account created for " . $email . ".\n");
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "That email is already registered. No account was changed.\n");
        exit(1);
    }
    fwrite(STDERR, "Could not create the administrator account: " . $exception->getMessage() . "\n");
    exit(1);
}
