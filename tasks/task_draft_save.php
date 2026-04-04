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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    json_error('CSRF invalid', 403);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
$draft   = (string)($_POST['draft'] ?? '');

if (!$task_id) {
    json_error('task_id invalid');
}

/* 本人の課題か確認 */
$stmt = $pdo->prepare('
    SELECT id
    FROM tasks
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$stmt->execute([
    ':id'  => $task_id,
    ':uid' => $user_id,
]);

if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    json_error('task not found', 404);
}

/* 空文字も保存できるようにする */
$save = $pdo->prepare('
    UPDATE tasks
    SET
        draft_body = :draft_body,
        draft_updated_at = NOW()
    WHERE id = :id AND user_id = :uid
    LIMIT 1
');
$save->execute([
    ':draft_body' => $draft,
    ':id'         => $task_id,
    ':uid'        => $user_id,
]);

echo json_encode([
    'ok' => true,
    'saved_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
