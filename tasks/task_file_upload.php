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

$task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
$cell = $_POST['cell'] ?? '';
if (!$task_id) {
    http_response_code(400);
    exit('task_id is invalid');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare('SELECT id FROM tasks WHERE id = :tid AND user_id = :uid LIMIT 1');
$stmt->execute([':tid' => $task_id, ':uid' => $user_id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit('task not found');
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    exit('file is required');
}
$f = $_FILES['file'];

if ($f['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('upload failed');
}

$maxBytes = 10 * 1024 * 1024;
if ($f['size'] <= 0 || $f['size'] > $maxBytes) {
    http_response_code(400);
    exit('file size is invalid');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($f['tmp_name']);

$allowed = [
    'application/pdf' => 'pdf',
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'text/plain' => 'txt',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
];

if (!isset($allowed[$mime])) {
    http_response_code(400);
    exit('file type not allowed');
}

$ext = $allowed[$mime];
$stored = bin2hex(random_bytes(16)) . '.' . $ext;

$dir = __DIR__ . '/../uploads/task_files';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$path = $dir . '/' . $stored;

if (!move_uploaded_file($f['tmp_name'], $path)) {
    http_response_code(500);
    exit('failed to save file');
}

$original = (string)$f['name'];
$size = (int)$f['size'];

$stmt = $pdo->prepare('
  INSERT INTO task_files (user_id, task_id, original_name, stored_name, mime, size)
  VALUES (:uid, :tid, :oname, :sname, :mime, :size)
');
$stmt->execute([
    ':uid' => $user_id,
    ':tid' => $task_id,
    ':oname' => $original,
    ':sname' => $stored,
    ':mime' => $mime,
    ':size' => $size,
]);

$back = 'task.php?id=' . $task_id;
if (preg_match('/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/', $cell)) {
    $back .= '&cell=' . urlencode($cell);
}
header('Location: ' . $back);
exit;