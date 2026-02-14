<?php
require_once __DIR__ . '/bootstrap.php';
require_post_csrf();

$user_id = (int)$_SESSION['user_id'];
$day    = $_POST['day'] ?? '';
$period = (int)($_POST['period'] ?? 0);

$valid_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
if (!in_array($day, $valid_days, true)) json_ng('day is invalid');
if ($period < 1 || $period > 8) json_ng('period is invalid');

$stmt = $pdo->prepare('
  DELETE FROM timetable_cells
  WHERE user_id = :uid AND day = :day AND period = :period
');
$stmt->execute([':uid' => $user_id, ':day' => $day, ':period' => $period]);

json_ok();
