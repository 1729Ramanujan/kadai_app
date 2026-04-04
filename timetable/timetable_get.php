<?php
require_once __DIR__ . '/../bootstrap.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    json_ng('login required', 401);
}

$stmt = $pdo->prepare('
    SELECT
        s.day,
        s.period,
        c.id AS course_id,
        c.course_name,
        c.room,
        SUM(
            CASE
                WHEN t.id IS NOT NULL
                 AND LOWER(COALESCE(t.status, "")) <> "done"
                THEN 1
                ELSE 0
            END
        ) AS open_task_count,
        MIN(
            CASE
                WHEN t.due_at IS NOT NULL
                 AND LOWER(COALESCE(t.status, "")) <> "done"
                THEN t.due_at
                ELSE NULL
            END
        ) AS nearest_due_at
    FROM timetable_course_slots s
    INNER JOIN timetable_courses c
      ON c.id = s.course_id
     AND c.user_id = s.user_id
    LEFT JOIN tasks t
      ON t.user_id = s.user_id
     AND t.course_id = c.id
    WHERE s.user_id = :uid
    GROUP BY
        s.day,
        s.period,
        c.id,
        c.course_name,
        c.room
    ORDER BY
        FIELD(s.day, "mon", "tue", "wed", "thu", "fri", "sat"),
        s.period ASC
');
$stmt->execute([
    ':uid' => $user_id,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$map = [];

foreach ($rows as $r) {
    $day = (string)($r['day'] ?? '');
    $period = (int)($r['period'] ?? 0);

    if ($day === '' || $period <= 0) {
        continue;
    }

    $key = $day . '_' . $period;

    $map[$key] = [
        'day'           => $day,
        'period'        => $period,
        'courseId'      => (int)($r['course_id'] ?? 0),
        'courseName'    => (string)($r['course_name'] ?? ''),
        'room'          => (string)($r['room'] ?? ''),
        'openTaskCount' => (int)($r['open_task_count'] ?? 0),
        'nearestDueAt'  => !empty($r['nearest_due_at'])
            ? date('Y-m-d\TH:i', strtotime((string)$r['nearest_due_at']))
            : null,
    ];
}

json_ok(['cells' => $map]);