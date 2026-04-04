<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$secrets = '/home/sh-sgmt/google_secrets.php';
if (is_file($secrets)) {
    require_once $secrets;
}

if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
    http_response_code(500);
    exit('Google client secrets are not set');
}

// 新構成では timetable は timetable/timetable.php
$return = (string)($_GET['return'] ?? '../timetable/timetable.php');

if (preg_match('#^https?://#', $return)) {
    $return = '../timetable/timetable.php';
}
if (!str_starts_with($return, '../') && !str_starts_with($return, '/')) {
    $return = '../' . ltrim($return, './');
}

$_SESSION['google_login_state']  = bin2hex(random_bytes(16));
$_SESSION['google_login_return'] = $return;

$client = new Google\Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);

// 新しい配置に合わせる
$client->setRedirectUri('https://sh-sgmt.sakura.ne.jp/php/kadai_app/google/google_login_callback.php');

$client->setScopes(['openid', 'email', 'profile']);
$client->setAccessType('online');
$client->setPrompt('select_account');

$client->setState($_SESSION['google_login_state']);

header('Location: ' . $client->createAuthUrl());
exit;