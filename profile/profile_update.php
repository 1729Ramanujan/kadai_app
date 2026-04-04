<?php
require_once __DIR__ . '/../config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('CSRF invalid');
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['action'] ?? 'university');

/* -------------------------
   通知設定の保存
------------------------- */
if ($action === 'notification_settings') {
    $email_enabled = !empty($_POST['email_reminder_enabled']) ? 1 : 0;
    $reminder_email = trim((string)($_POST['reminder_email'] ?? ''));

    if ($reminder_email !== '' && !filter_var($reminder_email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        exit('Invalid email');
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET email_reminder_enabled = :enabled,
            reminder_email = :reminder_email
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->bindValue(':enabled', $email_enabled, PDO::PARAM_INT);

    if ($reminder_email === '') {
        $stmt->bindValue(':reminder_email', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':reminder_email', $reminder_email, PDO::PARAM_STR);
    }

    $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    header('Location: profile.php');
    exit;
}

/* -------------------------
   時限設定の保存
------------------------- */
if ($action === 'period_settings') {
    $periodValues = [];

    for ($i = 1; $i <= 6; $i++) {
        $startKey = "period{$i}_start";
        $endKey   = "period{$i}_end";

        $start = trim((string)($_POST[$startKey] ?? ''));
        $end   = trim((string)($_POST[$endKey] ?? ''));

        if ($start === '' && $end === '') {
            $periodValues[$startKey] = null;
            $periodValues[$endKey] = null;
            continue;
        }

        if ($start === '' || $end === '') {
            http_response_code(400);
            exit("{$i}限は開始・終了の両方を入力してください");
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            http_response_code(400);
            exit("{$i}限の時刻形式が不正です");
        }

        if ($start >= $end) {
            http_response_code(400);
            exit("{$i}限の開始時刻は終了時刻より前にしてください");
        }

        $periodValues[$startKey] = $start . ':00';
        $periodValues[$endKey]   = $end . ':00';
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET
            period1_start = :period1_start,
            period1_end   = :period1_end,
            period2_start = :period2_start,
            period2_end   = :period2_end,
            period3_start = :period3_start,
            period3_end   = :period3_end,
            period4_start = :period4_start,
            period4_end   = :period4_end,
            period5_start = :period5_start,
            period5_end   = :period5_end,
            period6_start = :period6_start,
            period6_end   = :period6_end
        WHERE id = :id
        LIMIT 1
    ");

    foreach ($periodValues as $key => $value) {
        if ($value === null) {
            $stmt->bindValue(":{$key}", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":{$key}", $value, PDO::PARAM_STR);
        }
    }

    $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
    $stmt->execute();

    header('Location: profile.php');
    exit;
}

/* -------------------------
   大学設定の保存
------------------------- */
$university_id = (int)($_POST['university_id'] ?? 0);

$selectedUniversityId = null;
$selectedUniversityName = '';

if ($university_id > 0) {
    $stmt = $pdo->prepare('SELECT id, name FROM universities WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $university_id]);
    $uni = $stmt->fetch();

    if (!$uni) {
        http_response_code(400);
        exit('Invalid university');
    }

    $selectedUniversityId = (int)$uni['id'];
    $selectedUniversityName = (string)$uni['name'];
}

$stmt = $pdo->prepare(
    'UPDATE users
     SET university_id = :university_id,
         university = :university
     WHERE id = :id
     LIMIT 1'
);

if ($selectedUniversityId === null) {
    $stmt->bindValue(':university_id', null, PDO::PARAM_NULL);
} else {
    $stmt->bindValue(':university_id', $selectedUniversityId, PDO::PARAM_INT);
}

$stmt->bindValue(':university', $selectedUniversityName, PDO::PARAM_STR);
$stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();

header('Location: profile.php');
exit;