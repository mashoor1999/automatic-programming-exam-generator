<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/Data/db.php';
require_once __DIR__ . '/Data/config.php';

$email    = trim((string)($_POST['email'] ?? ''));
$password = trim((string)($_POST['password'] ?? ''));

if ($email === '' || $password === '') {
    $_SESSION['login_error'] = 'Email and password are required.';
    $_SESSION['old_email']   = $email;
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            first_name,
            last_name,
            full_name,
            email,
            password,
            role
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['login_error'] = 'Invalid email or password.';
        $_SESSION['old_email']   = $email;
        header('Location: index.php');
        exit;
    }

    $enteredHash = md5(PASS_PEPPER . $password);
    $storedHash  = (string)($user['password'] ?? '');

    if (!hash_equals($storedHash, $enteredHash)) {
        $_SESSION['login_error'] = 'Invalid email or password.';
        $_SESSION['old_email']   = $email;
        header('Location: index.php');
        exit;
    }

    session_regenerate_id(true);

    $firstName = trim((string)($user['first_name'] ?? ''));
    $lastName  = trim((string)($user['last_name'] ?? ''));
    $fullName  = trim($firstName . ' ' . $lastName);

    if ($fullName === '') {
        $fullName = trim((string)($user['full_name'] ?? 'User'));
    }

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['id']         = (int)$user['id'];
    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name']  = $lastName;
    $_SESSION['full_name']  = $fullName;
    $_SESSION['email']      = (string)$user['email'];
    $_SESSION['role']       = (string)$user['role'];

    header('Location: dashboard.php');
    exit;

} catch (Throwable $e) {
    $_SESSION['login_error'] = 'Login failed. Please try again.';
    $_SESSION['old_email']   = $email;
    header('Location: index.php');
    exit;
}