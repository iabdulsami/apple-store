<?php
/*
=============================================================
  FILE: admin/dashboard.php
  PATH: apple-store/admin/dashboard.php
  PURPOSE: Admin panel home — shows stats, recent orders,
           and links to manage products and orders.
  ACCESS: Admin only. Redirects to login if not admin.
=============================================================
*/
ob_start();
session_start();

// ── Auth guard: redirect non-admins immediately ───────────
// We do this BEFORE requiring config so we can use header()
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.html');
    exit;
}

require_once '../php/config.php';
$db = getDB();

// ── Fetch dashboard statistics ────────────────────────────

// Total revenue from delivered + processing + shipped orders
$revenueRow = $db->query("SELECT COALESCE(SUM(total),0) AS total_revenue FROM orders WHERE status != 'cancelled'")->fetch_assoc();
$totalRevenue = (float)$revenueRow['total_revenue'];

// Total number of orders
$ordersRow = $db->query("SELECT COUNT(*) AS cnt FROM orders")->fetch_assoc();
$totalOrders = (int)$ordersRow['cnt'];

// Total customers
$customersRow = $db->query("SELECT COUNT(*) AS cnt FROM users WHERE role='customer'")->fetch_assoc();
$totalCustomers = (int)$customersRow['cnt'];

// Total active products
$productsRow = $db->query("SELECT COUNT(*) AS cnt FROM products WHERE is_active=1")->fetch_assoc();
$totalProducts = (int)$productsRow['cnt'];

// Pending orders count
$pendingRow = $db->query("SELECT COUNT(*) AS cnt FROM orders WHERE status='pending'")->fetch_assoc();
$pendingOrders = (int)$pendingRow['cnt'];

// Recent 10 orders with customer name
$recentOrders = $db->query("
    SELECT o.id, o.order_number, o.total, o.status, o.created_at,
           u.full_name, u.email
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Status badge CSS classes
$statusBadge = [
    'pending'    => 'badge-pending',
    'processing' => 'badge-processing',
    'shipped'    => 'badge-shipped',
    'delivered'  => 'badge-delivered',
    'cancelled'  => 'badge-cancelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Apple Store</title>
    <!-- Same CSS as the rest of the site -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Extra admin-only styles below -->
    <style>
        /* ── Admin layout ── */
        body { background: #f5f5f7; }

        .admin-wrap {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .admin-sidebar {
            width: 240px;
            background: #1d1d1f;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            overflow-y: auto;
            z-index: 100;
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
        }
        .sidebar-logo svg { color: #fff; }
        .sidebar-nav { padding: 16px 12px; flex: 1; }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            color: rgba(255,255,255,0.65);
            border-radius: 9px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            margin-bottom: 3px;
            transition: all 0.2s;
        }
        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
            text-decoration: none;
        }
        .sidebar-nav a svg { width: 16px; height: 16px; flex-shrink: 0; }
        .sidebar-nav .nav-section {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 16px 14px 6px;
        }
        .sidebar-bottom {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: rgba(255,255,255,0.7);
            font-size: 13px;
        }
        .sidebar-avatar {
            width: 32px; height: 32px;
            background: #0071e3;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 14px;
            flex-shrink: 0;
        }
        .sidebar-logout {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 14px; color: rgba(255,255,255,0.5);
            font-size: 13px; text-decoration: none; border-radius: 8px;
            transition: all 0.2s; margin-top: 4px;
        }
        .sidebar-logout:hover { background: rgba(255,59,48,0.15); color: #ff3b30; text-decoration: none; }
        .sidebar-logout svg { width: 14px; height: 14px; }

        /* ── Main content ── */
        .admin-main {
            margin-left: 240px;
            flex: 1;
            padding: 32px;
            min-height: 100vh;
        }

        /* ── Page header ── */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1d1d1f;
        }
        .page-header p { color: #6e6e73; font-size: 14px; margin-top: 2px; }

        /* ── Stats grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }
        .stat-card .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px;
        }
        .stat-card .stat-icon svg { width: 22px; height: 22px; }
        .stat-card .stat-label {
            font-size: 12px; font-weight: 600;
            color: #6e6e73; text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .stat-card .stat-value {
            font-size: 30px; font-weight: 700; color: #1d1d1f;
            letter-spacing: -1px;
        }
        .stat-card .stat-sub { font-size: 12px; color: #6e6e73; margin-top: 4px; }
        .icon-blue   { background: #e8f0fe; color: #0071e3; }
        .icon-green  { background: #e8f8f0; color: #30d158; }
        .icon-orange { background: #fff4e6; color: #ff9f0a; }
        .icon-purple { background: #f3e8ff; color: #bf5af2; }

        /* ── Section card ── */
        .section-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .section-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f5;
        }
        .section-card-header h2 {
            font-size: 16px;
            font-weight: 700;
            color: #1d1d1f;
        }
        .section-card-header a {
            font-size: 13px;
            color: #0071e3;
            font-weight: 500;
        }

        /* ── Admin table ── */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }
        .admin-table th {
            padding: 12px 24px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: #6e6e73;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #fafafa;
            border-bottom: 1px solid #f0f0f5;
        }
        .admin-table td {
            padding: 14px 24px;
            font-size: 13px;
            color: #1d1d1f;
            border-bottom: 1px solid #f5f5f7;
            vertical-align: middle;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tr:hover td { background: #fafafa; }
        .order-num { font-weight: 700; color: #0071e3; }
        .customer-name { font-weight: 600; }
        .customer-email { font-size: 12px; color: #6e6e73; }

        /* ── Pending badge ── */
        .pending-alert {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff3cd; color: #856404;
            padding: 6px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
        }

        /* ── Quick actions ── */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 28px;
        }
        .quick-action-btn {
            display: flex; align-items: center; gap: 12px;
            background: #fff;
            border: none; border-radius: 14px;
            padding: 18px 20px;
            cursor: pointer;
            text-decoration: none;
            color: #1d1d1f;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: all 0.2s;
        }
        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
            text-decoration: none;
            color: #1d1d1f;
        }
        .quick-action-btn .qa-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .quick-action-btn .qa-icon svg { width: 20px; height: 20px; }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .quick-actions { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .admin-sidebar { display: none; }
            .admin-main { margin-left: 0; padding: 20px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body>

<div class="admin-wrap">

    <!-- ===== SIDEBAR ===== -->
    <aside class="admin-sidebar">
        <a href="dashboard.php" class="sidebar-logo">
            <svg width="20" height="24" viewBox="0 0 814 1000" fill="currentColor">
                <path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-37.5-150.3-96.4c-52.1-66.5-101-170.5-101-269.3 0-170.4 111.4-260.5 220.5-260.5 57.5 0 105.3 38 140.9 38 33.9 0 87-40.3 154-40.3 24.9 0 108.2 2.3 159.8 86.4zm-216.8-78.2c32.1-38.1 53.7-90.8 53.7-143.5 0-7.4-.6-14.9-1.9-21-50.6 1.9-110.4 33.4-146.1 75.8-29.4 33.4-55.8 86.4-55.8 140.3 0 8.4 1.3 16.9 1.9 19.5 3.2.6 8.4 1.3 13.6 1.3 45.4 0 102.5-29.8 134.6-72.4z"/>
            </svg>
            Admin Panel
        </a>

        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="dashboard.php" class="active">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>

            <div class="nav-section">Catalog</div>
            <a href="products.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                All Products
            </a>
            <a href="add_product.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                Add New Product
            </a>
            <a href="categories.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Categories
            </a>

            <div class="nav-section">Orders</div>
            <a href="orders.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                All Orders
                <?php if ($pendingOrders > 0): ?>
                <span style="margin-left:auto;background:#ff3b30;color:#fff;border-radius:10px;padding:2px 7px;font-size:11px;font-weight:700;"><?= $pendingOrders ?></span>
                <?php endif; ?>
            </a>

            <div class="nav-section">Users</div>
            <a href="customers.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                Customers
            </a>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?></div>
                <div>
                    <div style="font-weight:600;color:#fff;font-size:13px;"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                    <div style="font-size:11px;">Administrator</div>
                </div>
            </div>
            <a href="../index.html" class="sidebar-logout">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                View Store
            </a>
            <a href="../php/auth_check.php?action=logout" class="sidebar-logout" onclick="return confirm('Sign out?')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
            </a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="admin-main">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?>! Here's what's happening.</p>
            </div>
            <?php if ($pendingOrders > 0): ?>
            <a href="orders.php?status=pending" class="pending-alert">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <?= $pendingOrders ?> Pending Order<?= $pendingOrders > 1 ? 's' : '' ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- ── Stats cards ── -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value">$<?= number_format($totalRevenue, 0) ?></div>
                <div class="stat-sub">All completed orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-blue">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                </div>
                <div class="stat-label">Total Orders</div>
                <div class="stat-value"><?= $totalOrders ?></div>
                <div class="stat-sub"><?= $pendingOrders ?> pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                </div>
                <div class="stat-label">Customers</div>
                <div class="stat-value"><?= $totalCustomers ?></div>
                <div class="stat-sub">Registered accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-orange">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </div>
                <div class="stat-label">Products</div>
                <div class="stat-value"><?= $totalProducts ?></div>
                <div class="stat-sub">Active listings</div>
            </div>
        </div>

        <!-- ── Quick actions ── -->
        <div class="quick-actions">
            <a href="add_product.php" class="quick-action-btn">
                <div class="qa-icon icon-blue">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                </div>
                <div>
                    <div>Add New Product</div>
                    <div style="font-size:12px;color:#6e6e73;font-weight:400;">Upload image + details</div>
                </div>
            </a>
            <a href="orders.php" class="quick-action-btn">
                <div class="qa-icon icon-orange">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div>
                    <div>Manage Orders</div>
                    <div style="font-size:12px;color:#6e6e73;font-weight:400;">Update order status</div>
                </div>
            </a>
            <a href="products.php" class="quick-action-btn">
                <div class="qa-icon icon-purple">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <div>Edit Products</div>
                    <div style="font-size:12px;color:#6e6e73;font-weight:400;">Update prices, images</div>
                </div>
            </a>
        </div>

        <!-- ── Recent orders table ── -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Recent Orders</h2>
                <a href="orders.php">View all →</a>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentOrders)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#6e6e73;padding:40px;">No orders yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($recentOrders as $order): ?>
                    <tr>
                        <td><span class="order-num"><?= htmlspecialchars($order['order_number']) ?></span></td>
                        <td>
                            <div class="customer-name"><?= htmlspecialchars($order['full_name']) ?></div>
                            <div class="customer-email"><?= htmlspecialchars($order['email']) ?></div>
                        </td>
                        <td><strong>$<?= number_format((float)$order['total'], 2) ?></strong></td>
                        <td>
                            <span class="badge <?= $statusBadge[$order['status']] ?? 'badge-pending' ?>">
                                <?= ucfirst($order['status']) ?>
                            </span>
                        </td>
                        <td style="color:#6e6e73;">
                            <?= date('M d, Y', strtotime($order['created_at'])) ?>
                        </td>
                        <td>
                            <a href="orders.php?id=<?= $order['id'] ?>" style="color:#0071e3;font-size:13px;font-weight:500;">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main><!-- end .admin-main -->
</div><!-- end .admin-wrap -->

<script src="https://unpkg.com/feather-icons"></script>
<script>feather.replace();</script>
</body>
</html>