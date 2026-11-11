<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function validKanjiList(mixed $value): bool
{
    if (!is_array($value) || count($value) > 129) {
        return false;
    }

    foreach ($value as $kanji) {
        if (!is_string($kanji) || !preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー]{1,8}$/u', $kanji)) {
            return false;
        }
    }

    return true;
}

function validQuizHistory(mixed $value): bool
{
    if (!is_array($value) || count($value) > 50) {
        return false;
    }

    foreach ($value as $result) {
        if (!is_array($result)) {
            return false;
        }

        $quizType = $result['quizType'] ?? 'kanji';
        $maximumQuestions = $quizType === 'kanji' ? 5 : 10;

        if (
            !isset($result['id'], $result['playedAt'], $result['correct'], $result['total'], $result['pointsEarned']) ||
            !in_array($quizType, ['kanji', 'katakana', 'hiragana'], true) ||
            !is_string($result['id']) ||
            strlen($result['id']) > 80 ||
            !is_string($result['playedAt']) ||
            strlen($result['playedAt']) > 40 ||
            !is_int($result['correct']) ||
            !is_int($result['total']) ||
            !is_int($result['pointsEarned']) ||
            $result['total'] < 1 ||
            $result['total'] > $maximumQuestions ||
            $result['correct'] < 0 ||
            $result['correct'] > $result['total'] ||
            $result['pointsEarned'] < 0 ||
            $result['pointsEarned'] > 50 ||
            !array_key_exists('unlockedKanji', $result) ||
            ($quizType !== 'kanji' && ($result['pointsEarned'] !== 0 || $result['unlockedKanji'] !== null)) ||
            ($result['unlockedKanji'] !== null && (
                !is_string($result['unlockedKanji']) ||
                !preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー]{1,8}$/u', $result['unlockedKanji'])
            ))
        ) {
            return false;
        }

        try {
            new DateTimeImmutable($result['playedAt']);
        } catch (Exception) {
            return false;
        }
    }

    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'PUT'], true)) {
    header('Allow: GET, PUT');
    respond(405, ['error' => 'Method not allowed']);
}

$deviceToken = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
if (!is_string($deviceToken) || !preg_match('/^[a-f0-9]{64}$/', $deviceToken)) {
    respond(401, ['error' => 'Invalid device token']);
}
$tokenHash = hash('sha256', $deviceToken);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('Japanese Study API: config.php is missing.');
    respond(503, ['error' => 'Server storage is not configured']);
}

$config = require $configPath;
if (
    !is_array($config) ||
    !isset($config['db_host'], $config['db_name'], $config['db_user'], $config['db_password']) ||
    $config['db_name'] === 'YOUR_DATABASE_NAME' ||
    $config['db_user'] === 'YOUR_DATABASE_USER'
) {
    error_log('Japanese Study API: database configuration is incomplete.');
    respond(503, ['error' => 'Server storage is not configured']);
}

try {
    $database = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
        $config['db_user'],
        $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS study_progress (
            device_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            progress_json MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    if ($method === 'GET') {
        $statement = $database->prepare('SELECT progress_json FROM study_progress WHERE device_hash = :device_hash');
        $statement->execute(['device_hash' => $tokenHash]);
        $savedProgress = $statement->fetchColumn();
        if ($savedProgress === false) {
            respond(200, ['progress' => null]);
        }

        $progress = json_decode($savedProgress, true, 512, JSON_THROW_ON_ERROR);
        respond(200, ['progress' => $progress]);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 65536) {
        respond(413, ['error' => 'Request body is too large']);
    }

    try {
        $progress = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'Invalid JSON']);
    }

    if (
        !is_array($progress) ||
        !isset($progress['points'], $progress['unlockedCount'], $progress['updatedAt']) ||
        !is_int($progress['points']) ||
        $progress['points'] < 0 ||
        $progress['points'] > 2147483647 ||
        !is_int($progress['unlockedCount']) ||
        $progress['unlockedCount'] < 1 ||
        $progress['unlockedCount'] > 129 ||
        !is_string($progress['updatedAt']) ||
        strlen($progress['updatedAt']) > 40 ||
        !is_bool($progress['showItalian'] ?? null) ||
        !validKanjiList($progress['reviewKanji'] ?? null) ||
        !validQuizHistory($progress['quizHistory'] ?? null)
    ) {
        respond(400, ['error' => 'Invalid progress data']);
    }

    try {
        new DateTimeImmutable($progress['updatedAt']);
    } catch (Exception) {
        respond(400, ['error' => 'Invalid update timestamp']);
    }

    $progressJson = json_encode(
        $progress,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $statement = $database->prepare(
        'INSERT INTO study_progress (device_hash, progress_json)
         VALUES (:device_hash, :progress_json)
         ON DUPLICATE KEY UPDATE progress_json = VALUES(progress_json), updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'device_hash' => $tokenHash,
        'progress_json' => $progressJson,
    ]);

    respond(200, ['saved' => true]);
} catch (PDOException $error) {
    error_log('Japanese Study API database error: ' . $error->getMessage());
    respond(503, ['error' => 'Server storage is temporarily unavailable']);
} catch (JsonException $error) {
    error_log('Japanese Study API JSON error: ' . $error->getMessage());
    respond(400, ['error' => 'Invalid progress data']);
}
