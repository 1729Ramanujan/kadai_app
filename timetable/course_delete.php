<?php
require_once __DIR__ . '/../bootstrap.php';
require_post_csrf();

function fail(string $message, int $status = 400): void
{
    json_ng($message, $status);
}

function fetch_course(PDO $pdo, int $user_id, int $course_id): ?array
{
    $stmt = $pdo->prepare('
        SELECT id, course_name, room
        FROM timetable_courses
        WHERE user_id = :uid AND id = :cid
        LIMIT 1
    ');
    $stmt->execute([
        ':uid' => $user_id,
        ':cid' => $course_id,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    fail('login required', 401);
}

$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
if (!$courseId) {
    fail('course_id is required', 400);
}

$course = fetch_course($pdo, $user_id, (int)$courseId);
if (!$course) {
    fail('course_id is invalid', 400);
}

try {
    $pdo->beginTransaction();

    // 授業の課題も削除
    $stmt = $pdo->prepare('
    DELETE FROM tasks
    WHERE user_id = :uid
      AND course_id = :cid
');
    $stmt->execute([
        ':uid' => $user_id,
        ':cid' => (int)$courseId,
    ]);

    // 以下は既存のまま
    $stmt = $pdo->prepare('
        DELETE FROM timetable_course_slots
        WHERE user_id = :uid
          AND course_id = :cid
    ');
    $stmt->execute([
        ':uid' => $user_id,
        ':cid' => (int)$courseId,
    ]);

    $stmt = $pdo->prepare('
        DELETE FROM timetable_courses
        WHERE user_id = :uid
          AND id = :cid
        LIMIT 1
    ');
    $stmt->execute([
        ':uid' => $user_id,
        ':cid' => (int)$courseId,
    ]);

    $pdo->commit();

    json_ok([
        'deletedCourseId' => (int)$courseId,
        'courseName' => (string)$course['course_name'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_ng('delete failed', 500);
}
