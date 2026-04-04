<?php
$announceUnreadCount = 0;

if (isset($pdo, $_SESSION['user_id'])) {
    try {
        $stmtAnn = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :uid
              AND channel = 'in_app'
              AND is_read = 0
        ");
        $stmtAnn->execute([
            ':uid' => (int)($_SESSION['user_id'] ?? 0),
        ]);
        $announceUnreadCount = (int)$stmtAnn->fetchColumn();
    } catch (Throwable $e) {
        $announceUnreadCount = 0;
    }
}

$announceIcon = $announceUnreadCount > 0 ? 'bell-ring' : 'bell';
$badgeText = $announceUnreadCount > 99 ? '99+' : (string)$announceUnreadCount;

/*
 * 今の構成では header を読む画面は
 * auth を除いてすべて 1 階層下にある前提
 */
$navBase = '../';
?>

<header class="appHeader">
    <div class="appHeaderInner">
        <a class="appHeaderLogo" href="<?= h($navBase) ?>timetable/timetable.php" aria-label="kadAI ホームへ">
            <span class="appHeaderLogoText">
                <span>kad</span><span class="appHeaderLogoAccent">AI</span>
            </span>
        </a>

        <div class="appHeaderActions">
            <a class="appHeaderBell" href="<?= h($navBase) ?>notifications/announce.php" aria-label="お知らせ">
                <i data-lucide="<?= h($announceIcon) ?>" class="appHeaderBellIcon" aria-hidden="true"></i>
                <span class="appHeaderBellLabel">お知らせ</span>
                <?php if ($announceUnreadCount > 0): ?>
                    <span class="appHeaderBellBadge"><?= h($badgeText) ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>