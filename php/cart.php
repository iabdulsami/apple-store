<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':       getProducts();    break;
    case 'featured':   getFeatured();    break;
    case 'detail':     getProduct();     break;
    case 'categories': getCategories();  break;
    case 'search':     searchProducts(); break;
    case 'create':     createProduct();  break;
    case 'update':     updateProduct();  break;
    case 'delete':     deleteProduct();  break;
    default:           jsonResponse(['error' => 'Invalid action'], 400);
}

function getProducts() {
    $db  = getDB();
    $cat = $_GET['category'] ?? '';
    $where = "WHERE p.is_active = 1";
    $params = [];
    $types  = '';

    if ($cat) {
        $where  .= " AND c.slug = ?";
        $params[] = $cat;
        $types   .= 's';
    }

    $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                   MIN(pv.price_modifier) AS min_modifier,
                   COUNT(DISTINCT pv.id) AS variant_count
            FROM products p
            JOIN categories c ON p.category_id = c.id
            LEFT JOIN product_variants pv ON p.id = pv.product_id
            $where
            GROUP BY p.id
            ORDER BY p.is_featured DESC, p.created_at DESC";

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($products as &$p) {
        $p['final_price'] = $p['base_price'] + ($p['min_modifier'] ?? 0);
    }

    jsonResponse(['success' => true, 'products' => $products]);
}

function getFeatured() {
    $db  = getDB();
    $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM products p
            JOIN categories c ON p.category_id = c.id
            WHERE p.is_featured = 1 AND p.is_active = 1
            ORDER BY p.created_at DESC
            LIMIT 6";
    $result = $db->query($sql);
    jsonResponse(['success' => true, 'products' => $result->fetch_all(MYSQLI_ASSOC)]);
}

function getProduct() {
    $db   = getDB();
    $slug = $_GET['slug'] ?? '';
    $id   = (int)($_GET['id'] ?? 0);

    if (!$slug && !$id) jsonResponse(['error' => 'Product slug or id required'], 400);

    $where = $slug ? "p.slug = ?" : "p.id = ?";
    $param = $slug ?: $id;
    $type  = $slug ? 's' : 'i';

    $stmt = $db->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
                          FROM products p
                          JOIN categories c ON p.category_id = c.id
                          WHERE $where AND p.is_active = 1");
    $stmt->bind_param($type, $param);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product) jsonResponse(['error' => 'Product not found'], 404);

    // Get variants
    $stmt2 = $db->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY price_modifier ASC");
    $stmt2->bind_param('i', $product['id']);
    $stmt2->execute();
    $product['variants'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    // Group variants by storage and color
    $storages = array_unique(array_filter(array_column($product['variants'], 'storage')));
    $colors   = [];
    $seenColors = [];
    foreach ($product['variants'] as $v) {
        if ($v['color'] && !in_array($v['color'], $seenColors)) {
            $colors[]     = ['color' => $v['color'], 'hex' => $v['color_hex']];
            $seenColors[] = $v['color'];
        }
    }
    $product['available_storages'] = array_values($storages);
    $product['available_colors']   = $colors;

    jsonResponse(['success' => true, 'product' => $product]);
}

function getCategories() {
    $db  = getDB();
    $sql = "SELECT c.*, COUNT(p.id) AS product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1
            GROUP BY c.id
            ORDER BY c.sort_order ASC";
    $result = $db->query($sql);
    jsonResponse(['success' => true, 'categories' => $result->fetch_all(MYSQLI_ASSOC)]);
}

function searchProducts() {
    $db    = getDB();
    $query = '%' . sanitize($_GET['q'] ?? '') . '%';
    $stmt  = $db->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
                            FROM products p
                            JOIN categories c ON p.category_id = c.id
                            WHERE p.is_active = 1 AND (p.name LIKE ? OR p.tagline LIKE ? OR p.description LIKE ?)
                            ORDER BY p.is_featured DESC LIMIT 20");
    $stmt->bind_param('sss', $query, $query, $query);
    $stmt->execute();
    jsonResponse(['success' => true, 'products' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function createProduct() {
    requireAdmin();
    $db = getDB();
    $name        = sanitize($_POST['name'] ?? '');
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $tagline     = sanitize($_POST['tagline'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $basePrice   = (float)($_POST['base_price'] ?? 0);
    $isFeatured  = (int)($_POST['is_featured'] ?? 0);

    if (!$name || !$categoryId || !$basePrice) {
        jsonResponse(['error' => 'Name, category, and price are required'], 400);
    }

    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
    $stmt = $db->prepare("INSERT INTO products (category_id, name, slug, tagline, description, base_price, is_featured)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('issssdii', $categoryId, $name, $slug, $tagline, $description, $basePrice, $isFeatured);
    $stmt->execute();
    jsonResponse(['success' => true, 'product_id' => $db->insert_id]);
}

function updateProduct() {
    requireAdmin();
    $db   = getDB();
    $id   = (int)($_POST['id'] ?? 0);
    $data = [];

    $fields = ['name', 'tagline', 'description', 'base_price', 'is_featured', 'is_active', 'category_id'];
    $sets   = [];
    $params = [];
    $types  = '';

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $sets[]   = "$f = ?";
            $params[] = in_array($f, ['base_price']) ? (float)$_POST[$f] : sanitize($_POST[$f]);
            $types   .= in_array($f, ['base_price']) ? 'd' : 's';
        }
    }

    if (!$sets) jsonResponse(['error' => 'Nothing to update'], 400);

    $params[] = $id;
    $types   .= 'i';
    $sql      = "UPDATE products SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt     = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    jsonResponse(['success' => true]);
}

function deleteProduct() {
    requireAdmin();
    $db = getDB();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    jsonResponse(['success' => true]);
}