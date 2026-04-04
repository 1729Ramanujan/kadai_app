<?php
require_once __DIR__ . '/_google.php';

header('Content-Type: application/json; charset=utf-8');

function jerr($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fatalも拾ってJSONで返す
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Fatal: ' . $e['message']], JSON_UNESCAPED_UNICODE);
    }
});

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jerr('Method not allowed', 405);
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        jerr('CSRF invalid', 403);
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
    $draft   = (string)($_POST['draft'] ?? '');

    if (!$task_id) {
        jerr('task_id invalid');
    }
    if (trim($draft) === '') {
        jerr('draft is empty');
    }

    $stmt = $pdo->prepare('SELECT id, title, google_doc_id FROM tasks WHERE id=:id AND user_id=:uid LIMIT 1');
    $stmt->execute([':id' => $task_id, ':uid' => $user_id]);
    $task = $stmt->fetch();

    if (!$task) {
        jerr('task not found', 404);
    }

    $client = google_client($pdo, $user_id, true);
    $docs   = new Google\Service\Docs($client);

    // Docが無ければ作る
    $docId = (string)($task['google_doc_id'] ?? '');
    if ($docId === '') {
        $created = $docs->documents->create(new Google\Service\Docs\Document([
            'title' => (string)($task['title'] ?: 'レポート下書き')
        ]));
        $docId = $created->getDocumentId();

        $up = $pdo->prepare('UPDATE tasks SET google_doc_id=:did WHERE id=:id AND user_id=:uid LIMIT 1');
        $up->execute([
            ':did' => $docId,
            ':id'  => $task_id,
            ':uid' => $user_id
        ]);
    }

    // 既存本文を全消し → 先頭に挿入
    $doc = $docs->documents->get($docId);
    $content = $doc->getBody()->getContent();

    $endIndex = 1;
    if (is_array($content)) {
        foreach ($content as $el) {
            $ei = method_exists($el, 'getEndIndex') ? $el->getEndIndex() : null;
            if (is_numeric($ei)) {
                $endIndex = max($endIndex, (int)$ei);
            }
        }
    }

    $requests = [];

    $start = 1;
    $end   = $endIndex - 1;

    if ($end > $start) {
        $requests[] = new Google\Service\Docs\Request([
            'deleteContentRange' => [
                'range' => [
                    'startIndex' => $start,
                    'endIndex'   => $end
                ]
            ]
        ]);
    }

    $text = rtrim($draft) . "\n";
    $requests[] = new Google\Service\Docs\Request([
        'insertText' => [
            'location' => ['index' => 1],
            'text'     => $text
        ]
    ]);

    $batch = new Google\Service\Docs\BatchUpdateDocumentRequest([
        'requests' => $requests
    ]);
    $docs->documents->batchUpdate($docId, $batch);

    echo json_encode([
        'ok'      => true,
        'doc_id'  => $docId,
        'doc_url' => doc_url($docId)
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Google\Service\Exception $e) {
    error_log('Docs API error: ' . $e->getMessage());
    jerr('Google API error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    error_log('Server error: ' . $e->getMessage());
    jerr('Server error: ' . $e->getMessage(), 500);
}