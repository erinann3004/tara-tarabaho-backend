<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Cloudinary_service
{
    public function isConfigured(): bool
    {
        return $this->env('CLOUDINARY_CLOUD_NAME') !== ''
            && $this->env('CLOUDINARY_API_KEY') !== ''
            && $this->env('CLOUDINARY_API_SECRET') !== '';
    }

    public function upload(string $filePath, string $publicId, string $resourceType, string $deliveryType): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Cloudinary is not configured.');
        }
        if (!in_array($resourceType, ['image', 'raw'], true) || !in_array($deliveryType, ['upload', 'authenticated'], true)) {
            throw new InvalidArgumentException('Invalid Cloudinary resource or delivery type.');
        }
        if (!is_file($filePath)) {
            throw new RuntimeException('The upload file is unavailable.');
        }

        $timestamp = time();
        $params = [
            'access_mode' => $deliveryType === 'authenticated' ? 'authenticated' : 'public',
            'public_id' => $publicId,
            'timestamp' => $timestamp,
            'type' => $deliveryType,
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);
        if (!is_string($mime)) {
            throw new RuntimeException('The upload file type could not be determined.');
        }
        $fields = $params + [
            'api_key' => $this->env('CLOUDINARY_API_KEY'),
            'signature' => $this->apiSignature($params),
            'file' => new CURLFile($filePath, $mime, basename($publicId)),
        ];

        $response = $this->request(
            'POST',
            $this->apiUrl($resourceType, 'upload'),
            $fields
        );
        if (!isset($response['public_id'], $response['secure_url'], $response['version'])) {
            throw new RuntimeException('Cloudinary returned incomplete upload metadata.');
        }

        return [
            'public_id' => (string) $response['public_id'],
            'asset_id' => (string) ($response['asset_id'] ?? ''),
            'secure_url' => (string) $response['secure_url'],
            'resource_type' => $resourceType,
            'delivery_type' => $deliveryType,
            'version' => (int) $response['version'],
        ];
    }

    public function delete(string $publicId, string $resourceType, string $deliveryType): void
    {
        if (!$this->isConfigured() || $publicId === '') {
            return;
        }
        $params = [
            'invalidate' => 'true',
            'public_id' => $publicId,
            'timestamp' => time(),
            'type' => $deliveryType,
        ];
        $fields = $params + [
            'api_key' => $this->env('CLOUDINARY_API_KEY'),
            'signature' => $this->apiSignature($params),
        ];
        $this->request('POST', $this->apiUrl($resourceType, 'destroy'), $fields);
    }

    public function authenticatedUrl(string $publicId, string $resourceType, int $version): string
    {
        if (!$this->isConfigured() || $publicId === '' || $version < 1) {
            throw new RuntimeException('The private Cloudinary asset metadata is incomplete.');
        }

        $path = 'v' . $version . '/' . $publicId;
        $digest = base64_encode(sha1($path . $this->env('CLOUDINARY_API_SECRET'), true));
        $signature = substr(strtr(rtrim($digest, '='), '+/', '-_'), 0, 8);

        return 'https://res.cloudinary.com/' . rawurlencode($this->env('CLOUDINARY_CLOUD_NAME'))
            . '/' . $resourceType . '/authenticated/s--' . $signature . '--/' . $path;
    }

    public function downloadAuthenticated(string $publicId, string $resourceType, int $version): string
    {
        $curl = curl_init($this->authenticatedUrl($publicId, $resourceType, $version));
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the private Cloudinary download.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
        ];
        $caFile = $this->caBundlePath();
        if ($caFile !== '') {
            $options[CURLOPT_CAINFO] = $caFile;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Private Cloudinary download failed: ' . $curlError);
        }
        if ($status !== 200) {
            throw new RuntimeException('Cloudinary private download returned HTTP ' . $status . '.');
        }

        return $body;
    }

    private function request(string $method, string $url, array $fields): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the Cloudinary request.');
        }
        $options = [
            CURLOPT_POST => $method === 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $fields,
        ];
        $caFile = $this->caBundlePath();
        if ($caFile !== '') {
            $options[CURLOPT_CAINFO] = $caFile;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Cloudinary request failed: ' . $curlError);
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? 'Cloudinary request failed.') : 'Cloudinary returned invalid data.';
            throw new RuntimeException('Cloudinary returned HTTP ' . $status . ': ' . mb_substr((string) $message, 0, 300));
        }
        if (($decoded['result'] ?? null) === 'not found') {
            throw new RuntimeException('The Cloudinary asset was not found.');
        }

        return $decoded;
    }

    private function apiSignature(array $params): string
    {
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return sha1(implode('&', $parts) . $this->env('CLOUDINARY_API_SECRET'));
    }

    private function apiUrl(string $resourceType, string $action): string
    {
        return 'https://api.cloudinary.com/v1_1/'
            . rawurlencode($this->env('CLOUDINARY_CLOUD_NAME'))
            . '/' . $resourceType . '/' . $action;
    }

    private function caBundlePath(): string
    {
        $candidates = [
            getenv('CURL_CA_BUNDLE'),
            getenv('SSL_CERT_FILE'),
            'C:/Program Files/Git/usr/ssl/cert.pem',
            'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
            'C:/Program Files/Git/usr/ssl/certs/ca-bundle.trust.crt',
            'C:/wamp64/bin/php/php8.0.30/extras/ssl/cert.pem',
            'C:/wamp64/bin/php/php8.0.30/extras/ssl/cacert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return $value === false ? '' : trim((string) $value);
    }
}
