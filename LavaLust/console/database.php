<?php

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This database bootstrap is for CLI commands only.');
}

defined('PREVENT_DIRECT_ACCESS') OR define('PREVENT_DIRECT_ACCESS', true);
defined('ROOT_DIR') OR define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
defined('SYSTEM_DIR') OR define('SYSTEM_DIR', ROOT_DIR . 'scheme' . DIRECTORY_SEPARATOR);
defined('APP_DIR') OR define('APP_DIR', ROOT_DIR . 'app' . DIRECTORY_SEPARATOR);

$environmentFile = ROOT_DIR . '.env';
if (!is_file($environmentFile)) {
    throw new RuntimeException('The LavaLust .env file is missing.');
}

foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);
    if (strlen($value) > 1 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
        $value = substr($value, 1, -1);
    }

    if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER) && getenv($key) === false) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

require_once SYSTEM_DIR . 'kernel' . DIRECTORY_SEPARATOR . 'Registry.php';
require_once SYSTEM_DIR . 'kernel' . DIRECTORY_SEPARATOR . 'Routine.php';

return load_class('database', 'database');