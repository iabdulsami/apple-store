<?php
/*
=============================================================
  FILE: php/add_to_cart.php
  ACTIONS:
    add    → add item to cart
    get    → load all cart items + totals
    update → change quantity
    remove → delete one item
    clear  → empty entire cart
    count  → return total quantity (for navbar badge)
=============================================================
*/

ob_start();
session_start();
require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'add':    cartAdd();    break;
    case 'get':    cartGet();    break;
    case 'update': cartUpdate(); break;
    case 'remove': cartRemove(); break;
    case 'clear':  cartClear();  break;
    case 'count':  cartCount();  break;
    default: jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 400);
}

// ── Add item ─────────────────────────────────────────────
function cartAdd() {
    requireLogin();
    $db        = getDB();
    $userId    = (int)$_SESSION['user_id'];
    $productId = (int)($_POST['product_id'] ?? 0);
    $variantId = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $qty       = max(1, (int)($_POST['quantity'] ?? 1));

    if (!$productId) {
        jsonResponse(['success' => false, 'error' => 'Product ID required.'], 400);
    }

    // NULL-safe check: already in cart?
    $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id=? AND product_id=? AND variant_id<=>? LIMIT 1");
    $stmt->bind_param('iii', $userId, $productId, $variantId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $newQty = $existing['quantity'] + $qty;
        $stmt = $db->prepare("UPDATE cart SET quantity=? WHERE id=?");
        $stmt->bind_param('ii', $newQty, $existing['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, variant_id, quantity) VALUES (?,?,?,?)");
        $stmt->bind_param('iiii', $userId, $productId, $variantId, $qty);
        $stmt->execute();
        $stmt->close();
    }

    jsonResponse(['success' => true, 'count' => getCartCount($userId)]);
}

// ── Get cart items + totals ──────────────────────────────
function cartGet() {
    requireLogin();
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("
        SELECT c.id AS cart_id, c.quantity, c.product_id, c.variant_id,
               p.name, p.slug, p.base_price, p.image_main,
               pv.storage, pv.color, pv.color_hex, pv.price_modifier, pv.stock
        FROM cart c
        JOIN products p ON c.product_id = p.id
        LEFT JOIN product_variants pv ON c.variant_id = pv.id
        WHERE c.user_id = ?
        ORDER BY c.added_at DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $subtotal = 0.0;
    foreach ($items as &$item) {
        $item['unit_price'] = (float)$item['base_price'] + (float)($item['price_modifier'] ?? 0);
        $item['line_total'] = $item['unit_price'] * (int)$item['quantity'];
        $subtotal += $item['line_total'];
    }
    unset($item);

    $shipping = ($subtotal >= FREE_SHIPPING_THRESHOLD || $subtotal == 0) ? 0.0 : (float)SHIPPING_COST;
    $tax      = round($subtotal * TAX_RATE, 2);
    $total    = round($subtotal + $shipping + $tax, 2);

    jsonResponse([
        'success' => true,
        'items'   => $items,
        'summary' => [
            'subtotal'     => round($subtotal, 2),
            'shipping'     => $shipping,
            'tax'          => $tax,
            'total'        => $total,
            'free_shipping'=> ($shipping == 0 && $subtotal > 0),
        ]
    ]);
}

// ── Update quantity ──────────────────────────────────────
function cartUpdate() {
    requireLogin();
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];
    $cartId = (int)($_POST['cart_id']  ?? 0);
    $qty    = (int)($_POST['quantity'] ?? 0);

    if ($qty < 1) {
        $stmt = $db->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $cartId, $userId);
    } else {
        $stmt = $db->prepare("UPDATE cart SET quantity=? WHERE id=? AND user_id=?");
        $stmt->bind_param('iii', $qty, $cartId, $userId);
    }
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true, 'count' => getCartCount($userId)]);
}

// ── Remove one item ──────────────────────────────────────
function cartRemove() {
    requireLogin();
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];
    $cartId = (int)($_POST['cart_id'] ?? 0);

    $stmt = $db->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $cartId, $userId);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true, 'count' => getCartCount($userId)]);
}

// ── Clear entire cart ────────────────────────────────────
function cartClear() {
    requireLogin();
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $db->prepare("DELETE FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['success' => true]);
}

// ── Count total items in cart (for navbar badge) ─────────
function cartCount() {
    if (!isLoggedIn()) {
        jsonResponse(['count' => 0]);
    }
    jsonResponse(['count' => getCartCount((int)$_SESSION['user_id'])]);
}

// ── Helper: SUM of all quantities for a user ─────────────
function getCartCount($userId) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity),0) AS cnt FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['cnt'];
}
?>