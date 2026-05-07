
<?php
/*
=============================================================
  FILE: php/config.php
  PURPOSE: Shared settings, database connection, helper functions.
  RULE: NEVER echo/print/header() at top level of this file.
        NEVER call session_start() here.
        Each PHP file calls session_start() itself FIRST,
        then require_once 'config.php'
=============================================================
*/

// ── Database ──────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');            // blank = default XAMPP
define('DB_NAME', 'apple_store');

// ── Site ──────────────────────────────────────────────────
define('SITE_NAME', 'Apple Store');
define('SITE_URL',  'http://localhost/apple-store');

// ── Cart / Order math ─────────────────────────────────────
define('TAX_RATE',                0.05);
define('FREE_SHIPPING_THRESHOLD', 100.00);
define('SHIPPING_COST',           15.00);

/*
  getDB() — returns a MySQL connection (creates it only once per request)
*/
function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode([
                'success' => false,
                'error'   => 'Database connection failed — check php/config.php',
                'detail'  => $conn->connect_error
            ]));
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/*
  sanitize($v) — strips tags & special chars from user input
*/
function sanitize($v) {
    return htmlspecialchars(strip_tags(trim($v ?? '')), ENT_QUOTES, 'UTF-8');
}

/*
  jsonResponse($data, $code) — send JSON and stop execution
*/
function jsonResponse($data, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Session helpers ───────────────────────────────────────
function isLoggedIn() {
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function isAdmin() {
    return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'error' => 'Login required.', 'auth_required' => true], 401);
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        jsonResponse(['success' => false, 'error' => 'Admin access required.'], 403);
    }
}

// ── Helpers ───────────────────────────────────────────────
function generateOrderNumber() {
    return 'ORD-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}
?>
