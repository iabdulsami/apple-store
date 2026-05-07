<?php
/*
=============================================================
  FILE: php/products.php
  ACTIONS (?action=...):
    list       → all products (filter by ?category=slug)
    featured   → is_featured=1 products only (homepage)
    detail     → one product by ?slug= or ?id=
    categories → all categories
    search     → search by ?q=keyword
=============================================================
*/

ob_start();
session_start();
require_once 'config.php';

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':       getProducts();   break;
    case 'featured':   getFeatured();   break;
    case 'detail':     getProduct();    break;
    case 'categories': getCategories(); break;
    case 'search':     searchProducts();break;
    default: jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
}

function getProducts() {
    $db  = getDB();
    $cat = sanitize($_GET['category'] ?? '');

    $where  = "WHERE p.is_active = 1";
    $params = [];
    $types  = '';

    if (!empty($cat)) {
        $where   .= " AND c.slug = ?";
        $params[] = $cat;
        $types   .= 's';
    }

    $sql = "SELECT p.id, p.name, p.slug, p.tagline, p.base_price, p.image_main, p.is_featured,
                   c.name AS category_name, c.slug AS category_slug,
                   MIN(pv.price_modifier) AS min_modifier
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN product_variants pv ON p.id = pv.product_id
            $where GROUP BY p.id
            ORDER BY p.is_featured DESC, p.created_at DESC";

    $stmt = $db->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    jsonResponse(['success' => true, 'products' => $products]);
}

function getFeatured() {
    $db  = getDB();
    $sql = "SELECT p.id, p.name, p.slug, p.tagline, p.base_price, p.image_main, p.is_featured,
                   c.name AS category_name, c.slug AS category_slug,
                   MIN(pv.price_modifier) AS min_modifier
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN product_variants pv ON p.id = pv.product_id
            WHERE p.is_featured = 1 AND p.is_active = 1
            GROUP BY p.id ORDER BY p.created_at DESC LIMIT 6";
    $result = $db->query($sql);
    jsonResponse(['success' => true, 'products' => $result->fetch_all(MYSQLI_ASSOC)]);
}

function getProduct() {
    $db   = getDB();
    $slug = sanitize($_GET['slug'] ?? '');
    $id   = (int)($_GET['id'] ?? 0);

    if (empty($slug) && !$id) {
        jsonResponse(['success' => false, 'error' => 'Provide slug= or id='], 400);
    }

    if (!empty($slug)) {
        $stmt = $db->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
                              FROM products p JOIN categories c ON p.category_id=c.id
                              WHERE p.slug=? AND p.is_active=1 LIMIT 1");
        $stmt->bind_param('s', $slug);
    } else {
        $stmt = $db->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
                              FROM products p JOIN categories c ON p.category_id=c.id
                              WHERE p.id=? AND p.is_active=1 LIMIT 1");
        $stmt->bind_param('i', $id);
    }

    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        jsonResponse(['success' => false, 'error' => 'Product not found.'], 404);
    }

    // Get variants
    $stmt = $db->prepare("SELECT * FROM product_variants WHERE product_id=? ORDER BY price_modifier ASC");
    $stmt->bind_param('i', $product['id']);
    $stmt->execute();
    $variants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $product['variants'] = $variants;

    // Build unique storage + color lists
    $storages = $colors = $seenS = $seenC = [];
    foreach ($variants as $v) {
        if (!empty($v['storage']) && !in_array($v['storage'], $seenS)) {
            $storages[] = $v['storage']; $seenS[] = $v['storage'];
        }
        if (!empty($v['color']) && !in_array($v['color'], $seenC)) {
            $colors[] = ['color' => $v['color'], 'hex' => $v['color_hex']];
            $seenC[]  = $v['color'];
        }
    }
    $product['available_storages'] = $storages;
    $product['available_colors']   = $colors;

    jsonResponse(['success' => true, 'product' => $product]);
}

function getCategories() {
    $db  = getDB();
    $sql = "SELECT c.*, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1
            GROUP BY c.id ORDER BY c.sort_order ASC";
    $result = $db->query($sql);
    jsonResponse(['success' => true, 'categories' => $result->fetch_all(MYSQLI_ASSOC)]);
}

function searchProducts() {
    $db = getDB();
    $q  = '%' . sanitize($_GET['q'] ?? '') . '%';

    $stmt = $db->prepare("SELECT p.id, p.name, p.slug, p.tagline, p.base_price, p.image_main,
                                 c.name AS category_name
                          FROM products p JOIN categories c ON p.category_id=c.id
                          WHERE p.is_active=1 AND (p.name LIKE ? OR p.tagline LIKE ? OR p.description LIKE ?)
                          ORDER BY p.is_featured DESC LIMIT 10");
    $stmt->bind_param('sss', $q, $q, $q);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    jsonResponse(['success' => true, 'products' => $products]);
}
?>