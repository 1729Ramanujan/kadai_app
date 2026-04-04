<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/ai_prompts.php';
require_once __DIR__ . '/../ai/ai_client.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'error' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    json_error('CSRF invalid', 403);
}

$task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
$draft   = trim((string)($_POST['draft'] ?? ''));
$force   = (($_POST['force'] ?? '') === '1');

if (!$task_id) {
    json_error('task_id invalid');
}

if ($draft === '') {
    json_error('draft is empty');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$selection = ai_resolve_selection(
    (string)($_POST['provider'] ?? ''),
    (string)($_POST['model'] ?? '')
);

$provider = $selection['provider'];
$model    = $selection['model'];

$stmt = $pdo->prepare('
    SELECT
        id,
        title,
        detail,
        due_at,
        status,
        ai_grade_json,
        ai_grade_hash,
        ai_grade_updated_at,
        ai_grade_provider,
        ai_grade_model
    FROM tasks
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$stmt->execute([
    ':id' => $task_id,
    ':uid' => $user_id,
]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    json_error('task not found', 404);
}

$hash = hash('sha256', $draft);

/**
 * provider / model 一致 + 同じ draft のときだけキャッシュ再利用
 */
$cacheMatchesModel =
    !empty($task['ai_grade_provider']) &&
    !empty($task['ai_grade_model']) &&
    (string)$task['ai_grade_provider'] === $provider &&
    (string)$task['ai_grade_model'] === $model;

if (
    !$force &&
    $cacheMatchesModel &&
    !empty($task['ai_grade_json']) &&
    !empty($task['ai_grade_hash']) &&
    hash_equals((string)$task['ai_grade_hash'], $hash)
) {
    $cachedResult = json_decode((string)$task['ai_grade_json'], true);

    echo json_encode([
        'ok' => true,
        'cached' => true,
        'provider' => $provider,
        'model' => $model,
        'graded_at' => (string)($task['ai_grade_updated_at'] ?? ''),
        'result' => is_array($cachedResult) ? $cachedResult : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = ai_get_api_key($provider);
if ($apiKey === '') {
    json_error($provider . ' API key is not set on server', 500);
}

$system = ai_grade_system_prompt();
$userPrompt = ai_build_grade_user_prompt($task, $draft);

try {
    $result = ai_grade_draft(
        $provider,
        $model,
        $apiKey,
        $system,
        $userPrompt
    );
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}

/**
 * 保存
 */
$save = $pdo->prepare('
    UPDATE tasks
    SET
        ai_grade_json = :json,
        ai_grade_hash = :hash,
        ai_grade_updated_at = NOW(),
        ai_grade_provider = :provider,
        ai_grade_model = :model
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$save->execute([
    ':json' => json_encode($result, JSON_UNESCAPED_UNICODE),
    ':hash' => $hash,
    ':provider' => $provider,
    ':model' => $model,
    ':id' => $task_id,
    ':uid' => $user_id,
]);

echo json_encode([
    'ok' => true,
    'cached' => false,
    'provider' => $provider,
    'model' => $model,
    'graded_at' => date('Y-m-d H:i:s'),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
