<?php
declare(strict_types=1);

/**
 * Forward-only migration runner.
 *
 *   php tools/migrate.php          apply anything not yet applied
 *   php tools/migrate.php --status show what has and has not run
 *
 * If you have no shell on your host, import migrations/*.sql through the
 * phpMyAdmin Import tab in filename order instead - the effect is the same.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use CoNotes\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

Db::run('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(191) NOT NULL,
    applied_at DATETIME     NOT NULL,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$dir = dirname(__DIR__) . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

$applied = [];
foreach (Db::all('SELECT filename FROM schema_migrations') as $row) {
    $applied[(string) $row['filename']] = true;
}

$statusOnly = in_array('--status', $argv, true);
$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name])) {
        echo "  ok      {$name}\n";
        continue;
    }
    if ($statusOnly) {
        echo "  pending {$name}\n";
        continue;
    }

    echo "  running {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "could not read file\n";
        exit(1);
    }

    try {
        // Statements are separated by semicolons at end of line. The schema
        // deliberately contains no stored routines, so this is sufficient.
        //
        // Each chunk arrives with the comment block that documents it still
        // attached in front. Those comment lines have to be stripped line by
        // line: testing whether the *chunk* starts with '--' would throw the
        // statement away along with its comment, and the loop would then report
        // success having run nothing at all.
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $chunk) {
            $kept = [];
            foreach (preg_split('/\R/', $chunk) ?: [] as $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }
                $kept[] = $line;
            }

            $stmt = trim(implode("\n", $kept));
            if ($stmt === '') {
                continue;   // the chunk was nothing but comments
            }

            Db::conn()->exec($stmt);
        }

        Db::run(
            'INSERT INTO schema_migrations (filename, applied_at) VALUES (:f, :now)',
            [':f' => $name, ':now' => Db::now()]
        );

        echo "done\n";
        $ran++;
    } catch (Throwable $e) {
        echo "FAILED\n    " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $statusOnly ? "\n" : "\n{$ran} migration(s) applied.\n";
