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
if (!$task_id) {
    json_error('task_id invalid');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$force = (($_POST['force'] ?? '') === '1');

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
        updated_at,
        ai_answer,
        ai_answer_updated_at,
        ai_answer_provider,
        ai_answer_model
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

/**
 * 添付ファイルの最新追加時刻を取得
 */
$stmtMax = $pdo->prepare('
    SELECT MAX(created_at) AS max_created
    FROM task_files
    WHERE user_id = :uid AND task_id = :tid
');
$stmtMax->execute([
    ':uid' => $user_id,
    ':tid' => $task_id,
]);
$maxFileCreated = $stmtMax->fetchColumn();

/**
 * 既存AI回答が stale か判定
 * - task 自体が更新されている
 * - 添付ファイルが後から追加されている
 */
$stale = false;

if (!empty($task['ai_answer_updated_at'])) {
    $aiTime = strtotime((string)$task['ai_answer_updated_at']);

    if ($aiTime !== false) {
        if (!empty($task['updated_at'])) {
            $taskUpdated = strtotime((string)$task['updated_at']);
            if ($taskUpdated !== false && $taskUpdated > $aiTime) {
                $stale = true;
            }
        }

        if ($maxFileCreated) {
            $fileUpdated = strtotime((string)$maxFileCreated);
            if ($fileUpdated !== false && $fileUpdated > $aiTime) {
                $stale = true;
            }
        }
    }
}

/**
 * provider / model 一致時のみキャッシュ再利用
 */
$cacheMatchesModel =
    !empty($task['ai_answer_provider']) &&
    !empty($task['ai_answer_model']) &&
    (string)$task['ai_answer_provider'] === $provider &&
    (string)$task['ai_answer_model'] === $model;

if (
    !$force &&
    !$stale &&
    $cacheMatchesModel &&
    !empty($task['ai_answer']) &&
    !empty($task['ai_answer_updated_at'])
) {
    echo json_encode([
        'ok' => true,
        'answer' => (string)$task['ai_answer'],
        'cached' => true,
        'provider' => $provider,
        'model' => $model,
        'generated_at' => (string)$task['ai_answer_updated_at'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 添付ファイル取得
 * 最小変更版では OpenAI のみ添付利用
 */
$stmtF = $pdo->prepare('
    SELECT
        id,
        original_name,
        stored_name,
        mime,
        size,
        created_at
    FROM task_files
    WHERE user_id = :uid AND task_id = :tid
    ORDER BY id DESC
');
$stmtF->execute([
    ':uid' => $user_id,
    ':tid' => $task_id,
]);
$files = $stmtF->fetchAll(PDO::FETCH_ASSOC);

$apiKey = ai_get_api_key($provider);
if ($apiKey === '') {
    json_error($provider . ' API key is not set on server', 500);
}

$system = ai_guidance_system_prompt();
$userPrompt = ai_build_guidance_user_prompt($task);

try {
    $answer = ai_generate_guidance(
        $provider,
        $model,
        $apiKey,
        $system,
        $userPrompt,
        $files
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
        ai_answer = :ans,
        ai_answer_updated_at = NOW(),
        ai_answer_provider = :provider,
        ai_answer_model = :model
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$save->execute([
    ':ans' => $answer,
    ':provider' => $provider,
    ':model' => $model,
    ':id' => $task_id,
    ':uid' => $user_id,
]);

echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'cached' => false,
    'provider' => $provider,
    'model' => $model,
    'generated_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
