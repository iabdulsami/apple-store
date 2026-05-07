<?php
/*
=============================================================
  FILE: php/auth_check.php
  PURPOSE:
    GET  ?action=check   → tells JS who is logged in
    POST action=logout   → destroys session
  CALLED FROM: js/script.js on every page load + logout button
=============================================================
*/

ob_start();
session_start();
require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'check') {

    if (isLoggedIn()) {
        jsonResponse([
            'loggedIn' => true,
            'user'     => [
                'id'        => (int)$_SESSION['user_id'],
                'full_name' => $_SESSION['full_name'],
                'email'     => $_SESSION['email'],
                'role'      => $_SESSION['role'],
            ]
        ]);
    } else {
        jsonResponse(['loggedIn' => false]);
    }

} elseif ($action === 'logout') {

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    jsonResponse(['success' => true, 'message' => 'Logged out.']);

} else {
    jsonResponse(['success' => false, 'error' => 'Use ?action=check or POST action=logout'], 400);
}
?>