<?php
require_once __DIR__ . '/../config.php';
require_login();

$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT 
        us.id,
        us.email,
        us.password_hash,
        us.email_reminder_enabled,
        us.reminder_email,
        us.university,
        us.university_id,
        us.google_email,
        us.google_sub,
        us.period1_start,
        us.period1_end,
        us.period2_start,
        us.period2_end,
        us.period3_start,
        us.period3_end,
        us.period4_start,
        us.period4_end,
        us.period5_start,
        us.period5_end,
        us.period6_start,
        us.period6_end,
        uni.name AS university_name,
        uni.lms_url
     FROM users us
     LEFT JOIN universities uni ON uni.id = us.university_id
     WHERE us.id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $user_id]);
$me = $stmt->fetch();

if (!$me) {
    http_response_code(404);
    exit('user not found');
}

$uniStmt = $pdo->query('SELECT id, name, lms_url FROM universities ORDER BY name ASC');
$universities = $uniStmt->fetchAll();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$canResetPassword = !empty($me['password_hash']);
$isGoogleOnly = !$canResetPassword && (!empty($me['google_sub']) || !empty($me['google_email']));

$resetSent = (($_GET['reset_sent'] ?? '') === '1');
$resetError = (string)($_GET['reset_error'] ?? '');
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プロフィール</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
    <link rel="stylesheet" href="<?= h(base_url('assets/css/app_layout.css')) ?>">
</head>

<body class="page--withAppHeader profilePage">
    <div class="appShell appShell--withHeader">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="appBody">
            <div class="sideNavSlot">
                <?php include __DIR__ . '/../partials/sidebar.php'; ?>
            </div>

            <div class="pageMain">
                <div class="card profileSettingsCard">
                    <h3>プロフィール設定</h3>

                    <div class="profileSettingBlock">
                        <p class="profileSettingLabel">登録メールアドレス</p>
                        <p class="profileAccountEmail">
                            <strong><?= h((string)$me['email']) ?></strong>
                        </p>
                        <p class="muted profileHelp">
                            パスワード再設定メールや通知の既定送信先として使用されます。
                        </p>
                    </div>

                    <?php if ($resetSent): ?>
                        <div class="profileStatus profileStatus--success">
                            パスワード再設定リンクを登録メールアドレスに送信しました。
                        </div>
                    <?php endif; ?>

                    <?php if ($resetError === 'google_only'): ?>
                        <div class="profileStatus profileStatus--error">
                            このアカウントはGoogleログイン専用のため、パスワード再設定は利用できません。
                        </div>
                    <?php elseif ($resetError === 'send_failed'): ?>
                        <div class="profileStatus profileStatus--error">
                            再設定メールの送信に失敗しました。時間をおいて再度お試しください。
                        </div>
                    <?php elseif ($resetError === 'invalid_request'): ?>
                        <div class="profileStatus profileStatus--error">
                            無効なリクエストです。もう一度お試しください。
                        </div>
                    <?php endif; ?>

                    <div class="profileSettingBlock">
                        <p class="profileSettingLabel">パスワード</p>

                        <?php if ($canResetPassword): ?>
                            <p class="muted">
                                登録メールアドレスに、パスワード再設定用のリンクを送信できます。
                            </p>

                            <form action="send_password_reset.php" method="post" class="form">
                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                <div class="buttonRow">
                                    <button type="submit" class="secondary">パスワード再設定リンクを送る</button>
                                </div>
                            </form>
                        <?php elseif ($isGoogleOnly): ?>
                            <p class="muted">
                                このアカウントはGoogleログイン専用のため、メールアドレスでのパスワード再設定は利用できません。
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="profileDangerZone">
                        <p class="profileSettingLabel profileSettingLabel--danger">アカウント削除</p>
                        <p class="muted">
                            退会すると、あなたの課題・時間割・添付ファイル・AI出力などのデータを削除します。
                        </p>

                        <form action="account_delete.php" method="post"
                            onsubmit="return confirm('退会します。保存したデータは削除され、元に戻せません。本当に実行しますか？');">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                            <div class="buttonRow">
                                <button type="submit" class="danger">退会（アカウント削除）</button>
                            </div>
                        </form>
                    </div>
                </div>

                <hr class="divider">
                
                <div class="card">
                    <h3>授業時間</h3>
                    <p class="muted profileHelp">
                        ここで設定した時間が、時間割画面の左列に 1〜6限の固定時刻として表示されます。
                    </p>

                    <form action="profile_update.php" method="post" class="form profileForm">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="period_settings">

                        <div class="timeRow">
                            <label>1限 開始
                                <input type="time" name="period1_start" value="<?= h(!empty($me['period1_start']) ? substr((string)$me['period1_start'], 0, 5) : '') ?>">
                            </label>
                            <label>1限 終了
                                <input type="time" name="period1_end" value="<?= h(!empty($me['period1_end']) ? substr((string)$me['period1_end'], 0, 5) : '') ?>">
                            </label>
                        </div>

                        <div class="timeRow">
                            <label>2限 開始
                                <input type="time" name="period2_start" value="<?= h(!empty($me['period2_start']) ? substr((string)$me['period2_start'], 0, 5) : '') ?>">
                            </label>
                            <label>2限 終了
                                <input type="time" name="period2_end" value="<?= h(!empty($me['period2_end']) ? substr((string)$me['period2_end'], 0, 5) : '') ?>">
                            </label>
                        </div>

                        <div class="timeRow">
                            <label>3限 開始
                                <input type="time" name="period3_start" value="<?= h(!empty($me['period3_start']) ? substr((string)$me['period3_start'], 0, 5) : '') ?>">
                            </label>
                            <label>3限 終了
                                <input type="time" name="period3_end" value="<?= h(!empty($me['period3_end']) ? substr((string)$me['period3_end'], 0, 5) : '') ?>">
                            </label>
                        </div>

                        <div class="timeRow">
                            <label>4限 開始
                                <input type="time" name="period4_start" value="<?= h(!empty($me['period4_start']) ? substr((string)$me['period4_start'], 0, 5) : '') ?>">
                            </label>
                            <label>4限 終了
                                <input type="time" name="period4_end" value="<?= h(!empty($me['period4_end']) ? substr((string)$me['period4_end'], 0, 5) : '') ?>">
                            </label>
                        </div>

                        <div class="timeRow">
                            <label>5限 開始
                                <input type="time" name="period5_start" value="<?= h(!empty($me['period5_start']) ? substr((string)$me['period5_start'], 0, 5) : '') ?>">
                            </label>
                            <label>5限 終了
                                <input type="time" name="period5_end" value="<?= h(!empty($me['period5_end']) ? substr((string)$me['period5_end'], 0, 5) : '') ?>">
                            </label>
                        </div>

                        <div class="timeRow">
                            <label>6限 開始
                                <input type="time" name="period6_start" value="<?= h(!empty($me['period6_start']) ? substr((string)$me['period6_start'], 0, 5) : '') ?>">
                            </label>
                            <label>6限 終了
                                <input type="time" name="period6_end" value="<?= h(!empty($me['period6_end']) ? substr((string)$me['period6_end'], 0, 5) : '') ?>">
                            </label>
                        </div>

                        <div class="buttonRow">
                            <button type="submit">授業時間を保存</button>
                        </div>
                    </form>
                </div>

                <hr class="divider">

                <div class="card">
                    <h3>所属大学</h3>

                    <?php if (empty($me['university_id']) && !empty($me['university'])): ?>
                        <p class="muted">
                            現在は旧方式の大学名「<?= h($me['university']) ?>」が保存されています。<br>
                            下の選択欄から大学を選び直すと、LMSリンク機能が使えるようになります。
                        </p>
                    <?php endif; ?>

                    <form action="profile_update.php" method="post" class="form">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

                        <label>大学名
                            <select name="university_id">
                                <option value="">選択してください</option>
                                <?php foreach ($universities as $uni): ?>
                                    <option value="<?= (int)$uni['id'] ?>"
                                        <?= ((int)($me['university_id'] ?? 0) === (int)$uni['id']) ? 'selected' : '' ?>>
                                        <?= h($uni['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <?php if (!empty($me['lms_url'])): ?>
                            <p class="muted">
                                現在のLMSリンク：
                                <a href="<?= h($me['lms_url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h($me['lms_url']) ?>
                                </a>
                            </p>
                        <?php endif; ?>

                        <div class="buttonRow">
                            <button type="submit">保存</button>
                        </div>
                    </form>
                </div>

                <hr class="divider">

                <div class="card">
                    <h3>通知設定</h3>

                    <form action="profile_update.php" method="post" class="form profileForm">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="notification_settings">

                        <label class="profileToggle">
                            <input
                                class="profileCheckbox"
                                type="checkbox"
                                name="email_reminder_enabled"
                                value="1"
                                <?= !empty($me['email_reminder_enabled']) ? 'checked' : '' ?>>
                            <span>メールで締切通知を受け取る</span>
                        </label>

                        <label class="profileFieldLabel">通知先メールアドレス
                            <input
                                type="email"
                                name="reminder_email"
                                placeholder="未入力なら登録メールアドレスを使用"
                                value="<?= h((string)($me['reminder_email'] ?? '')) ?>">
                        </label>

                        <p class="muted profileHelp">
                            未入力の場合は、登録中のメールアドレス
                            <strong><?= h((string)$me['email']) ?></strong>
                            に通知します。
                        </p>

                        <div class="buttonRow">
                            <button type="submit">通知設定を保存</button>
                        </div>
                    </form>
                </div>

                <hr class="divider">

                <div class="card">
                    <h3>連携しているGoogleアカウント</h3>
                    <?php if (!empty($me['google_email'])): ?>
                        <p><strong><?= h($me['google_email']) ?></strong></p>
                        <form action="../google/google_disconnect.php" method="post" style="margin-top:8px;">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                            <button type="submit" class="secondary">Google連携を解除</button>
                        </form>
                    <?php else: ?>
                        <p class="muted">未連携</p>
                        <a class="button" href="../google/google_connect.php?return=../profile/profile.php">Google連携する</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <script src="https://unpkg.com/lucide@0.577.0/dist/umd/lucide.min.js"></script>
        <script>
            lucide.createIcons();
        </script>
    </div>
</body>

</html>