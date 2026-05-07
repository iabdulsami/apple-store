<?php
/*
=============================================================
  FILE: admin/add_product.php
  PATH: apple-store/admin/add_product.php
  PURPOSE: Form to add a NEW product with image upload.
           Also handles the POST when the form is submitted.

  HOW IMAGE UPLOAD WORKS:
    1. Admin fills the form and picks an image file
    2. Browser sends the form to THIS same page via POST
    3. PHP moves the uploaded file to ../images/ folder
    4. PHP saves the product + image path into the database
    5. Admin is redirected to products.php with a success message
=============================================================
*/
ob_start();
session_start();

// Admin guard
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.html');
    exit;
}

require_once '../php/config.php';
$db = getDB();

$errors  = [];
$success = '';

// ── Handle form submission ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Read all form fields
    $name        = sanitize($_POST['name']        ?? '');
    $categoryId  = (int)($_POST['category_id']   ?? 0);
    $tagline     = sanitize($_POST['tagline']     ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $basePrice   = (float)($_POST['base_price']  ?? 0);
    $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;

    // Validate required fields
    if (empty($name))       $errors[] = 'Product name is required.';
    if (!$categoryId)       $errors[] = 'Please select a category.';
    if ($basePrice <= 0)    $errors[] = 'Price must be greater than 0.';

    // Auto-generate slug from name (e.g. "iPhone 16 Pro" → "iphone-16-pro")
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

    // Check if slug already exists
    $chk = $db->prepare("SELECT id FROM products WHERE slug = ? LIMIT 1");
    $chk->bind_param('s', $slug);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        // Make it unique by adding a suffix
        $slug = $slug . '-' . time();
    }
    $chk->close();

    // ── Handle image upload ───────────────────────────────
    $imagePath = '';  // Will store path like "images/iphone16pro.jpg"

    if (!empty($_FILES['image']['name'])) {
        $file     = $_FILES['image'];
        $origName = $file['name'];
        $tmpPath  = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileErr  = $file['error'];

        // Check for upload errors
        if ($fileErr !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed. Error code: ' . $fileErr;
        } else {
            // Only allow image file types
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
            $mimeType = mime_content_type($tmpPath);

            if (!in_array($mimeType, $allowedTypes)) {
                $errors[] = 'Only JPG, PNG, WEBP, or GIF images are allowed.';
            } elseif ($fileSize > 5 * 1024 * 1024) {
                // Max 5MB
                $errors[] = 'Image must be smaller than 5MB.';
            } else {
                // Create a clean filename: slug + original extension
                $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $newFileName = $slug . '.' . $ext;

                // Images are stored in apple-store/images/ folder
                // From admin/ we go up one level with ../
                $uploadDir  = '../images/';
                $uploadPath = $uploadDir . $newFileName;

                // Create images folder if it doesn't exist
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Move the temporary file to the images folder
                if (move_uploaded_file($tmpPath, $uploadPath)) {
                    // Store the path relative to the project root
                    $imagePath = 'images/' . $newFileName;
                } else {
                    $errors[] = 'Could not save image. Check that the images/ folder exists and is writable.';
                }
            }
        }
    }
    // Image is optional — no error if none uploaded

    // ── Save to database if no errors ────────────────────
    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO products (category_id, name, slug, tagline, description, base_price, image_main, is_featured, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param('issssdsi', $categoryId, $name, $slug, $tagline, $description, $basePrice, $imagePath, $isFeatured);

        if ($stmt->execute()) {
            $newProductId = $db->insert_id;
            $stmt->close();

            // ── Handle variants (optional) ─────────────
            // Variants come as arrays: color[], storage[], price_modifier[], stock[]
            $colors    = $_POST['color']          ?? [];
            $storages  = $_POST['storage']        ?? [];
            $modifiers = $_POST['price_modifier'] ?? [];
            $stocks    = $_POST['stock']          ?? [];
            $hexes     = $_POST['color_hex']      ?? [];

            for ($i = 0; $i < count($colors); $i++) {
                $vColor    = sanitize($colors[$i]    ?? '');
                $vStorage  = sanitize($storages[$i]  ?? '');
                $vModifier = (float)($modifiers[$i]  ?? 0);
                $vStock    = (int)($stocks[$i]        ?? 10);
                $vHex      = sanitize($hexes[$i]      ?? '#cccccc');
                $vSku      = strtoupper($slug) . '-' . $i;

                // Only insert if at least color or storage is filled
                if (!empty($vColor) || !empty($vStorage)) {
                    $sv = $db->prepare("
                        INSERT INTO product_variants (product_id, storage, color, color_hex, price_modifier, stock, sku)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $sv->bind_param('isssdis', $newProductId, $vStorage, $vColor, $vHex, $vModifier, $vStock, $vSku);
                    $sv->execute();
                    $sv->close();
                }
            }

            // Redirect to products list with a success message
            header('Location: products.php?added=1');
            exit;

        } else {
            $errors[] = 'Database error: ' . $db->error;
            $stmt->close();
        }
    }
}

// ── Load categories for the dropdown ─────────────────────
$categories = $db->query("SELECT id, name FROM categories ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product — Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { background: #f5f5f7; }
        .admin-wrap { display: flex; min-height: 100vh; }

        /* Sidebar (same as dashboard) */
        .admin-sidebar { width:240px; background:#1d1d1f; flex-shrink:0; display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; overflow-y:auto; z-index:100; }
        .sidebar-logo { display:flex; align-items:center; gap:10px; padding:24px 20px; border-bottom:1px solid rgba(255,255,255,0.1); color:#fff; font-size:15px; font-weight:600; text-decoration:none; }
        .sidebar-nav { padding:16px 12px; flex:1; }
        .sidebar-nav a { display:flex; align-items:center; gap:10px; padding:11px 14px; color:rgba(255,255,255,0.65); border-radius:9px; font-size:14px; font-weight:500; text-decoration:none; margin-bottom:3px; transition:all 0.2s; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background:rgba(255,255,255,0.12); color:#fff; text-decoration:none; }
        .sidebar-nav a svg { width:16px; height:16px; flex-shrink:0; }
        .sidebar-nav .nav-section { font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:rgba(255,255,255,0.3); padding:16px 14px 6px; }
        .sidebar-bottom { padding:16px; border-top:1px solid rgba(255,255,255,0.1); }
        .sidebar-logout { display:flex; align-items:center; gap:8px; padding:9px 14px; color:rgba(255,255,255,0.5); font-size:13px; text-decoration:none; border-radius:8px; transition:all 0.2s; margin-top:4px; }
        .sidebar-logout:hover { background:rgba(255,59,48,0.15); color:#ff3b30; text-decoration:none; }
        .sidebar-logout svg { width:14px; height:14px; }
        .sidebar-avatar { width:32px; height:32px; background:#0071e3; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:14px; flex-shrink:0; }
        .sidebar-user { display:flex; align-items:center; gap:10px; padding:10px 14px; color:rgba(255,255,255,0.7); font-size:13px; }

        .admin-main { margin-left:240px; flex:1; padding:32px; }

        /* Form styles */
        .form-page-header { margin-bottom:28px; }
        .form-page-header h1 { font-size:26px; font-weight:700; color:#1d1d1f; }
        .form-page-header p { color:#6e6e73; font-size:14px; margin-top:4px; }
        .back-link { display:inline-flex; align-items:center; gap:6px; color:#0071e3; font-size:13px; margin-bottom:16px; text-decoration:none; }
        .back-link:hover { text-decoration:underline; }

        .form-layout { display:grid; grid-template-columns:1fr 380px; gap:24px; align-items:start; }

        .form-card { background:#fff; border-radius:16px; padding:28px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:20px; }
        .form-card h2 { font-size:16px; font-weight:700; margin-bottom:20px; color:#1d1d1f; padding-bottom:12px; border-bottom:1px solid #f0f0f5; }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

        .field-group { margin-bottom:18px; }
        .field-group label { display:block; font-size:13px; font-weight:600; color:#1d1d1f; margin-bottom:7px; }
        .field-group label span { color:#6e6e73; font-weight:400; }
        .field-group input[type=text],
        .field-group input[type=number],
        .field-group select,
        .field-group textarea {
            width:100%; padding:11px 14px;
            border:1.5px solid #d2d2d7;
            border-radius:10px;
            font-size:14px;
            font-family:inherit;
            outline:none;
            transition:border-color 0.2s, box-shadow 0.2s;
            background:#fafafa;
        }
        .field-group input:focus,
        .field-group select:focus,
        .field-group textarea:focus {
            border-color:#0071e3;
            background:#fff;
            box-shadow:0 0 0 3px rgba(0,113,227,0.12);
        }
        .field-group textarea { resize:vertical; min-height:100px; }

        /* Image upload area */
        .image-upload-area {
            border:2px dashed #d2d2d7;
            border-radius:14px;
            padding:32px 20px;
            text-align:center;
            cursor:pointer;
            transition:all 0.2s;
            background:#fafafa;
            position:relative;
        }
        .image-upload-area:hover { border-color:#0071e3; background:#f0f6ff; }
        .image-upload-area input[type=file] {
            position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%;
        }
        .image-upload-area .upload-icon { color:#0071e3; margin-bottom:10px; }
        .image-upload-area h3 { font-size:15px; font-weight:600; margin-bottom:4px; }
        .image-upload-area p { font-size:13px; color:#6e6e73; }
        .image-preview {
            margin-top:16px; display:none;
        }
        .image-preview img {
            max-width:100%; max-height:200px; object-fit:contain;
            border-radius:10px; border:1px solid #e8e8ed;
        }

        /* Toggle switch for Featured */
        .toggle-group { display:flex; align-items:center; justify-content:space-between; padding:14px 0; border-bottom:1px solid #f0f0f5; }
        .toggle-group:last-child { border-bottom:none; padding-bottom:0; }
        .toggle-label { font-size:14px; font-weight:500; }
        .toggle-sub { font-size:12px; color:#6e6e73; margin-top:2px; }
        .toggle-switch { position:relative; width:48px; height:28px; }
        .toggle-switch input { opacity:0; width:0; height:0; }
        .toggle-slider {
            position:absolute; cursor:pointer; inset:0;
            background:#d2d2d7; border-radius:28px; transition:0.3s;
        }
        .toggle-slider:before {
            content:''; position:absolute;
            height:22px; width:22px; left:3px; bottom:3px;
            background:#fff; border-radius:50%;
            transition:0.3s; box-shadow:0 1px 4px rgba(0,0,0,0.2);
        }
        .toggle-switch input:checked + .toggle-slider { background:#34c759; }
        .toggle-switch input:checked + .toggle-slider:before { transform:translateX(20px); }

        /* Variants section */
        .variant-row {
            display:grid;
            grid-template-columns: 1fr 1fr 90px 70px 50px 36px;
            gap:8px;
            align-items:end;
            margin-bottom:10px;
        }
        .variant-row input, .variant-row select {
            padding:9px 10px; border:1.5px solid #d2d2d7;
            border-radius:8px; font-size:13px; font-family:inherit; outline:none; background:#fafafa;
        }
        .variant-row input:focus { border-color:#0071e3; background:#fff; }
        .variant-row input[type=color] { padding:3px 6px; height:38px; cursor:pointer; }
        .remove-variant-btn {
            width:36px; height:36px; border:none; background:#fff0f0;
            color:#ff3b30; border-radius:8px; cursor:pointer;
            display:flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        .remove-variant-btn:hover { background:#ff3b30; color:#fff; }
        .variant-header {
            display:grid;
            grid-template-columns:1fr 1fr 90px 70px 50px 36px;
            gap:8px;
            margin-bottom:6px;
        }
        .variant-header span {
            font-size:11px; font-weight:700; color:#6e6e73;
            text-transform:uppercase; letter-spacing:0.5px;
        }
        #addVariantBtn {
            display:inline-flex; align-items:center; gap:6px;
            background:#f0f6ff; color:#0071e3;
            border:none; border-radius:8px;
            padding:9px 14px; font-size:13px; font-weight:600;
            cursor:pointer; margin-top:8px; transition:all 0.2s;
        }
        #addVariantBtn:hover { background:#0071e3; color:#fff; }

        /* Error / success alerts */
        .alert { border-radius:10px; padding:14px 18px; margin-bottom:20px; font-size:14px; }
        .alert-error { background:#fff0f0; border:1px solid #ffcdd2; color:#c62828; }
        .alert-error ul { margin:6px 0 0 16px; }
        .alert-success { background:#f0faf4; border:1px solid #a5d6a7; color:#2e7d32; }

        /* Submit button */
        .submit-section { position:sticky; bottom:0; background:#fff; padding:16px 24px; border-top:1px solid #f0f0f5; border-radius:0 0 16px 16px; }
        .btn-submit { width:100%; padding:14px; font-size:15px; font-weight:600; background:#0071e3; color:#fff; border:none; border-radius:10px; cursor:pointer; transition:background 0.2s; }
        .btn-submit:hover { background:#0077ed; }

        @media (max-width:1024px) { .form-layout { grid-template-columns:1fr; } }
        @media (max-width:768px) { .admin-sidebar { display:none; } .admin-main { margin-left:0; padding:20px; } }
    </style>
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body>

<div class="admin-wrap">

    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <a href="dashboard.php" class="sidebar-logo">
            <svg width="20" height="24" viewBox="0 0 814 1000" fill="currentColor"><path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-37.5-150.3-96.4c-52.1-66.5-101-170.5-101-269.3 0-170.4 111.4-260.5 220.5-260.5 57.5 0 105.3 38 140.9 38 33.9 0 87-40.3 154-40.3 24.9 0 108.2 2.3 159.8 86.4zm-216.8-78.2c32.1-38.1 53.7-90.8 53.7-143.5 0-7.4-.6-14.9-1.9-21-50.6 1.9-110.4 33.4-146.1 75.8-29.4 33.4-55.8 86.4-55.8 140.3 0 8.4 1.3 16.9 1.9 19.5 3.2.6 8.4 1.3 13.6 1.3 45.4 0 102.5-29.8 134.6-72.4z"/></svg>
            Admin Panel
        </a>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="dashboard.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Dashboard</a>
            <div class="nav-section">Catalog</div>
            <a href="products.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>All Products</a>
            <a href="add_product.php" class="active"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Add New Product</a>
            <div class="nav-section">Orders</div>
            <a href="orders.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>All Orders</a>
            <div class="nav-section">Users</div>
            <a href="customers.php"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Customers</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?></div>
                <div><div style="font-weight:600;color:#fff;font-size:13px;"><?= htmlspecialchars($_SESSION['full_name']) ?></div><div style="font-size:11px;">Administrator</div></div>
            </div>
            <a href="../index.html" class="sidebar-logout"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>View Store</a>
            <a href="../php/auth_check.php?action=logout" class="sidebar-logout"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign Out</a>
        </div>
    </aside>

    <!-- Main content -->
    <main class="admin-main">
        <a href="products.php" class="back-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Products
        </a>

        <div class="form-page-header">
            <h1>Add New Product</h1>
            <p>Fill in the details below. Fields marked * are required.</p>
        </div>

        <!-- Error messages -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>Please fix these errors:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <!--
            enctype="multipart/form-data" is REQUIRED for file uploads.
            Without it, PHP will never receive the uploaded file.
        -->
        <form method="POST" enctype="multipart/form-data">
            <div class="form-layout">

                <!-- ── LEFT COLUMN: Product details ── -->
                <div>
                    <!-- Basic info -->
                    <div class="form-card">
                        <h2>Product Information</h2>

                        <div class="form-row">
                            <div class="field-group">
                                <label for="name">Product Name *</label>
                                <input type="text" id="name" name="name"
                                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                                    placeholder="e.g. iPhone 16 Pro" required>
                            </div>
                            <div class="field-group">
                                <label for="category_id">Category *</label>
                                <select id="category_id" name="category_id" required>
                                    <option value="">— Select category —</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"
                                        <?= (($_POST['category_id'] ?? '') == $cat['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="field-group">
                            <label for="tagline">Tagline <span>(short marketing line)</span></label>
                            <input type="text" id="tagline" name="tagline"
                                value="<?= htmlspecialchars($_POST['tagline'] ?? '') ?>"
                                placeholder="e.g. Titanium. So strong. So light. So Pro.">
                        </div>

                        <div class="field-group">
                            <label for="description">Full Description</label>
                            <textarea id="description" name="description"
                                placeholder="Detailed product description shown on the product page..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="field-group">
                                <label for="base_price">Base Price (USD) *</label>
                                <input type="number" id="base_price" name="base_price"
                                    value="<?= htmlspecialchars($_POST['base_price'] ?? '') ?>"
                                    placeholder="1099.00" step="0.01" min="0.01" required>
                            </div>
                        </div>
                    </div>

                    <!-- Variants -->
                    <div class="form-card">
                        <h2>Product Variants <span style="font-size:13px;font-weight:400;color:#6e6e73;">(optional — e.g. 128GB Black, 256GB White)</span></h2>

                        <div class="variant-header">
                            <span>Color Name</span>
                            <span>Storage</span>
                            <span>Extra Price</span>
                            <span>Stock</span>
                            <span>Hex Color</span>
                            <span></span>
                        </div>

                        <div id="variantsList">
                            <!-- First variant row (always shown) -->
                            <div class="variant-row">
                                <input type="text" name="color[]" placeholder="e.g. Black Titanium">
                                <input type="text" name="storage[]" placeholder="e.g. 128GB">
                                <input type="number" name="price_modifier[]" placeholder="0.00" step="0.01" min="0" value="0">
                                <input type="number" name="stock[]" placeholder="10" min="0" value="10">
                                <input type="color" name="color_hex[]" value="#1a1a1a" title="Pick color swatch">
                                <button type="button" class="remove-variant-btn" onclick="removeVariant(this)" title="Remove">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                        </div>

                        <button type="button" id="addVariantBtn" onclick="addVariant()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Add Another Variant
                        </button>
                    </div>
                </div>

                <!-- ── RIGHT COLUMN: Image + settings ── -->
                <div>
                    <!-- Image upload -->
                    <div class="form-card">
                        <h2>Product Image</h2>

                        <div class="image-upload-area" id="uploadArea">
                            <!--
                                The input is hidden behind the div via position:absolute + opacity:0
                                Clicking anywhere on the box triggers the file picker
                            -->
                            <input type="file" name="image" id="imageInput" accept="image/*"
                                onchange="previewImage(this)">
                            <div class="upload-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </div>
                            <h3>Click to upload image</h3>
                            <p>JPG, PNG, WEBP up to 5MB</p>
                        </div>

                        <!-- Preview of selected image -->
                        <div class="image-preview" id="imagePreview">
                            <img id="previewImg" src="" alt="Preview">
                            <p style="font-size:12px;color:#6e6e73;margin-top:8px;text-align:center;" id="previewName"></p>
                        </div>

                        <p style="font-size:12px;color:#6e6e73;margin-top:12px;">
                            💡 <strong>Tip:</strong> Use a square image (1:1 ratio) with a white or transparent background for best results. Recommended size: 800×800px.
                        </p>
                    </div>

                    <!-- Settings -->
                    <div class="form-card">
                        <h2>Settings</h2>

                        <div class="toggle-group">
                            <div>
                                <div class="toggle-label">Featured Product</div>
                                <div class="toggle-sub">Show on the homepage featured section</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="is_featured" value="1"
                                    <?= isset($_POST['is_featured']) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Submit button -->
                    <div class="form-card" style="padding:0;overflow:hidden;">
                        <div class="submit-section" style="position:static;border-radius:16px;">
                            <button type="submit" class="btn-submit">
                                Save Product
                            </button>
                            <a href="products.php" style="display:block;text-align:center;margin-top:10px;color:#6e6e73;font-size:13px;">
                                Cancel
                            </a>
                        </div>
                    </div>
                </div>

            </div><!-- end .form-layout -->
        </form>
    </main>
</div>

<script src="https://unpkg.com/feather-icons"></script>
<script>
feather.replace();

// ── Preview image before upload ───────────────────────────
// When the user picks a file, show a preview so they can see it
function previewImage(input) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('previewName').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
        document.getElementById('imagePreview').style.display = 'block';
    };
    reader.readAsDataURL(file);
}

// ── Add a new variant row ─────────────────────────────────
function addVariant() {
    const row = document.createElement('div');
    row.className = 'variant-row';
    row.innerHTML = `
        <input type="text"   name="color[]"          placeholder="e.g. Silver">
        <input type="text"   name="storage[]"        placeholder="e.g. 256GB">
        <input type="number" name="price_modifier[]" placeholder="0.00" step="0.01" min="0" value="0">
        <input type="number" name="stock[]"          placeholder="10" min="0" value="10">
        <input type="color"  name="color_hex[]"      value="#cccccc" title="Pick color swatch">
        <button type="button" class="remove-variant-btn" onclick="removeVariant(this)" title="Remove">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    `;
    document.getElementById('variantsList').appendChild(row);
}

// ── Remove a variant row ──────────────────────────────────
function removeVariant(btn) {
    const row = btn.closest('.variant-row');
    // Don't remove if it's the only row
    if (document.querySelectorAll('.variant-row').length > 1) {
        row.remove();
    }
}
</script>
</body>
</html>