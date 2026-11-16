<?php
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = realpath(__DIR__);
$requestedFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim(rawurldecode($requestPath), '/\\'));

if (
    $requestPath !== '/' &&
    $requestedFile !== false &&
    str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR) &&
    is_file($requestedFile)
) {
    return false;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';