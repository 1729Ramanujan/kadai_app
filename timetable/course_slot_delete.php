<?php
require_once __DIR__ . '/../bootstrap.php';
require_post_csrf();

function fail(string $message, int $status = 400): void
{
    json_ng($message, $status);
}

function validate_day(string $day): string
{
    $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    if (!in_array($day, $valid, true)) {
        fail('day is invalid', 400);
    }
    return $day;
}

function validate_period(int $period): int
{
    if ($period < 1 || $period > 6) {
        fail('period is invalid', 400);
    }
    return $period;
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

function fetch_slots(PDO $pdo, int $user_id, int $course_id): array
{
    $stmt = $pdo->prepare('
        SELECT day, period
        FROM timetable_course_slots
        WHERE user_id = :uid
          AND course_id = :cid
        ORDER BY FIELD(day, "mon", "tue", "wed", "thu", "fri", "sat"), period ASC
    ');
    $stmt->execute([
        ':uid' => $user_id,
        ':cid' => $course_id,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $row): array {
        return [
            'day' => (string)$row['day'],
            'period' => (int)$row['period'],
            'cellKey' => (string)$row['day'] . '_' . (int)$row['period'],
        ];
    }, $rows);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    fail('login required', 401);
}

$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$day = validate_day((string)($_POST['day'] ?? ''));
$period = validate_period((int)($_POST['period'] ?? 0));

if (!$courseId) {
    fail('course_id is required', 400);
}

$course = fetch_course($pdo, $user_id, (int)$courseId);
if (!$course) {
    fail('course_id is invalid', 400);
}

$stmt = $pdo->prepare('
    SELECT COUNT(*)
    FROM timetable_course_slots
    WHERE user_id = :uid
      AND course_id = :cid
');
$stmt->execute([
    ':uid' => $user_id,
    ':cid' => (int)$courseId,
]);
$totalSlots = (int)$stmt->fetchColumn();

if ($totalSlots <= 1) {
    fail('最後の1コマは外せません。授業ごと削除を使ってください。', 400);
}

$stmt = $pdo->prepare('
    DELETE FROM timetable_course_slots
    WHERE user_id = :uid
      AND course_id = :cid
      AND day = :day
      AND period = :period
    LIMIT 1
');
$stmt->execute([
    ':uid'    => $user_id,
    ':cid'    => (int)$courseId,
    ':day'    => $day,
    ':period' => $period,
]);

if ($stmt->rowCount() === 0) {
    fail('指定したコマはこの授業に登録されていません', 404);
}

json_ok([
    'course' => [
        'id' => (int)$course['id'],
        'courseName' => (string)$course['course_name'],
        'room' => (string)($course['room'] ?? ''),
    ],
    'slots' => fetch_slots($pdo, $user_id, (int)$courseId),
    'removedCellKey' => $day . '_' . $period,
]);
