<?php
require_once __DIR__ . '/../bootstrap.php';
require_post_csrf();

function course_exists(PDO $pdo, int $user_id, int $course_id): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM timetable_courses
        WHERE user_id = :uid
          AND id = :cid
        LIMIT 1
    ');
    $stmt->execute([
        ':uid' => $user_id,
        ':cid' => $course_id,
    ]);

    return (bool)$stmt->fetchColumn();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    json_ng('login required', 401);
}

$course_id = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$title     = trim((string)($_POST['title'] ?? ''));
$due       = (string)($_POST['due'] ?? '');
$detail    = trim((string)($_POST['detail'] ?? ''));

if (!$course_id) {
    json_ng('course_id is required', 400);
}
if ($title === '') {
    json_ng('title is required', 400);
}
if (!course_exists($pdo, $user_id, (int)$course_id)) {
    json_ng('course_id is invalid', 400);
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
    INSERT INTO tasks (user_id, course_id, title, due_at, detail, status)
    VALUES (:uid, :course_id, :title, :due_at, :detail, "open")
');
$stmt->execute([
    ':uid'       => $user_id,
    ':course_id' => (int)$course_id,
    ':title'     => $title,
    ':due_at'    => $due_at,
    ':detail'    => $detail !== '' ? $detail : null,
]);

json_ok([
    'task_id'   => (int)$pdo->lastInsertId(),
    'course_id' => (int)$course_id,
]);
