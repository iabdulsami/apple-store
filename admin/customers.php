<?php
/*
=============================================================
  FILE: admin/customers.php
  PATH: apple-store/admin/customers.php
  PURPOSE: Admin can see all registered customers,
           how many orders they placed, total spent,
           and click to see their full order history.
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

// ── Handle AJAX: delete customer ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action     = $_POST['action']      ?? '';
    $customerId = (int)($_POST['id']    ?? 0);

    if ($action === 'delete' && $customerId) {
        // Delete cart items first (foreign key), then user
        $db->query("DELETE FROM cart WHERE user_id = $customerId");
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'customer'");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Search / filter ───────────────────────────────────────
$search  = sanitize($_GET['q'] ?? '');
$viewId  = (int)($_GET['id']   ?? 0); // view one customer's orders

// ── Load customers with their order stats ─────────────────
$whereSearch = $search
    ? "AND (u.full_name LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%')"
    : '';

$customers = $db->query("
    SELECT
        u.id, u.full_name, u.email, u.phone, u.city, u.country, u.created_at,
        COUNT(o.id)            AS order_count,
        COALESCE(SUM(o.total), 0) AS total_spent,
        MAX(o.created_at)      AS last_order_date
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
    WHERE u.role = 'customer'
    $whereSearch
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// ── Load single customer's orders if ?id= given ───────────
$customerDetail = null;
$customerOrders = [];
if ($viewId) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $customerDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($customerDetail) {
        $stmt2 = $db->prepare("
            SELECT o.*, COUNT(oi.id) AS item_count
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ");
        $stmt2->bind_param('i', $viewId);
        $stmt2->execute();
        $customerOrders = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    }
}

// ── Stats for header cards ────────────────────────────────
$totalCustomers  = count($customers);
$totalWithOrders = count(array_filter($customers, fn($c) => $c['order_count'] > 0));
$totalRevenue    = array_sum(array_column($customers, 'total_spent'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers — Admin</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body{background:#f5f5f7}
        .admin-wrap{display:flex;min-height:100vh}

        /* ── Sidebar ── */
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

        /* ── Main ── */
        .admin-main{margin-left:240px;flex:1;padding:32px}
        .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
        .page-header h1{font-size:26px;font-weight:700;color:#1d1d1f}

        /* ── Mini stats ── */
        .mini-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
        .mini-stat{background:#fff;border-radius:14px;padding:20px 22px;box-shadow:0 2px 10px rgba(0,0,0,0.05)}
        .mini-stat .ms-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#6e6e73;margin-bottom:6px}
        .mini-stat .ms-value{font-size:28px;font-weight:700;color:#1d1d1f;letter-spacing:-1px}

        /* ── Toolbar ── */
        .toolbar{display:flex;gap:10px;margin-bottom:20px;align-items:center}
        .toolbar input{padding:9px 14px;border:1.5px solid #d2d2d7;border-radius:10px;font-size:14px;outline:none;min-width:280px;background:#fff}
        .toolbar input:focus{border-color:#0071e3}
        .toolbar button{padding:9px 18px;background:#0071e3;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer}

        /* ── Table ── */
        .table-card{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);overflow:hidden}
        .customers-table{width:100%;border-collapse:collapse}
        .customers-table th{padding:12px 20px;text-align:left;font-size:11px;font-weight:700;color:#6e6e73;text-transform:uppercase;letter-spacing:0.5px;background:#fafafa;border-bottom:1px solid #f0f0f5}
        .customers-table td{padding:14px 20px;font-size:13px;border-bottom:1px solid #f5f5f7;vertical-align:middle}
        .customers-table tr:last-child td{border-bottom:none}
        .customers-table tr:hover td{background:#fafafa}

        .customer-avatar-sm{width:36px;height:36px;border-radius:50%;background:#0071e3;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
        .customer-cell{display:flex;align-items:center;gap:12px}
        .cust-name{font-weight:600;font-size:14px}
        .cust-email{font-size:11px;color:#6e6e73;margin-top:1px}
        .cust-phone{font-size:12px;color:#6e6e73}
        .spent-value{font-weight:700;color:#1d1d1f}
        .no-orders{color:#d2d2d7;font-size:13px}
        .empty-state{text-align:center;padding:60px;color:#6e6e73;font-size:15px}

        .action-btns{display:flex;gap:6px}
        .action-btn{padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all 0.2s}
        .btn-view{background:#f0f6ff;color:#0071e3}
        .btn-view:hover{background:#0071e3;color:#fff;text-decoration:none}
        .btn-delete{background:#fff0f0;color:#ff3b30}
        .btn-delete:hover{background:#ff3b30;color:#fff}

        /* ── Customer detail slide panel ── */
        .detail-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:500;backdrop-filter:blur(4px)}
        .detail-overlay.open{display:flex;align-items:flex-start;justify-content:flex-end}
        .detail-panel{background:#fff;width:500px;max-width:95vw;height:100vh;overflow-y:auto;box-shadow:-8px 0 40px rgba(0,0,0,0.15);display:flex;flex-direction:column}
        .detail-header{display:flex;justify-content:space-between;align-items:center;padding:24px;border-bottom:1px solid #f0f0f5;position:sticky;top:0;background:#fff;z-index:1}
        .detail-header h2{font-size:18px;font-weight:700}
        .detail-close{background:#f5f5f7;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;color:#1d1d1f}
        .detail-body{padding:24px;flex:1}
        .detail-section{margin-bottom:24px}
        .detail-section h3{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#6e6e73;margin-bottom:12px}
        .detail-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f5f5f7;font-size:14px}
        .detail-row:last-child{border-bottom:none}
        .detail-row span:first-child{color:#6e6e73}

        /* Order mini cards inside customer detail */
        .mini-order{background:#f9f9fb;border-radius:10px;padding:12px 14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;transition:background 0.15s;text-decoration:none}
        .mini-order:hover{background:#f0f0f5;text-decoration:none}
        .mini-order-num{font-weight:700;font-size:13px;color:#0071e3}
        .mini-order-date{font-size:11px;color:#6e6e73;margin-top:2px}
        .mini-order-total{font-weight:700;font-size:14px;color:#1d1d1f}
        .no-orders-msg{text-align:center;padding:24px;color:#6e6e73;font-size:14px}

        /* Big avatar in detail panel */
        .big-avatar{width:64px;height:64px;background:#0071e3;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:28px;margin-bottom:16px}

        @media(max-width:768px){.admin-sidebar{display:none}.admin-main{margin-left:0;padding:20px}.mini-stats{grid-template-columns:1fr 1fr}.detail-panel{width:100vw}}
    </style>
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body>
<div class="admin-wrap">

    <!-- ===== SIDEBAR ===== -->
    <aside class="admin-sidebar">
        <a href="dashboard.php" class="sidebar-logo">
            <svg width="20" height="24" viewBox="0 0 814 1000" fill="currentColor"><path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-37.5-150.3-96.4c-52.1-66.5-101-170.5-101-269.3 0-170.4 111.4-260.5 220.5-260.5 57.5 0 105.3 38 140.9 38 33.9 0 87-40.3 154-40.3 24.9 0 108.2 2.3 159.8 86.4zm-216.8-78.2c32.1-38.1 53.7-90.8 53.7-143.5 0-7.4-.6-14.9-1.9-21-50.6 1.9-110.4 33.4-146.1 75.8-29.4 33.4-55.8 86.4-55.8 140.3 0 8.4 1.3 16.9 1.9 19.5 3.2.6 8.4 1.3 13.6 1.3 45.4 0 102.5-29.8 134.6-72.4z"/></svg>
            Admin Panel
        </a>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="dashboard.php">
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
            <div class="nav-section">Orders</div>
            <a href="orders.php">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                All Orders
            </a>
            <div class="nav-section">Users</div>
            <a href="customers.php" class="active">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                Customers
            </a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
                <div>
                    <div style="font-weight:600;color:#fff;font-size:13px;"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                    <div style="font-size:11px;">Administrator</div>
                </div>
            </div>
            <a href="../index.html" class="sidebar-logout">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                View Store
            </a>
            <a href="../php/auth_check.php?action=logout" class="sidebar-logout">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
            </a>
        </div>
    </aside>

    <!-- ===== MAIN ===== -->
    <main class="admin-main">

        <div class="page-header">
            <div>
                <h1>Customers</h1>
                <p style="color:#6e6e73;font-size:14px;margin-top:2px;"><?= $totalCustomers ?> registered customers</p>
            </div>
        </div>

        <!-- Mini stats -->
        <div class="mini-stats">
            <div class="mini-stat">
                <div class="ms-label">Total Customers</div>
                <div class="ms-value"><?= $totalCustomers ?></div>
            </div>
            <div class="mini-stat">
                <div class="ms-label">Have Ordered</div>
                <div class="ms-value"><?= $totalWithOrders ?></div>
            </div>
            <div class="mini-stat">
                <div class="ms-label">Total Revenue</div>
                <div class="ms-value">$<?= number_format($totalRevenue, 0) ?></div>
            </div>
        </div>

        <!-- Search -->
        <form method="GET" class="toolbar">
            <input type="text" name="q" placeholder="Search by name, email or phone…" value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
            <?php if ($search): ?>
            <a href="customers.php" style="color:#6e6e73;font-size:13px;align-self:center;padding:0 6px;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Customers table -->
        <div class="table-card">
            <table class="customers-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Location</th>
                        <th>Orders</th>
                        <th>Total Spent</th>
                        <th>Last Order</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                    <tr><td colspan="8" class="empty-state">No customers found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr id="cust-row-<?= $c['id'] ?>">
                        <td>
                            <div class="customer-cell">
                                <!-- Avatar: first letter of name on a blue circle -->
                                <div class="customer-avatar-sm">
                                    <?= strtoupper(substr($c['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="cust-name"><?= htmlspecialchars($c['full_name']) ?></div>
                                    <div class="cust-email"><?= htmlspecialchars($c['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="cust-phone"><?= $c['phone'] ? htmlspecialchars($c['phone']) : '<span style="color:#d2d2d7;">—</span>' ?></td>
                        <td style="color:#6e6e73;font-size:13px;">
                            <?= $c['city'] ? htmlspecialchars($c['city']) . ', ' : '' ?><?= htmlspecialchars($c['country'] ?? 'Pakistan') ?>
                        </td>
                        <td>
                            <?php if ($c['order_count'] > 0): ?>
                                <span style="font-weight:700;"><?= $c['order_count'] ?></span>
                                <span style="color:#6e6e73;font-size:12px;"> order<?= $c['order_count']!=1?'s':'' ?></span>
                            <?php else: ?>
                                <span class="no-orders">No orders</span>
                            <?php endif; ?>
                        </td>
                        <td class="spent-value">
                            <?= $c['total_spent'] > 0 ? '$'.number_format((float)$c['total_spent'],2) : '<span style="color:#d2d2d7;">$0.00</span>' ?>
                        </td>
                        <td style="color:#6e6e73;font-size:12px;">
                            <?= $c['last_order_date'] ? date('M d, Y', strtotime($c['last_order_date'])) : '—' ?>
                        </td>
                        <td style="color:#6e6e73;font-size:12px;">
                            <?= date('M d, Y', strtotime($c['created_at'])) ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="action-btn btn-view" onclick="openCustomer(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['full_name'])) ?>', '<?= addslashes(htmlspecialchars($c['email'])) ?>', '<?= addslashes(htmlspecialchars($c['phone'] ?? '')) ?>', '<?= addslashes(htmlspecialchars(($c['city']?$c['city'].', ':'').($c['country']??'Pakistan'))) ?>', <?= $c['order_count'] ?>, <?= number_format((float)$c['total_spent'],2) ?>, '<?= date('M d, Y', strtotime($c['created_at'])) ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    View
                                </button>
                                <button class="action-btn btn-delete" onclick="deleteCustomer(<?= $c['id'] ?>, '<?= addslashes(htmlspecialchars($c['full_name'])) ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>
                                    Delete
                                </button>
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

<!-- ===== CUSTOMER DETAIL PANEL ===== -->
<div class="detail-overlay" id="detailOverlay" onclick="closeDetail(event)">
    <div class="detail-panel" id="detailPanel">
        <div class="detail-header">
            <h2 id="detailTitle">Customer Profile</h2>
            <button class="detail-close" onclick="document.getElementById('detailOverlay').classList.remove('open')">✕</button>
        </div>
        <div class="detail-body" id="detailBody">
            <p style="text-align:center;color:#6e6e73;padding:40px;">Loading…</p>
        </div>
    </div>
</div>

<script src="https://unpkg.com/feather-icons"></script>
<script>
feather.replace();

// ── Open customer detail panel ────────────────────────────
function openCustomer(id, name, email, phone, location, orderCount, totalSpent, joined) {
    document.getElementById('detailTitle').textContent = name;
    document.getElementById('detailOverlay').classList.add('open');

    // Render basic info immediately from passed data
    const initial = name.charAt(0).toUpperCase();
    let bodyHtml = `
        <div style="text-align:center;margin-bottom:24px;">
            <div class="big-avatar" style="margin:0 auto 12px;">${initial}</div>
            <div style="font-size:20px;font-weight:700;">${name}</div>
            <div style="font-size:14px;color:#6e6e73;">${email}</div>
        </div>

        <div class="detail-section">
            <h3>Profile</h3>
            <div class="detail-row"><span>Email</span><span>${email}</span></div>
            <div class="detail-row"><span>Phone</span><span>${phone || '—'}</span></div>
            <div class="detail-row"><span>Location</span><span>${location}</span></div>
            <div class="detail-row"><span>Joined</span><span>${joined}</span></div>
        </div>

        <div class="detail-section">
            <h3>Stats</h3>
            <div class="detail-row"><span>Total Orders</span><span style="font-weight:700;">${orderCount}</span></div>
            <div class="detail-row"><span>Total Spent</span><span style="font-weight:700;color:#30d158;">$${parseFloat(totalSpent).toFixed(2)}</span></div>
        </div>

        <div class="detail-section" id="ordersSection">
            <h3>Order History</h3>
            <p style="color:#6e6e73;font-size:13px;text-align:center;padding:12px;">Loading orders…</p>
        </div>
    `;
    document.getElementById('detailBody').innerHTML = bodyHtml;

    // Now load their orders via AJAX
    fetch('../php/checkout.php?action=list&user_id=' + id, {
        credentials: 'include'
    })
    .then(r => r.json())
    .then(res => {
        const section = document.getElementById('ordersSection');
        if (!section) return;

        if (res.success && res.orders && res.orders.length > 0) {
            const statusColors = {
                pending:'#856404', processing:'#004085',
                shipped:'#155724', delivered:'#0c5460', cancelled:'#721c24'
            };
            const statusBg = {
                pending:'#fff3cd', processing:'#cce5ff',
                shipped:'#d4edda', delivered:'#d1ecf1', cancelled:'#f8d7da'
            };

            let ordersHtml = '<h3>Order History</h3>';
            res.orders.forEach(function(o) {
                const date = new Date(o.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                ordersHtml += `
                    <a href="orders.php?id=${o.id}" class="mini-order">
                        <div>
                            <div class="mini-order-num">${o.order_number}</div>
                            <div class="mini-order-date">${date} · ${o.item_count} item${o.item_count!=1?'s':''}</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="background:${statusBg[o.status]};color:${statusColors[o.status]};padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">
                                ${o.status.charAt(0).toUpperCase()+o.status.slice(1)}
                            </span>
                            <span class="mini-order-total">$${parseFloat(o.total).toFixed(2)}</span>
                        </div>
                    </a>`;
            });
            section.innerHTML = ordersHtml;
        } else {
            section.innerHTML = '<h3>Order History</h3><div class="no-orders-msg">This customer has not placed any orders yet.</div>';
        }
    })
    .catch(() => {
        const section = document.getElementById('ordersSection');
        if (section) section.innerHTML = '<h3>Order History</h3><p style="color:#6e6e73;font-size:13px;padding:12px;">Could not load orders.</p>';
    });
}

// Close overlay when clicking background
function closeDetail(e) {
    if (e && e.target !== document.getElementById('detailOverlay')) return;
    document.getElementById('detailOverlay').classList.remove('open');
}

// Escape key to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('detailOverlay').classList.remove('open');
});

// ── Delete customer ───────────────────────────────────────
function deleteCustomer(id, name) {
    if (!confirm('Delete customer "' + name + '"?\n\nThis will permanently remove their account and cart. Their orders will remain in the database.')) return;

    fetch('customers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax=1&action=delete&id=' + id
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            // Remove the row from the table smoothly
            const row = document.getElementById('cust-row-' + id);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 300);
            }
        } else {
            alert('Could not delete customer.');
        }
    });
}

// ── Auto open detail if ?id= is in URL ───────────────────
<?php if ($viewId && $customerDetail): ?>
window.addEventListener('DOMContentLoaded', function() {
    openCustomer(
        <?= $customerDetail['id'] ?>,
        '<?= addslashes(htmlspecialchars($customerDetail['full_name'])) ?>',
        '<?= addslashes(htmlspecialchars($customerDetail['email'])) ?>',
        '<?= addslashes(htmlspecialchars($customerDetail['phone'] ?? '')) ?>',
        '<?= addslashes(htmlspecialchars(($customerDetail['city']?$customerDetail['city'].', ':'').($customerDetail['country']??'Pakistan'))) ?>',
        <?= count($customerOrders) ?>,
        <?= array_sum(array_column($customerOrders, 'total')) ?>,
        '<?= date('M d, Y', strtotime($customerDetail['created_at'])) ?>'
    );
});
<?php endif; ?>
</script>
</body>
</html>