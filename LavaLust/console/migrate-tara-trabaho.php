<?php

function load_migration_env(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('The application .env file is missing.');
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
        if (getenv($key) === false) putenv($key . '=' . $value);
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    load_migration_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    $database = getenv('DB_NAME') ?: '';
    if ($database !== 'tara_trabaho') {
        throw new RuntimeException('Refusing to migrate: DB_NAME must be tara_trabaho.');
    }

    $db = require __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

    $columns = [
        'Users' => [
            'location' => "VARCHAR(160) NOT NULL DEFAULT ''",
            'email_alerts' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'qualifications' => 'TEXT NULL',
            'experience' => 'TEXT NULL',
            'profile_image_url' => 'VARCHAR(1000) NULL',
            'profile_image_public_id' => 'VARCHAR(255) NULL'
        ],
        'JobVacancies' => [
            'category' => "VARCHAR(100) NOT NULL DEFAULT ''"
        ],
        'TrainingPrograms' => [
            'category' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'starts' => 'DATE NULL',
            'ends' => 'DATE NULL'
        ],
        'Applications' => [
            'cover_letter' => 'TEXT NULL',
            'applicant_phone' => 'VARCHAR(40) NULL',
            'applicant_location' => 'VARCHAR(160) NULL'
        ],
        'Notifications' => [
            'url' => "VARCHAR(255) NOT NULL DEFAULT '/'"
        ],
        'UploadedDocuments' => [
            'cloudinary_url' => 'VARCHAR(1000) NULL',
            'cloudinary_public_id' => 'VARCHAR(255) NULL',
            'cloudinary_asset_id' => 'VARCHAR(255) NULL',
            'cloudinary_resource_type' => "VARCHAR(20) NOT NULL DEFAULT 'raw'",
            'cloudinary_version' => 'BIGINT UNSIGNED NULL'
        ]
    ];

    foreach ($columns as $table => $tableColumns) {
        foreach ($tableColumns as $column => $definition) {
            $existingColumn = $db->table('INFORMATION_SCHEMA.COLUMNS')
                ->select_count('COLUMN_NAME', 'total')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->get();
            if ((int) ($existingColumn['total'] ?? 0) === 0) {
                $db->raw('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                echo "Added {$table}.{$column}\n";
            }
        }
    }

    $documentTypeColumn = $db->table('INFORMATION_SCHEMA.COLUMNS')
        ->select('DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE')
        ->where('TABLE_SCHEMA', $database)
        ->where('TABLE_NAME', 'UploadedDocuments')
        ->where('COLUMN_NAME', 'document_type')
        ->get();
    if (!$documentTypeColumn) {
        throw new RuntimeException('UploadedDocuments.document_type is missing from the active database.');
    }
    $documentTypeLength = (int) ($documentTypeColumn['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    if ($documentTypeLength > 0 && $documentTypeLength < 150) {
        $nullability = ($documentTypeColumn['IS_NULLABLE'] ?? 'NO') === 'YES' ? 'NULL' : 'NOT NULL';
        $db->raw('ALTER TABLE `UploadedDocuments` MODIFY COLUMN `document_type` VARCHAR(150) ' . $nullability);
        echo "Expanded UploadedDocuments.document_type to VARCHAR(150) for PDF and DOCX MIME types.\n";
    }

    $db->raw(
        "CREATE TABLE IF NOT EXISTS TrainingApplications (
            application_id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            program_id INT NOT NULL,
            application_status VARCHAR(50) NOT NULL DEFAULT 'Submitted',
            cover_letter TEXT NULL,
            applicant_phone VARCHAR(40) NULL,
            applicant_location VARCHAR(160) NULL,
            application_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (application_id),
            UNIQUE KEY training_application_once (user_id, program_id),
            KEY training_application_program (program_id),
            KEY training_application_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $trainingResumeColumn = $db->table('INFORMATION_SCHEMA.COLUMNS')
        ->select_count('COLUMN_NAME', 'total')
        ->where('TABLE_SCHEMA', $database)
        ->where('TABLE_NAME', 'TrainingApplications')
        ->where('COLUMN_NAME', 'resume_id')
        ->get();
    if ((int) ($trainingResumeColumn['total'] ?? 0) > 0) {
        $db->raw('ALTER TABLE `TrainingApplications` DROP COLUMN `resume_id`');
        echo "Removed TrainingApplications.resume_id; training registrations no longer use attachments.\n";
    }
    $db->raw(
        "CREATE TABLE IF NOT EXISTS TaraPushSubscriptions (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            public_key VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tara_push_endpoint_hash_unique (endpoint_hash),
            KEY tara_push_user_idx (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    echo "TARA-TRABAHO additive migration complete. Existing tables and rows were preserved.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
