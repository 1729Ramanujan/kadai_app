<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'error' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$task_id = filter_input(INPUT_GET, 'task_id', FILTER_VALIDATE_INT);
if (!$task_id) {
    json_error('task_id invalid');
}

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
if (!$limit) {
    $limit = 50;
}
$limit = max(1, min(100, $limit));

$user_id = (int)($_SESSION['user_id'] ?? 0);

/**
 * task が本人のものか確認
 */
$stmtTask = $pdo->prepare('
    SELECT id, title
    FROM tasks
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$stmtTask->execute([
    ':id' => $task_id,
    ':uid' => $user_id,
]);
$task = $stmtTask->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    json_error('task not found', 404);
}

/**
 * 最新の active thread を取得
 * mode では分けない
 */
$stmtThread = $pdo->prepare('
    SELECT id, mode, last_message_at, created_at, updated_at
    FROM task_chat_threads
    WHERE user_id = :uid
      AND task_id = :tid
      AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
');
$stmtThread->execute([
    ':uid' => $user_id,
    ':tid' => $task_id,
]);
$thread = $stmtThread->fetch(PDO::FETCH_ASSOC);

if (!$thread) {
    echo json_encode([
        'ok' => true,
        'task_id' => $task_id,
        'mode' => 'chat',
        'mode_label' => 'AI相談',
        'thread_id' => null,
        'messages' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$thread_id = (int)$thread['id'];

/**
 * 新しい順で limit 件取得して、表示用に時系列へ戻す
 */
$stmtMsg = $pdo->prepare("
    SELECT
        id,
        role,
        body,
        safety_flag,
        provider,
        model,
        created_at
    FROM task_chat_messages
    WHERE thread_id = :thread_id
      AND user_id = :user_id
    ORDER BY id DESC
    LIMIT {$limit}
");
$stmtMsg->execute([
    ':thread_id' => $thread_id,
    ':user_id' => $user_id,
]);
$rows = $stmtMsg->fetchAll(PDO::FETCH_ASSOC);

$rows = array_reverse($rows);

$messages = array_map(static function (array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'role' => (string)($row['role'] ?? ''),
        'body' => (string)($row['body'] ?? ''),
        'safety_flag' => (string)($row['safety_flag'] ?? 'normal'),
        'provider' => $row['provider'] !== null ? (string)$row['provider'] : null,
        'model' => $row['model'] !== null ? (string)$row['model'] : null,
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
}, $rows);

echo json_encode([
    'ok' => true,
    'task_id' => $task_id,
    'mode' => 'chat',
    'mode_label' => 'AI相談',
    'thread_id' => $thread_id,
    'thread' => [
        'mode' => (string)($thread['mode'] ?? 'chat'),
        'last_message_at' => (string)($thread['last_message_at'] ?? ''),
        'created_at' => (string)($thread['created_at'] ?? ''),
        'updated_at' => (string)($thread['updated_at'] ?? ''),
    ],
    'messages' => $messages,
], JSON_UNESCAPED_UNICODE);
