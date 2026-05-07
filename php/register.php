<?php
/*
=============================================================
  FILE: php/register.php
  HOW IT WORKS:
    JS sends: full_name, email, password, phone via POST
    We validate → check duplicate email → hash password
    → insert into DB → set session → return JSON
  CALLED FROM: js/script.js registerSubmit button
=============================================================
*/

ob_start();
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST method required.'], 405);
}

// Read fields
$fullName = sanitize($_POST['full_name'] ?? '');
$email    = trim($_POST['email']         ?? '');
$password =       $_POST['password']     ?? '';
$phone    = sanitize($_POST['phone']     ?? '');

// Validate
if (empty($fullName)) {
    jsonResponse(['success' => false, 'error' => 'Full name is required.'], 400);
}
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'A valid email address is required.'], 400);
}
if (strlen($password) < 6) {
    jsonResponse(['success' => false, 'error' => 'Password must be at least 6 characters.'], 400);
}

$db = getDB();

// Check if email already taken
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    jsonResponse(['success' => false, 'error' => 'This email is already registered. Please log in.'], 409);
}
$stmt->close();

// Hash password — bcrypt, never store plain text
$hash = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$stmt = $db->prepare(
    "INSERT INTO users (full_name, email, password, phone, role) VALUES (?, ?, ?, ?, 'customer')"
);
$stmt->bind_param('ssss', $fullName, $email, $hash, $phone);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(['success' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

$newId = $db->insert_id;
$stmt->close();

// Auto-login: set session right away
$_SESSION['user_id']   = $newId;
$_SESSION['full_name'] = $fullName;
$_SESSION['email']     = $email;
$_SESSION['role']      = 'customer';

jsonResponse([
    'success' => true,
    'user'    => [
        'id'        => $newId,
        'full_name' => $fullName,
        'email'     => $email,
        'role'      => 'customer',
    ]
]);
?>