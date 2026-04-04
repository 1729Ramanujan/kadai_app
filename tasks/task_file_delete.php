<?php
require_once __DIR__ . '/../config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../timetable/timetable.php');
    exit;
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('CSRF invalid');
}

$file_id = filter_input(INPUT_POST, 'file_id', FILTER_VALIDATE_INT);
$task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
$cell = $_POST['cell'] ?? '';

if (!$file_id || !$task_id) {
    http_response_code(400);
    exit('invalid');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare('
  SELECT id, stored_name
  FROM task_files
  WHERE id = :fid AND task_id = :tid AND user_id = :uid
  LIMIT 1
');
$stmt->execute([':fid' => $file_id, ':tid' => $task_id, ':uid' => $user_id]);
$file = $stmt->fetch();

if ($file) {
    $path = __DIR__ . '/../uploads/task_files/' . $file['stored_name'];
    if (is_file($path)) {
        @unlink($path);
    }

    $del = $pdo->prepare('DELETE FROM task_files WHERE id = :fid AND user_id = :uid');
    $del->execute([':fid' => $file_id, ':uid' => $user_id]);
}

$back = 'task.php?id=' . $task_id;
if (preg_match('/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/', $cell)) {
    $back .= '&cell=' . urlencode($cell);
}
header('Location: ' . $back);
exit;