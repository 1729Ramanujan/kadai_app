<?php
require_once __DIR__ . '/../config.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('id is invalid');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare('
  SELECT id, user_id, original_name, stored_name, mime, size
  FROM task_files
  WHERE id = :id AND user_id = :uid
  LIMIT 1
');
$stmt->execute([':id' => $id, ':uid' => $user_id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('file not found');
}

$path = __DIR__ . '/../uploads/task_files/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('file missing on server');
}

header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . (string)$file['size']);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;