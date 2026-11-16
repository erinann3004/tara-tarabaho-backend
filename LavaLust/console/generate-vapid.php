<?php

function openssl_config_path(): ?string
{
    $configuredPath = getenv('OPENSSL_CONF');
    if ($configuredPath && is_file($configuredPath)) return str_replace('\\', '/', $configuredPath);

    $windowsConfig = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
    return is_file($windowsConfig) ? str_replace('\\', '/', $windowsConfig) : null;
}

$options = [
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
];
$opensslConfig = openssl_config_path();
if ($opensslConfig) $options['config'] = $opensslConfig;
$key = openssl_pkey_new($options);
if (!$key) {
    $errors = [];
    while ($error = openssl_error_string()) $errors[] = $error;
    fwrite(STDERR, "OpenSSL could not generate a P-256 key pair." . ($errors ? ' ' . implode('; ', $errors) : '') . "\n");
    exit(1);
}

$details = openssl_pkey_get_details($key);
$ec = $details['ec'] ?? null;
if (!is_array($ec) || empty($ec['x']) || empty($ec['y']) || empty($ec['d'])) {
    fwrite(STDERR, "OpenSSL did not return the expected elliptic-curve key material.\n");
    exit(1);
}

$base64Url = static function (string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};
$public = "\x04" . $ec['x'] . $ec['y'];
fwrite(STDOUT, "Add these values to LavaLust/.env and keep the private key secret:\n");
fwrite(STDOUT, "VAPID_PUBLIC_KEY=" . $base64Url($public) . "\n");
fwrite(STDOUT, "VAPID_PRIVATE_KEY=" . $base64Url($ec['d']) . "\n");
