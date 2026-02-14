<?php
require_once __DIR__ . '/bootstrap.php';
require_post_csrf();

$user_id = (int)$_SESSION['user_id'];

$cell  = $_POST['cell'] ?? '';
$title = trim((string)($_POST['title'] ?? ''));
$due   = $_POST['due'] ?? '';       // datetime-local 文字列 "YYYY-MM-DDTHH:MM"
$detail = trim((string)($_POST['detail'] ?? ''));

if (!preg_match('/^(mon|tue|wed|thu|fri|sat)_[1-9][0-9]*$/', $cell)) {
    json_ng('cell is invalid', 400);
}
if ($title === '') {
    json_ng('title is required', 400);
}

$due_at = null;
if ($due !== '') {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $due);
    if (!$dt) {
        json_ng('due is invalid', 400);
    }
    $due_at = $dt->format('Y-m-d H:i:00');
}

$stmt = $pdo->prepare('
  INSERT INTO tasks (user_id, cell_key, title, due_at, detail, status)
  VALUES (:uid, :cell, :title, :due_at, :detail, "open")
');
$stmt->execute([
    ':uid' => $user_id,
    ':cell' => $cell,
    ':title' => $title,
    ':due_at' => $due_at,
    ':detail' => $detail !== '' ? $detail : null,
]);

json_ok(['task_id' => (int)$pdo->lastInsertId()]);
