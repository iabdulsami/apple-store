<?php
/*
=============================================================
  FILE: admin/products.php
  PATH: apple-store/admin/products.php
  PURPOSE: Lists all products. Admin can:
    - See all products with image, price, category, status
    - Toggle featured on/off with one click
    - Soft-delete a product (marks is_active=0, not real delete)
    - Click to edit a product
    - Click "Add Product" to go to add_product.php
=============================================================
*/
ob_start();
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.html');
    exit;
}

require_once '../php/config.php';
$db = getDB();

// ── Handle AJAX actions (toggle featured, delete) ─────────
// These come from JavaScript fetch() calls on this page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    $action    = $_POST['action']     ?? '';
    $productId = (int)($_POST['id']  ?? 0);

    if ($action === 'toggle_featured') {
        $db->query("UPDATE products SET is_featured = 1 - is_featured WHERE id = $productId");
        $row = $db->query("SELECT is_featured FROM products WHERE id=$productId")->fetch_assoc();
        echo json_encode(['success' => true, 'is_featured' => (int)$row['is_featured']]);
        exit;
    }

    if ($action === 'delete') {
        // Soft delete — just hide it (is_active=0), not a real DELETE
        $db->query("UPDATE products SET is_active = 0 WHERE id = $productId");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'restore') {
        $db->query("UPDATE products SET is_active = 1 WHERE id = $productId");
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── Filter ────────────────────────────────────────────────
$filterCat    = (int)($_GET['category'] ?? 0);
$filterStatus = $_GET['status'] ?? 'active'; // 'active' or 'all'
$search       = sanitize($_GET['q'] ?? '');

$where = "WHERE 1=1";
if ($filterStatus === 'active') $where .= " AND p.is_active = 1";
if ($filterCat)  $where .= " AND p.category_id = $filterCat";
if ($search)     $where .= " AND (p.name LIKE '%$search%' OR p.tagline LIKE '%$search%')";

// ── Load products ─────────────────────────────────────────
$products = $db->query("
    SELECT p.*, c.name AS category_name,
           (SELECT COUNT(*) FROM product_variants WHERE product_id = p.id) AS variant_count
    FROM products p
    JOIN categories c ON p.category_id = c.id
    $where
    ORDER BY p.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$categories = $db->query("SELECT id, name FROM categories ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);

$showAdded = isset($_GET['added']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products — Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body{background:#f5f5f7}
        .admin-wrap{display:flex;min-height:100vh}
        .admin-sidebar{width:240px;background:#1d1d1f;flex-shrink:0;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;overflow-y:auto;z-index:100}
        .sidebar-logo{display:flex;align-items:center;gap:10px;padding:24px 20px;border-bottom:1px solid rgba(255,255,255,0.1);color:#fff;font-size:15px;font-weight:600;text-decoration:none}
        .sidebar-nav{padding:16px 12px;flex:1}
        .sidebar-nav a{display:flex;align-items:center;gap:10px;padding:11px 14px;color:rgba(255,255,255,0.65);border-radius:9px;font-size:14px;font-weight:500;text-decoration:none;margin-bottom:3px;transition:all 0.2s}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:rgba(255,255,255,0.12);color:#fff;text-decoration:none}
        .sidebar-nav a svg{width:16px;height:16px;flex-shrink:0}
        .sidebar-nav .nav-section{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,0.3);padding:16px 14px 6px}
        .sidebar-bottom{padding:16px;border-top:1px solid rgba(255,255,255,0.1)}
        .sidebar-logout{display:flex;align-items:center;gap:8px;padding:9px 14px;color:rgba(255,255,255,0.5);font-size:13px;text-decoration:none;border-radius:8px;transition:all 0.2s;margin-top:4px}
        .sidebar-logout:hover{background:rgba(255,59,48,0.15);color:#ff3b30;text-decoration:none}
        .sidebar-logout svg{width:14px;height:14px}
        .sidebar-avatar{width:32px;height:32px;background:#0071e3;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
        .sidebar-user{display:flex;align-items:center;gap:10px;padding:10px 14px;color:rgba(255,255,255,0.7);font-size:13px}
        .admin-main{margin-left:240px;flex:1;padding:32px}
        .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
        .page-header h1{font-size:26px;font-weight:700;color:#1d1d1f}

        .toolbar{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
        .toolbar input{padding:9px 14px;border:1.5px solid #d2d2d7;border-radius:10px;font-size:14px;outline:none;min-width:220px}
        .toolbar input:focus{border-color:#0071e3}
        .toolbar select{padding:9px 14px;border:1.5px solid #d2d2d7;border-radius:10px;font-size:14px;outline:none;background:#fff}
        .toolbar-right{margin-left:auto;display:flex;gap:8px}

        .products-table-wrap{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);overflow:hidden}
        .products-table{width:100%;border-collapse:collapse}
        .products-table th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;color:#6e6e73;text-transform:uppercase;letter-spacing:0.5px;background:#fafafa;border-bottom:1px solid #f0f0f5}
        .products-table td{padding:14px 16px;font-size:13px;border-bottom:1px solid #f5f5f7;vertical-align:middle}
        .products-table tr:last-child td{border-bottom:none}
        .products-table tr:hover td{background:#fafafa}

        .product-img-cell{display:flex;align-items:center;gap:12px}
        .product-thumb{width:52px;height:52px;border-radius:10px;background:#f5f5f7;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;border:1px solid #e8e8ed}
        .product-thumb img{width:100%;height:100%;object-fit:contain;padding:4px}
        .product-thumb-placeholder{color:#aaa}
        .product-name-main{font-weight:600;color:#1d1d1f;font-size:14px}
        .product-slug{font-size:11px;color:#aaa;margin-top:2px;font-family:monospace}

        .price-cell{font-weight:700;color:#1d1d1f}
        .variant-count{font-size:11px;color:#6e6e73;margin-top:2px}

        .featured-toggle{background:none;border:none;cursor:pointer;padding:4px;border-radius:6px;transition:all 0.2s;display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600}
        .featured-toggle.is-featured{color:#ff9f0a}
        .featured-toggle.not-featured{color:#d2d2d7}
        .featured-toggle:hover{background:#f5f5f7}
        .featured-toggle svg{width:16px;height:16px}

        .inactive-row td{opacity:0.5}
        .inactive-badge{background:#f5f5f7;color:#aaa;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:600}

        .action-btns{display:flex;gap:6px;align-items:center}
        .action-btn{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all 0.2s}
        .btn-edit{background:#f0f6ff;color:#0071e3}.btn-edit:hover{background:#0071e3;color:#fff;text-decoration:none}
        .btn-delete{background:#fff0f0;color:#ff3b30}.btn-delete:hover{background:#ff3b30;color:#fff}
        .btn-restore{background:#f0faf4;color:#30d158}.btn-restore:hover{background:#30d158;color:#fff}

        .empty-state{text-align:center;padding:60px 20px;color:#6e6e73}
        .alert-success{background:#f0faf4;border:1px solid #a5d6a7;color:#2e7d32;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:14px;display:flex;align-items:center;gap:8px}

        .status-pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700}
        .status-active{background:#f0faf4;color:#30d158}
        .status-inactive{background:#f5f5f7;color:#aaa}

        @media(max-width:768px){.admin-sidebar{display:none}.admin-main{margin-left:0;padding:20px}}
    </style>
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body>
<div class="admin-wrap">
    <aside class="admin-sidebar">
        <a href="dashboard.php" class="sidebar-logo">
            <svg width="20" height="24" viewBox="0 0 814 1000" fill="currentColor"><path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-37.5-150.3-96.4c-52.1-66.5-101-170.5-101-269.3 0-170.4 111.4-260.5 220.5-260.5 57.5 0 105.3 38 140.9 38 33.9 0 87-40.3 154-40.3 24.9 0 108.2 2.3 159.8 86.4zm-216.8-78.2c32.1-38.1 53.7-90.8 53.7-143.5 0-7.4-.6-14.9-1.9-21-50.6 1.9-110.4 33.4-146.1 75.8-29.4 33.4-55.8 86.4-55.8 140.3 0 8.4 1.3 16.9 1.9 19.5 3.2.6 8.4 1.3 13.6 1.3 45.4 0 102.5-29.8 134.6-72.4z"/></svg>
            Admin Panel
        </a>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="dashboard.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
            <div class="nav-section">Catalog</div>
            <a href="products.php" class="active"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>All Products</a>
            <a href="add_product.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Add New Product</a>
            <div class="nav-section">Orders</div>
            <a href="orders.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>All Orders</a>
            <div class="nav-section">Users</div>
            <a href="customers.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Customers</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
                <div><div style="font-weight:600;color:#fff;font-size:13px;"><?= htmlspecialchars($_SESSION['full_name']) ?></div><div style="font-size:11px;">Administrator</div></div>
            </div>
            <a href="../index.html" class="sidebar-logout"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>View Store</a>
            <a href="../php/auth_check.php?action=logout" class="sidebar-logout"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a>
        </div>
    </aside>

    <main class="admin-main">
        <div class="page-header">
            <div>
                <h1>Products</h1>
                <p style="color:#6e6e73;font-size:14px;margin-top:2px;"><?= count($products) ?> products found</p>
            </div>
            <a href="add_product.php" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;border-radius:10px;padding:10px 18px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Product
            </a>
        </div>

        <?php if ($showAdded): ?>
        <div class="alert-success">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Product added successfully!
        </div>
        <?php endif; ?>

        <!-- Search / filter toolbar -->
        <form method="GET" class="toolbar">
            <input type="text" name="q" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="active" <?= $filterStatus==='active' ? 'selected':'' ?>>Active Only</option>
                <option value="all"    <?= $filterStatus==='all'    ? 'selected':'' ?>>Show All</option>
            </select>
            <button type="submit" class="btn btn-primary" style="border-radius:10px;padding:9px 16px;">Filter</button>
            <a href="products.php" style="padding:9px 14px;color:#6e6e73;font-size:13px;align-self:center;">Clear</a>
        </form>

        <div class="products-table-wrap">
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Featured</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                    <tr><td colspan="6" class="empty-state">No products found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($products as $p): ?>
                    <tr class="<?= !$p['is_active'] ? 'inactive-row' : '' ?>" id="row-<?= $p['id'] ?>">
                        <td>
                            <div class="product-img-cell">
                                <div class="product-thumb">
                                    <?php if (!empty($p['image_main']) && file_exists('../' . $p['image_main'])): ?>
                                    <img src="../<?= htmlspecialchars($p['image_main']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                                    <?php else: ?>
                                    <svg class="product-thumb-placeholder" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="2" width="14" height="20" rx="2"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="product-name-main"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="product-slug"><?= htmlspecialchars($p['slug']) ?></div>
                                    <?php if ($p['variant_count'] > 0): ?>
                                    <div class="variant-count"><?= $p['variant_count'] ?> variants</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="color:#6e6e73;"><?= htmlspecialchars($p['category_name']) ?></td>
                        <td class="price-cell">$<?= number_format((float)$p['base_price'], 2) ?></td>
                        <td>
                            <?php if ($p['is_active']): ?>
                            <span class="status-pill status-active">● Active</span>
                            <?php else: ?>
                            <span class="status-pill status-inactive">● Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <!-- Star toggle — click to feature/unfeature -->
                            <button class="featured-toggle <?= $p['is_featured'] ? 'is-featured' : 'not-featured' ?>"
                                    data-id="<?= $p['id'] ?>" onclick="toggleFeatured(this)"
                                    title="<?= $p['is_featured'] ? 'Remove from featured' : 'Mark as featured' ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                     fill="<?= $p['is_featured'] ? '#ff9f0a' : 'none' ?>"
                                     stroke="<?= $p['is_featured'] ? '#ff9f0a' : 'currentColor' ?>" stroke-width="2">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                                <?= $p['is_featured'] ? 'Featured' : 'Feature' ?>
                            </button>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="edit_product.php?id=<?= $p['id'] ?>" class="action-btn btn-edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Edit
                                </a>
                                <?php if ($p['is_active']): ?>
                                <button class="action-btn btn-delete" onclick="deleteProduct(<?= $p['id'] ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    Hide
                                </button>
                                <?php else: ?>
                                <button class="action-btn btn-restore" onclick="restoreProduct(<?= $p['id'] ?>)">
                                    Restore
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="https://unpkg.com/feather-icons"></script>
<script>
feather.replace();

// Toggle featured star
function toggleFeatured(btn) {
    const id = btn.dataset.id;
    fetch('products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax=1&action=toggle_featured&id=' + id
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const isFeatured = res.is_featured === 1;
            btn.className = 'featured-toggle ' + (isFeatured ? 'is-featured' : 'not-featured');
            btn.querySelector('svg').setAttribute('fill', isFeatured ? '#ff9f0a' : 'none');
            btn.querySelector('svg').setAttribute('stroke', isFeatured ? '#ff9f0a' : 'currentColor');
            btn.childNodes[btn.childNodes.length - 1].textContent = isFeatured ? ' Featured' : ' Feature';
        }
    });
}

// Hide product (soft delete)
function deleteProduct(id) {
    if (!confirm('Hide this product from the store? You can restore it later.')) return;
    fetch('products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax=1&action=delete&id=' + id
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            // Reload page to show updated status
            window.location.reload();
        }
    });
}

// Restore hidden product
function restoreProduct(id) {
    fetch('products.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax=1&action=restore&id=' + id
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) window.location.reload();
    });
}
</script>
</body>
</html>