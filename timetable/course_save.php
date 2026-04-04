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
        SELECT id, user_id, course_name, room
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

function find_course_id_by_slot(PDO $pdo, int $user_id, string $day, int $period): ?int
{
    $stmt = $pdo->prepare('
        SELECT course_id
        FROM timetable_course_slots
        WHERE user_id = :uid
          AND day = :day
          AND period = :period
        LIMIT 1
    ');
    $stmt->execute([
        ':uid' => $user_id,
        ':day' => $day,
        ':period' => $period,
    ]);
    $courseId = $stmt->fetchColumn();
    return $courseId !== false ? (int)$courseId : null;
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

$day = validate_day((string)($_POST['day'] ?? ''));
$period = validate_period((int)($_POST['period'] ?? 0));
$courseName = trim((string)($_POST['courseName'] ?? ''));
$room = trim((string)($_POST['room'] ?? ''));
$postedCourseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);

if ($courseName === '') {
    fail('courseName is required', 400);
}

$room = ($room !== '') ? $room : null;

try {
    $pdo->beginTransaction();

    $targetCourseId = null;

    if ($postedCourseId) {
        $course = fetch_course($pdo, $user_id, (int)$postedCourseId);
        if (!$course) {
            throw new RuntimeException('course_id is invalid', 400);
        }

        $occupiedCourseId = find_course_id_by_slot($pdo, $user_id, $day, $period);
        if ($occupiedCourseId !== null && $occupiedCourseId !== (int)$postedCourseId) {
            throw new RuntimeException('このコマには別の授業が登録されています', 409);
        }

        $stmt = $pdo->prepare('
            UPDATE timetable_courses
            SET course_name = :name,
                room = :room
            WHERE user_id = :uid
              AND id = :cid
            LIMIT 1
        ');
        $stmt->execute([
            ':name' => $courseName,
            ':room' => $room,
            ':uid'  => $user_id,
            ':cid'  => (int)$postedCourseId,
        ]);

        if ($occupiedCourseId === null) {
            $stmt = $pdo->prepare('
                INSERT INTO timetable_course_slots (user_id, course_id, day, period)
                VALUES (:uid, :cid, :day, :period)
            ');
            $stmt->execute([
                ':uid'    => $user_id,
                ':cid'    => (int)$postedCourseId,
                ':day'    => $day,
                ':period' => $period,
            ]);
        }

        $targetCourseId = (int)$postedCourseId;
    } else {
        $occupiedCourseId = find_course_id_by_slot($pdo, $user_id, $day, $period);

        if ($occupiedCourseId !== null) {
            $stmt = $pdo->prepare('
                UPDATE timetable_courses
                SET course_name = :name,
                    room = :room
                WHERE user_id = :uid
                  AND id = :cid
                LIMIT 1
            ');
            $stmt->execute([
                ':name' => $courseName,
                ':room' => $room,
                ':uid'  => $user_id,
                ':cid'  => $occupiedCourseId,
            ]);

            $targetCourseId = $occupiedCourseId;
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO timetable_courses (user_id, course_name, room)
                VALUES (:uid, :name, :room)
            ');
            $stmt->execute([
                ':uid'  => $user_id,
                ':name' => $courseName,
                ':room' => $room,
            ]);
            $targetCourseId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('
                INSERT INTO timetable_course_slots (user_id, course_id, day, period)
                VALUES (:uid, :cid, :day, :period)
            ');
            $stmt->execute([
                ':uid'    => $user_id,
                ':cid'    => $targetCourseId,
                ':day'    => $day,
                ':period' => $period,
            ]);
        }
    }

    $pdo->commit();

    $course = fetch_course($pdo, $user_id, $targetCourseId);
    $slots = fetch_slots($pdo, $user_id, $targetCourseId);

    json_ok([
        'course' => [
            'id' => (int)$course['id'],
            'courseName' => (string)$course['course_name'],
            'room' => (string)($course['room'] ?? ''),
        ],
        'slots' => $slots,
        'selectedCellKey' => $day . '_' . $period,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $code = (int)$e->getCode();
    if ($code < 400 || $code >= 600) {
        $code = 500;
    }

    json_ng($e->getMessage() !== '' ? $e->getMessage() : 'save failed', $code);
}
