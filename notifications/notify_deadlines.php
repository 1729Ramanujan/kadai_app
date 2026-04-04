<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;


/*
 * cron 用:
 *   php /path/to/notifications/notify_deadlines.php
 */

$dryRun = false;
if (PHP_SAPI === 'cli' && isset($argv) && in_array('--dry-run', $argv, true)) {
    $dryRun = true;
}

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function buildMessage(string $type, array $task): string
{
    $title = (string)($task['title'] ?? '（無題）');
    $dueAt = !empty($task['due_at'])
        ? date('Y-m-d H:i', strtotime((string)$task['due_at']))
        : '締切なし';

    return match ($type) {
        'deadline_1day'  => "課題「{$title}」は明日締切です（{$dueAt}）",
        'deadline_today' => "課題「{$title}」は今日締切です（{$dueAt}）",
        'overdue'        => "課題「{$title}」は締切を過ぎています（{$dueAt}）",
        default          => "課題「{$title}」の通知です（{$dueAt}）",
    };
}

function insertInAppNotification(PDO $pdo, array $task, string $type, string $message, bool $dryRun): bool
{
    if ($dryRun) {
        return true;
    }

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notifications
            (user_id, task_id, notification_type, channel, message, is_read, is_sent, read_at, sent_at, created_at, updated_at)
        VALUES
            (:user_id, :task_id, :notification_type, 'in_app', :message, 0, 1, NULL, NOW(), NOW(), NOW())
    ");

    $stmt->execute([
        ':user_id'           => (int)$task['user_id'],
        ':task_id'           => (int)$task['id'],
        ':notification_type' => $type,
        ':message'           => $message,
    ]);

    return $stmt->rowCount() > 0;
}

function insertEmailNotification(PDO $pdo, array $task, string $type, string $message, bool $dryRun): bool
{
    $enabled = (int)($task['email_reminder_enabled'] ?? 0);
    $email = trim((string)($task['notify_email'] ?? ''));

    if ($enabled !== 1 || $email === '') {
        return false;
    }

    if ($dryRun) {
        return true;
    }

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notifications
            (user_id, task_id, notification_type, channel, message, is_read, is_sent, read_at, sent_at, created_at, updated_at)
        VALUES
            (:user_id, :task_id, :notification_type, 'email', :message, 0, 0, NULL, NULL, NOW(), NOW())
    ");

    $stmt->execute([
        ':user_id'           => (int)$task['user_id'],
        ':task_id'           => (int)$task['id'],
        ':notification_type' => $type,
        ':message'           => $message,
    ]);

    return $stmt->rowCount() > 0;
}

function sendReminderMail(string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if ($to === '') {
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        logLine('PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

try {
    $now = new DateTimeImmutable('now');
    $todayStart = $now->setTime(0, 0, 0);
    $tomorrowStart = $todayStart->modify('+1 day');
    $dayAfterTomorrowStart = $todayStart->modify('+2 day');

    $paramsToday = [
        ':now' => $now->format('Y-m-d H:i:s'),
        ':tomorrow_start' => $tomorrowStart->format('Y-m-d H:i:s'),
    ];

    $paramsTomorrow = [
        ':tomorrow_start' => $tomorrowStart->format('Y-m-d H:i:s'),
        ':day_after_tomorrow_start' => $dayAfterTomorrowStart->format('Y-m-d H:i:s'),
    ];

    $paramsOverdue = [
        ':now' => $now->format('Y-m-d H:i:s'),
    ];

    $baseSelect = "
        SELECT
            t.id,
            t.user_id,
            t.title,
            t.due_at,
            t.status,
            u.email_reminder_enabled,
            CASE
                WHEN u.reminder_email IS NOT NULL AND u.reminder_email <> ''
                    THEN u.reminder_email
                ELSE u.email
            END AS notify_email
        FROM tasks t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.status = 'open'
          AND t.due_at IS NOT NULL
    ";

    $queries = [
        'deadline_1day' => [
            'sql' => $baseSelect . "
              AND t.due_at >= :tomorrow_start
              AND t.due_at < :day_after_tomorrow_start
            ORDER BY t.due_at ASC, t.id ASC
            ",
            'params' => $paramsTomorrow,
        ],
        'deadline_today' => [
            'sql' => $baseSelect . "
              AND t.due_at >= :now
              AND t.due_at < :tomorrow_start
            ORDER BY t.due_at ASC, t.id ASC
            ",
            'params' => $paramsToday,
        ],
        'overdue' => [
            'sql' => $baseSelect . "
              AND t.due_at < :now
            ORDER BY t.due_at ASC, t.id ASC
            ",
            'params' => $paramsOverdue,
        ],
    ];

    $createdInApp = 0;
    $createdEmail = 0;

    foreach ($queries as $type => $def) {
        $stmt = $pdo->prepare($def['sql']);
        $stmt->execute($def['params']);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        logLine("{$type}: 対象課題 " . count($tasks) . ' 件');

        foreach ($tasks as $task) {
            $message = buildMessage($type, $task);

            $addedInApp = insertInAppNotification($pdo, $task, $type, $message, $dryRun);
            if ($addedInApp) {
                $createdInApp++;
                logLine("in_app 追加: task_id=" . (int)$task['id'] . " / {$type}");
            }

            $addedEmail = insertEmailNotification($pdo, $task, $type, $message, $dryRun);
            if ($addedEmail) {
                $createdEmail++;
                logLine("email 通知追加: task_id=" . (int)$task['id'] . " / {$type}");
            }
        }
    }

    logLine("通知作成完了: in_app={$createdInApp}, email={$createdEmail}");

    $stmtMail = $pdo->prepare("
        SELECT
            n.id,
            n.user_id,
            n.task_id,
            n.notification_type,
            n.message,
            t.title,
            t.due_at,
            t.status,
            u.email_reminder_enabled,
            CASE
                WHEN u.reminder_email IS NOT NULL AND u.reminder_email <> ''
                    THEN u.reminder_email
                ELSE u.email
            END AS notify_email
        FROM notifications n
        INNER JOIN users u ON u.id = n.user_id
        INNER JOIN tasks t ON t.id = n.task_id
        WHERE n.channel = 'email'
          AND n.is_sent = 0
          AND t.status = 'open'
        ORDER BY n.id ASC
    ");
    $stmtMail->execute();
    $mailTargets = $stmtMail->fetchAll(PDO::FETCH_ASSOC);

    logLine('未送信メール通知: ' . count($mailTargets) . ' 件');

    $sentCount = 0;
    $failedCount = 0;

    foreach ($mailTargets as $row) {
        $enabled = (int)($row['email_reminder_enabled'] ?? 0);
        $to = trim((string)($row['notify_email'] ?? ''));

        if ($enabled !== 1) {
            logLine("メール送信スキップ: notification_id=" . (int)$row['id'] . " / 通知OFF");
            continue;
        }

        if ($to === '') {
            $failedCount++;
            logLine("メール送信スキップ: notification_id=" . (int)$row['id'] . " / emailなし");
            continue;
        }

        $title = (string)($row['title'] ?? '（無題）');
        $dueAt = !empty($row['due_at'])
            ? date('Y-m-d H:i', strtotime((string)$row['due_at']))
            : '締切なし';

        $subject = '[課題リマインド] ' . $title;
        $body = implode("\n", [
            '時間割アプリからのお知らせです。',
            '',
            (string)$row['message'],
            '',
            '課題名: ' . $title,
            '締切: ' . $dueAt,
            '',
            '時間割アプリにログインして確認してください。',
        ]);

        if ($dryRun) {
            logLine("DRY RUN: メール送信予定 notification_id=" . (int)$row['id'] . " / {$to}");
            continue;
        }

        $ok = sendReminderMail($to, $subject, $body);

        if ($ok) {
            $upd = $pdo->prepare("
                UPDATE notifications
                SET is_sent = 1,
                    sent_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $upd->execute([':id' => (int)$row['id']]);

            $sentCount++;
            logLine("メール送信成功: notification_id=" . (int)$row['id'] . " / {$to}");
        } else {
            $failedCount++;
            logLine("メール送信失敗: notification_id=" . (int)$row['id'] . " / {$to}");
        }
    }

    logLine("メール処理完了: success={$sentCount}, failed={$failedCount}");
    exit(0);
} catch (Throwable $e) {
    logLine('ERROR: ' . $e->getMessage());
    exit(1);
}
