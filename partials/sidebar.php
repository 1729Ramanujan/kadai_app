<?php
/*
 * 今の構成では sidebar を読む画面は
 * auth を除いてすべて 1 階層下にある前提
 */
$navBase = '../';

$currentPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');

$isTimetablePage = (strpos($currentPath, '/timetable/') !== false);
$isTasksPage     = (strpos($currentPath, '/tasks/') !== false);
$isProfilePage   = (strpos($currentPath, '/profile/') !== false);

$lmsUrl = trim((string)($me['lms_url'] ?? ''));
?>

<!-- <style>
    .sideNavItem.isActive {
        background: rgba(34, 197, 94, 0.12);
        color: #166534;
        font-weight: 700;
    }

    .sideNavItem.isActive .sideNavIcon {
        color: inherit;
    }
</style> -->

<aside class="sideNav" id="sideNav" aria-label="ナビゲーション">
    <a
        class="sideNavItem<?= $isTimetablePage ? ' isActive' : '' ?>"
        href="<?= h($navBase) ?>timetable/timetable.php"
        <?php if ($isTimetablePage): ?>aria-current="page" <?php endif; ?>>
        <i data-lucide="house" class="sideNavIcon" aria-hidden="true"></i>
        <span class="sideNavLabel">時間割</span>
    </a>

    <a
        class="sideNavItem<?= $isTasksPage ? ' isActive' : '' ?>"
        href="<?= h($navBase) ?>tasks/tasks_all.php"
        <?php if ($isTasksPage): ?>aria-current="page" <?php endif; ?>>
        <i data-lucide="list-todo" class="sideNavIcon" aria-hidden="true"></i>
        <span class="sideNavLabel">課題一覧</span>
    </a>

    <?php if ($lmsUrl !== ''): ?>
        <a class="sideNavItem" href="<?= h($lmsUrl) ?>" target="_blank" rel="noopener noreferrer">
            <i data-lucide="graduation-cap" class="sideNavIcon" aria-hidden="true"></i>
            <span class="sideNavLabel">LMSへ移動</span>
        </a>
    <?php else: ?>
        <a
            class="sideNavItem<?= $isProfilePage ? ' isActive' : '' ?>"
            href="<?= h($navBase) ?>profile/profile.php"
            <?php if ($isProfilePage): ?>aria-current="page" <?php endif; ?>>
            <i data-lucide="graduation-cap" class="sideNavIcon" aria-hidden="true"></i>
            <span class="sideNavLabel">大学を設定</span>
        </a>
    <?php endif; ?>

    <a
        class="sideNavItem<?= $isProfilePage ? ' isActive' : '' ?>"
        href="<?= h($navBase) ?>profile/profile.php"
        <?php if ($isProfilePage): ?>aria-current="page" <?php endif; ?>>
        <i data-lucide="user" class="sideNavIcon" aria-hidden="true"></i>
        <span class="sideNavLabel">プロフィール</span>
    </a>

    <a class="sideNavItem" href="<?= h($navBase) ?>auth/logout.php">
        <i data-lucide="log-out" class="sideNavIcon" aria-hidden="true"></i>
        <span class="sideNavLabel">ログアウト</span>
    </a>
</aside>