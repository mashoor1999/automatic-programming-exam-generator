<?php
declare(strict_types=1);

/*
|  1800 = 30 دقيقة
*/
const SESSION_TIMEOUT_SECONDS = 1800;

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_TIMEOUT_SECONDS);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function destroyUserSessionAndRedirect(string $location = 'index.php'): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
    header('Location: ' . $location);
    exit;
}

/*
|--------------------------------------------------------------------------
| Check Login
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: index.php');
    exit;
}

$now = time();

if (isset($_SESSION['last_activity']) && is_numeric($_SESSION['last_activity'])) {
    $inactiveSeconds = $now - (int)$_SESSION['last_activity'];

    if ($inactiveSeconds > SESSION_TIMEOUT_SECONDS) {
        destroyUserSessionAndRedirect('index.php?timeout=1');
    }
}

/*
|  Update Last Activity Time
*/
$_SESSION['last_activity'] = $now;

/*
| Regenerate Session ID Periodically
| حماية إضافية
*/
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = $now;
} elseif (($now - (int)$_SESSION['last_regeneration']) > 300) { 
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = $now;
}