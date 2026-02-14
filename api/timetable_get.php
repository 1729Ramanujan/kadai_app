<?php
require_once __DIR__ . '/bootstrap.php';

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('
  SELECT day, period, course_name, start_time, end_time
  FROM timetable_cells
  WHERE user_id = :uid
');
$stmt->execute([':uid' => $user_id]);
$rows = $stmt->fetchAll();

$map = [];
foreach ($rows as $r) {
    $key = $r['day'] . '_' . (int)$r['period']; // mon_1 など
    $map[$key] = [
        'day' => $r['day'],
        'period' => (int)$r['period'],
        'courseName' => $r['course_name'],
        'start' => $r['start_time'] ? substr($r['start_time'], 0, 5) : null, // HH:MM
        'end'   => $r['end_time'] ? substr($r['end_time'], 0, 5) : null,
    ];
}

json_ok(['cells' => $map]);
