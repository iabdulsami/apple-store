<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'place':  placeOrder();  break;
    case 'list':   listOrders();  break;
    case 'detail': orderDetail(); break;
    case 'all':    allOrders();   break;
    case 'status': updateStatus(); break;
    default:       jsonResponse(['error' => 'Invalid action'], 400);
}

function placeOrder() {
    requireLogin();
    $db     = getDB();
    $userId = $_SESSION['user_id'];

    $address = sanitize($_POST['address'] ?? '');
    $city    = sanitize($_POST['city'] ?? '');
    $payment = sanitize($_POST['payment_method'] ?? 'COD');
    $notes   = sanitize($_POST['notes'] ?? '');

    if (!$address || !$city) jsonResponse(['error' => 'Shipping address and city are required'], 400);

    // Get cart items
    $stmt = $db->prepare("
        SELECT c.quantity, c.product_id, c.variant_id,
               p.name, p.base_price,
               pv.storage, pv.color, pv.price_modifier
        FROM cart c
        JOIN products p ON c.product_id = p.id
        LEFT JOIN product_variants pv ON c.variant_id = pv.id
        WHERE c.user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $cartItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($cartItems)) jsonResponse(['error' => 'Cart is empty'], 400);

    $subtotal = 0;
    foreach ($cartItems as $item) {
        $price    = $item['base_price'] + ($item['price_modifier'] ?? 0);
        $subtotal += $price * $item['quantity'];
    }

    $shipping = ($subtotal >= FREE_SHIPPING_THRESHOLD) ? 0 : SHIPPING_COST;
    $tax      = round($subtotal * TAX_RATE, 2);
    $total    = $subtotal + $shipping + $tax;
    $fullAddr = "$address, $city";
    $orderNum = generateOrderNumber();

    // Insert order
    $stmt2 = $db->prepare("INSERT INTO orders (user_id, order_number, subtotal, shipping, tax, total, shipping_address, payment_method, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt2->bind_param('isddddss s', $userId, $orderNum, $subtotal, $shipping, $tax, $total, $fullAddr, $payment, $notes);

    // Fix bind types
    $stmt2 = $db->prepare("INSERT INTO orders (user_id, order_number, subtotal, shipping, tax, total, shipping_address, payment_method, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt2->bind_param('isddddss s', $userId, $orderNum, $subtotal, $shipping, $tax, $total, $fullAddr, $payment, $notes);

    $subF = (float)$subtotal;
    $shipF = (float)$shipping;
    $taxF = (float)$tax;
    $totF = (float)$total;

    $stmt3 = $db->prepare("INSERT INTO orders (user_id, order_number, subtotal, shipping, tax, total, shipping_address, payment_method, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt3->bind_param('isddddsss', $userId, $orderNum, $subF, $shipF, $taxF, $totF, $fullAddr, $payment, $notes);
    $stmt3->execute();
    $orderId = $db->insert_id;

    // Insert order items
    foreach ($cartItems as $item) {
        $unitPrice  = $item['base_price'] + ($item['price_modifier'] ?? 0);
        $lineTotal  = $unitPrice * $item['quantity'];
        $varInfo    = trim(($item['storage'] ?? '') . ' ' . ($item['color'] ?? ''));
        $stmt4 = $db->prepare("INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_info, quantity, unit_price, total_price)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt4->bind_param('iiissidd', $orderId, $item['product_id'], $item['variant_id'], $item['name'], $varInfo, $item['quantity'], $unitPrice, $lineTotal);
        $stmt4->execute();
    }

    // Clear cart
    $db->prepare("DELETE FROM cart WHERE user_id = ?")->bind_param('i', $userId) && null;
    $stmtClr = $db->prepare("DELETE FROM cart WHERE user_id = ?");
    $stmtClr->bind_param('i', $userId);
    $stmtClr->execute();

    jsonResponse(['success' => true, 'order_number' => $orderNum, 'order_id' => $orderId, 'total' => $totF]);
}

function listOrders() {
    requireLogin();
    $db     = getDB();
    $userId = $_SESSION['user_id'];

    $stmt = $db->prepare("SELECT o.*, COUNT(oi.id) AS item_count
                          FROM orders o
                          LEFT JOIN order_items oi ON o.id = oi.order_id
                          WHERE o.user_id = ?
                          GROUP BY o.id
                          ORDER BY o.created_at DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    jsonResponse(['success' => true, 'orders' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function orderDetail() {
    requireLogin();
    $db      = getDB();
    $orderId = (int)($_GET['id'] ?? 0);
    $userId  = $_SESSION['user_id'];

    $whereUser = isAdmin() ? "" : "AND o.user_id = $userId";

    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? $whereUser");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) jsonResponse(['error' => 'Order not found'], 404);

    $stmt2 = $db->prepare("SELECT oi.*, p.image_main FROM order_items oi
                           LEFT JOIN products p ON oi.product_id = p.id
                           WHERE oi.order_id = ?");
    $stmt2->bind_param('i', $orderId);
    $stmt2->execute();
    $order['items'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    jsonResponse(['success' => true, 'order' => $order]);
}

function allOrders() {
    requireAdmin();
    $db     = getDB();
    $status = $_GET['status'] ?? '';
    $where  = $status ? "WHERE o.status = '$status'" : "";

    $sql = "SELECT o.*, u.full_name, u.email, COUNT(oi.id) AS item_count
            FROM orders o
            JOIN users u ON o.user_id = u.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            $where
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT 100";

    $result = $db->query($sql);
    jsonResponse(['success' => true, 'orders' => $result->fetch_all(MYSQLI_ASSOC)]);
}

function updateStatus() {
    requireAdmin();
    $db      = getDB();
    $orderId = (int)($_POST['order_id'] ?? 0);
    $status  = sanitize($_POST['status'] ?? '');
    $allowed = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    if (!in_array($status, $allowed)) jsonResponse(['error' => 'Invalid status'], 400);

    $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $orderId);
    $stmt->execute();
    jsonResponse(['success' => true]);
}