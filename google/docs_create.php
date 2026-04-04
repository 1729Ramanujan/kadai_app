<?php
require_once __DIR__ . '/_google.php';

header('Content-Type: application/json; charset=utf-8');

function jerr($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jerr('Method not allowed', 405);
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    jerr('CSRF invalid', 403);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
if (!$task_id) {
    jerr('task_id invalid');
}

$stmt = $pdo->prepare('SELECT id, title, google_doc_id FROM tasks WHERE id=:id AND user_id=:uid LIMIT 1');
$stmt->execute([':id' => $task_id, ':uid' => $user_id]);
$task = $stmt->fetch();

if (!$task) {
    jerr('task not found', 404);
}

// 既にあればそのまま返す
if (!empty($task['google_doc_id'])) {
    echo json_encode([
        'ok'      => true,
        'doc_id'  => $task['google_doc_id'],
        'doc_url' => doc_url($task['google_doc_id']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $client = google_client($pdo, $user_id, true);
} catch (Throwable $e) {
    jerr($e->getMessage(), 400);
}

$docs = new Google\Service\Docs($client);
$doc = new Google\Service\Docs\Document([
    'title' => (string)($task['title'] ?: 'レポート下書き')
]);

$created = $docs->documents->create($doc);
$docId = $created->getDocumentId();

$up = $pdo->prepare('UPDATE tasks SET google_doc_id=:did WHERE id=:id AND user_id=:uid LIMIT 1');
$up->execute([
    ':did' => $docId,
    ':id'  => $task_id,
    ':uid' => $user_id
]);

echo json_encode([
    'ok'      => true,
    'doc_id'  => $docId,
    'doc_url' => doc_url($docId)
], JSON_UNESCAPED_UNICODE);