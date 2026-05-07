<?php
/*
=============================================================
  FILE: php/checkout.php
  ACTIONS:
    place  → create order from cart, clear cart
    list   → get all orders for current user
    detail → get one order with all its items
=============================================================
*/

ob_start();
session_start();
require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'place':  placeOrder();  break;
    case 'list':   listOrders();  break;
    case 'detail': orderDetail(); break;
    default: jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
}

// ── Place order ──────────────────────────────────────────
function placeOrder() {
    requireLogin();
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];

    $address = sanitize($_POST['address']        ?? '');
    $city    = sanitize($_POST['city']           ?? '');
    $payment = sanitize($_POST['payment_method'] ?? 'COD');
    $notes   = sanitize($_POST['notes']          ?? '');

    if (empty($address) || empty($city)) {
        jsonResponse(['success' => false, 'error' => 'Address and city are required.'], 400);
    }

    // Load cart
    $stmt = $db->prepare("
        SELECT c.quantity, c.product_id, c.variant_id,
               p.name AS product_name, p.base_price,
               pv.storage, pv.color, pv.price_modifier
        FROM cart c
        JOIN products p ON c.product_id = p.id
        LEFT JOIN product_variants pv ON c.variant_id = pv.id
        WHERE c.user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $cartItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($cartItems)) {
        jsonResponse(['success' => false, 'error' => 'Your cart is empty.'], 400);
    }

    // Calculate totals
    $subtotal = 0.0;
    foreach ($cartItems as $item) {
        $subtotal += ((float)$item['base_price'] + (float)($item['price_modifier'] ?? 0)) * (int)$item['quantity'];
    }
    $shipping    = ($subtotal >= FREE_SHIPPING_THRESHOLD) ? 0.0 : (float)SHIPPING_COST;
    $tax         = round($subtotal * TAX_RATE, 2);
    $total       = round($subtotal + $shipping + $tax, 2);
    $fullAddress = $address . ', ' . $city;
    $orderNum    = generateOrderNumber();

    // Insert order
    $stmt = $db->prepare("
        INSERT INTO orders (user_id, order_number, subtotal, shipping, tax, total, shipping_address, payment_method, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isddddsss', $userId, $orderNum, $subtotal, $shipping, $tax, $total, $fullAddress, $payment, $notes);

    if (!$stmt->execute()) {
        $stmt->close();
        jsonResponse(['success' => false, 'error' => 'Could not create order. Please try again.'], 500);
    }
    $orderId = $db->insert_id;
    $stmt->close();

    // Insert order items
    foreach ($cartItems as $item) {
        $unit   = (float)$item['base_price'] + (float)($item['price_modifier'] ?? 0);
        $line   = $unit * (int)$item['quantity'];
        $varInf = trim(($item['storage'] ?? '') . ' ' . ($item['color'] ?? ''));

        $stmt = $db->prepare("
            INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_info, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiissidd', $orderId, $item['product_id'], $item['variant_id'], $item['product_name'], $varInf, $item['quantity'], $unit, $line);
        $stmt->execute();
        $stmt->close();
    }

    // Clear cart
    $stmt = $db->prepare("DELETE FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    jsonResponse([
        'success'      => true,
        'order_number' => $orderNum,
        'order_id'     => $orderId,
        'total'        => $total,
    ]);
}

// ── List orders for current user ─────────────────────────
function listOrders() {
    requireLogin();
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("
        SELECT o.*, COUNT(oi.id) AS item_count
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.user_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    jsonResponse(['success' => true, 'orders' => $orders]);
}

// ── Get one order's details ───────────────────────────────
function orderDetail() {
    requireLogin();
    $db      = getDB();
    $orderId = (int)($_GET['id'] ?? 0);
    $userId  = (int)$_SESSION['user_id'];

    if (!$orderId) {
        jsonResponse(['success' => false, 'error' => 'Order ID required.'], 400);
    }

    if (isAdmin()) {
        $stmt = $db->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $orderId);
    } else {
        $stmt = $db->prepare("SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1");
        $stmt->bind_param('ii', $orderId, $userId);
    }
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Order not found.'], 404);
    }

    $stmt = $db->prepare("
        SELECT oi.*, p.image_main
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    jsonResponse(['success' => true, 'order' => $order]);
}
?>