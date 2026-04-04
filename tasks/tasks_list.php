<?php
require_once __DIR__ . '/../bootstrap.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    json_ng('login required', 401);
}

$course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (!$course_id) {
    json_ng('course_id is required', 400);
}

$stmtCourse = $pdo->prepare('
    SELECT 1
    FROM timetable_courses
    WHERE user_id = :uid
      AND id = :cid
    LIMIT 1
');
$stmtCourse->execute([
    ':uid' => $user_id,
    ':cid' => (int)$course_id,
]);

if (!$stmtCourse->fetchColumn()) {
    json_ng('course_id is invalid', 400);
}

$stmt = $pdo->prepare('
    SELECT
        id,
        title,
        due_at,
        detail,
        status,
        course_id
    FROM tasks
    WHERE user_id = :uid
      AND course_id = :cid
    ORDER BY (due_at IS NULL) ASC, due_at ASC, id ASC
');
$stmt->execute([
    ':uid' => $user_id,
    ':cid' => (int)$course_id,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $r['id'] = (int)($r['id'] ?? 0);
    $r['course_id'] = (int)($r['course_id'] ?? 0);
    $r['due_at'] = !empty($r['due_at'])
        ? date('Y-m-d\TH:i', strtotime((string)$r['due_at']))
        : null;
}
unset($r);

json_ok(['tasks' => $rows]);
