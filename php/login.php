<?php
/*
=============================================================
  FILE: php/login.php
  HOW IT WORKS:
    JS sends: email + password via POST
    We check DB → verify password → set session → return JSON
  CALLED FROM: js/script.js loginSubmit button
=============================================================
*/

// session_start() MUST come before require_once config.php
// because config.php must NOT output anything before session starts
ob_start();               // buffer output so no accidental whitespace breaks headers
session_start();          // start the session FIRST
require_once 'config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST method required.'], 405);
}

// Read fields
$email    = trim($_POST['email']    ?? '');
$password =       $_POST['password'] ?? '';

// Validate
if (empty($email) || empty($password)) {
    jsonResponse(['success' => false, 'error' => 'Email and password are required.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Invalid email format.'], 400);
}

// Look up user
$db   = getDB();
$stmt = $db->prepare("SELECT id, full_name, email, password, role FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Verify password (bcrypt)
if (!$user) {
    jsonResponse([
        'success' => false,
        'error' => 'User not found',
        'email_sent' => $email
    ]);
}

// Set session
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];

jsonResponse([
    'success' => true,
    'user'    => [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ]
]);
?>