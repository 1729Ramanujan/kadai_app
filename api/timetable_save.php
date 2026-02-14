<?php
require_once __DIR__ . '/bootstrap.php';
require_post_csrf();

$user_id = (int)$_SESSION['user_id'];

$day    = $_POST['day'] ?? '';
$period = (int)($_POST['period'] ?? 0);
$name   = trim((string)($_POST['courseName'] ?? ''));
$start  = $_POST['start'] ?? null; // "HH:MM"
$end    = $_POST['end'] ?? null;

$valid_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
if (!in_array($day, $valid_days, true)) json_ng('day is invalid');
if ($period < 1 || $period > 8) json_ng('period is invalid'); // あなたのコマ数に合わせて調整
if ($name === '') json_ng('courseName is required');

$start = $start ?: null;
$end   = $end   ?: null;

$stmt = $pdo->prepare('
  INSERT INTO timetable_cells (user_id, day, period, course_name, start_time, end_time)
  VALUES (:uid, :day, :period, :name, :start, :end)
  ON DUPLICATE KEY UPDATE
    course_name = VALUES(course_name),
    start_time = VALUES(start_time),
    end_time = VALUES(end_time)
');
$stmt->execute([
    ':uid' => $user_id,
    ':day' => $day,
    ':period' => $period,
    ':name' => $name,
    ':start' => $start,
    ':end' => $end,
]);

json_ok();
