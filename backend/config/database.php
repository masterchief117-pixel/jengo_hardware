<?php
/**
 * config/database.php
 *
 * Parses the project .env file, loads all key=value pairs into the
 * environment, and returns a singleton PDO connection.
 *
 * Usage (anywhere in the project):
 *   require_once __DIR__ . '/../config/database.php';
 *   $pdo = db();
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. .ENV PARSER
// ---------------------------------------------------------------------------
// PHP does not natively load .env files, so we parse it ourselves.
// We do this once per request; subsequent calls skip the file read.
// ---------------------------------------------------------------------------

function load_env(string $path): void
{
    static bool $loaded = false;  // Only parse once per request
    if ($loaded) {
        return;
    }

    if (!file_exists($path)) {
        throw new RuntimeException(
            ".env file not found at: {$path}\n" .
            "Copy .env.example to .env and fill in your credentials."
        );
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and blank lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Only process lines that contain an = sign
        if (!str_contains($line, '=')) {
            continue;
        }

        // Split on the FIRST = only (values may contain = e.g. base64 keys)
        [$key, $value] = explode('=', $line, 2);

        $key   = trim($key);
        $value = trim($value);

        // Strip inline comments  (e.g.  DB_PORT=3306  # default port)
        if (str_contains($value, ' #')) {
            $value = trim(explode(' #', $value, 2)[0]);
        }

        // Strip surrounding quotes  ("value"  or  'value')
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Populate $_ENV, $_SERVER, and putenv — all three for maximum
        // compatibility across different PHP configurations.
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    $loaded = true;
}

// ---------------------------------------------------------------------------
// 2. HELPER: env()
// ---------------------------------------------------------------------------
// Retrieve an env variable with an optional fallback default.
//
//   env('DB_HOST')           // returns value or null
//   env('DB_PORT', '3306')   // returns value or '3306'
// ---------------------------------------------------------------------------

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    // Cast common string booleans so env('APP_DEBUG') returns a real bool
    return match (strtolower((string) $value)) {
        'true',  '(true)'  => true,
        'false', '(false)' => false,
        'null',  '(null)'  => null,
        'empty', '(empty)' => '',
        default            => $value,
    };
}

// ---------------------------------------------------------------------------
// 3. BOOTSTRAP: load .env on first require
// ---------------------------------------------------------------------------

$_envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
load_env($_envPath);

// ---------------------------------------------------------------------------
// 4. PDO SINGLETON
// ---------------------------------------------------------------------------
// Returns the same PDO instance for every call — avoids opening multiple
// database connections during a single request.
// ---------------------------------------------------------------------------

function db(): PDO
{
    static ?PDO $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host    = env('DB_HOST',    'localhost');
    $port    = env('DB_PORT',    '3306');
    $name    = env('DB_NAME',    '');
    $user    = env('DB_USER',    'root');
    $pass    = env('DB_PASS',    '');
    $charset = env('DB_CHARSET', 'utf8mb4');

    if ($name === '') {
        throw new RuntimeException('DB_NAME is not set in .env');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Throw PDOException on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Return rows as associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                    // Use real prepared statements
        PDO::ATTR_PERSISTENT         => false,                    // No persistent connections (safer in XAMPP)
        PDO::MYSQL_ATTR_FOUND_ROWS   => true,                     // UPDATE returns matched rows, not changed rows
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // Never expose raw PDO errors to the browser in production
        if (env('APP_DEBUG', false) === true) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        throw new RuntimeException(
            'Unable to connect to the database. Please contact the administrator.',
            500
        );
    }

    return $pdo;
}
