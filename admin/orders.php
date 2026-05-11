<?php
/*
=============================================================
  FILE: admin/orders.php
  PATH: apple-store/admin/orders.php
  PURPOSE: Admin can see ALL orders, filter by status,
           view order details, and update order status.
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

// ── Handle AJAX: update order status ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $orderId   = (int)($_POST['order_id'] ?? 0);
    $newStatus = sanitize($_POST['status'] ?? '');
    $allowed   = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    if (!$orderId || !in_array($newStatus, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data.']);
        exit;
    }

    $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $newStatus, $orderId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

// ── Filters ───────────────────────────────────────────────
$filterStatus = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q']      ?? '');
$viewId       = (int)($_GET['id']        ?? 0); // view single order detail

// ── Load single order detail ──────────────────────────────
$orderDetail = null;
if ($viewId) {
    $stmt = $db->prepare("
        SELECT o.*, u.full_name, u.email, u.phone
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $orderDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($orderDetail) {
        // Get all items in this order
        $stmt2 = $db->prepare("
            SELECT oi.*, p.image_main
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmt2->bind_param('i', $viewId);
        $stmt2->execute();
        $orderDetail['items'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    }
}

// ── Load orders list ──────────────────────────────────────
$where = "WHERE 1=1";
if ($filterStatus) $where .= " AND o.status = '" . $db->real_escape_string($filterStatus) . "'";
if ($search)       $where .= " AND (o.order_number LIKE '%$search%' OR u.full_name LIKE '%$search%' OR u.email LIKE '%$search%')";

$orders = $db->query("
    SELECT o.id, o.order_number, o.total, o.status, o.created_at,
           o.payment_method, o.shipping_address,
           u.full_name, u.email,
           COUNT(oi.id) AS item_count
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    $where
    GROUP BY o.id
    ORDER BY o.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Count by status for tab badges
$statusCounts = [];
$countRows = $db->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status")->fetch_all(MYSQLI_ASSOC);
foreach ($countRows as $r) $statusCounts[$r['status']] = $r['cnt'];

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
    <title>Orders — Admin</title>
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

        /* ── Status tabs ── */
        .status-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
        .status-tab{padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;background:#fff;color:#6e6e73;border:1.5px solid #e8e8ed;transition:all 0.2s}
        .status-tab:hover{border-color:#0071e3;color:#0071e3;text-decoration:none}
        .status-tab.active{background:#0071e3;color:#fff;border-color:#0071e3}
        .tab-count{background:rgba(0,0,0,0.1);border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px}
        .status-tab.active .tab-count{background:rgba(255,255,255,0.25)}

        /* ── Search bar ── */
        .toolbar{display:flex;gap:10px;margin-bottom:20px;align-items:center}
        .toolbar input{padding:9px 14px;border:1.5px solid #d2d2d7;border-radius:10px;font-size:14px;outline:none;min-width:260px;background:#fff}
        .toolbar input:focus{border-color:#0071e3}
        .toolbar button{padding:9px 18px;background:#0071e3;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer}

        /* ── Orders table ── */
        .table-card{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);overflow:hidden}
        .orders-table{width:100%;border-collapse:collapse}
        .orders-table th{padding:12px 20px;text-align:left;font-size:11px;font-weight:700;color:#6e6e73;text-transform:uppercase;letter-spacing:0.5px;background:#fafafa;border-bottom:1px solid #f0f0f5}
        .orders-table td{padding:14px 20px;font-size:13px;border-bottom:1px solid #f5f5f7;vertical-align:middle}
        .orders-table tr:last-child td{border-bottom:none}
        .orders-table tr:hover td{background:#fafafa;cursor:pointer}
        .order-num{font-weight:700;color:#0071e3;font-size:14px}
        .customer-name{font-weight:600;font-size:13px}
        .customer-email{font-size:11px;color:#6e6e73;margin-top:2px}
        .empty-state{text-align:center;padding:60px;color:#6e6e73;font-size:15px}

        /* Status selector dropdown in table */
        .status-select{padding:6px 10px;border-radius:8px;border:1.5px solid #e8e8ed;font-size:12px;font-weight:600;cursor:pointer;outline:none;background:#fff;transition:border-color 0.2s}
        .status-select:focus{border-color:#0071e3}
        .status-select.pending{color:#856404;background:#fff3cd;border-color:#ffc107}
        .status-select.processing{color:#004085;background:#cce5ff;border-color:#74b9ff}
        .status-select.shipped{color:#155724;background:#d4edda;border-color:#28a745}
        .status-select.delivered{color:#0c5460;background:#d1ecf1;border-color:#17a2b8}
        .status-select.cancelled{color:#721c24;background:#f8d7da;border-color:#dc3545}

        /* ── Order detail panel ── */
        .detail-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:500;backdrop-filter:blur(4px)}
        .detail-overlay.open{display:flex;align-items:flex-start;justify-content:flex-end}
        .detail-panel{background:#fff;width:520px;max-width:95vw;height:100vh;overflow-y:auto;box-shadow:-8px 0 40px rgba(0,0,0,0.15);display:flex;flex-direction:column}
        .detail-header{display:flex;justify-content:space-between;align-items:center;padding:24px;border-bottom:1px solid #f0f0f5;position:sticky;top:0;background:#fff;z-index:1}
        .detail-header h2{font-size:18px;font-weight:700}
        .detail-close{background:#f5f5f7;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;color:#1d1d1f}
        .detail-body{padding:24px;flex:1}
        .detail-section{margin-bottom:24px}
        .detail-section h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#6e6e73;margin-bottom:12px}
        .detail-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f5f5f7;font-size:14px}
        .detail-row:last-child{border-bottom:none}
        .detail-row span:first-child{color:#6e6e73}
        .detail-row span:last-child{font-weight:500}
        .order-item-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f5f5f7}
        .order-item-row:last-child{border-bottom:none}
        .item-thumb{width:48px;height:48px;background:#f5f5f7;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
        .item-thumb img{width:100%;height:100%;object-fit:contain;padding:4px}
        .total-row{display:flex;justify-content:space-between;font-size:16px;font-weight:700;padding:14px 0 0;margin-top:8px;border-top:2px solid #1d1d1f}

        /* Status update button inside detail */
        .status-update-form{display:flex;gap:8px;align-items:center;margin-top:16px}
        .status-update-form select{flex:1;padding:10px 14px;border:1.5px solid #d2d2d7;border-radius:10px;font-size:14px;outline:none}
        .status-update-form button{padding:10px 18px;background:#0071e3;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer}
        .save-badge{font-size:12px;color:#30d158;font-weight:600;display:none}

        @media(max-width:768px){.admin-sidebar{display:none}.admin-main{margin-left:0;padding:20px}.detail-panel{width:100vw}}
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
            <a href="orders.php" class="active">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                All Orders
            </a>
            <div class="nav-section">Users</div>
            <a href="customers.php">
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

    <!-- ===== MAIN CONTENT ===== -->
    <main class="admin-main">

        <div class="page-header">
            <div>
                <h1>Orders</h1>
                <p style="color:#6e6e73;font-size:14px;margin-top:2px;"><?= count($orders) ?> orders found</p>
            </div>
        </div>

        <!-- Status filter tabs -->
        <div class="status-tabs">
            <a href="orders.php" class="status-tab <?= !$filterStatus ? 'active' : '' ?>">
                All <span class="tab-count"><?= array_sum($statusCounts) ?></span>
            </a>
            <?php
            $tabList = ['pending'=>'Pending','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'];
            foreach ($tabList as $val => $label):
                $cnt = $statusCounts[$val] ?? 0;
            ?>
            <a href="orders.php?status=<?= $val ?>" class="status-tab <?= $filterStatus===$val ? 'active':'' ?>">
                <?= $label ?> <span class="tab-count"><?= $cnt ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search bar -->
        <form method="GET" class="toolbar">
            <?php if ($filterStatus): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>
            <input type="text" name="q" placeholder="Search by order #, customer name or email…" value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
            <?php if ($search): ?>
            <a href="orders.php<?= $filterStatus ? '?status='.$filterStatus : '' ?>" style="color:#6e6e73;font-size:13px;align-self:center;padding:0 6px;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Orders table -->
        <div class="table-card">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="8" class="empty-state">No orders found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                    <tr onclick="openDetail(<?= $o['id'] ?>)" id="order-row-<?= $o['id'] ?>">
                        <td><span class="order-num"><?= htmlspecialchars($o['order_number']) ?></span></td>
                        <td>
                            <div class="customer-name"><?= htmlspecialchars($o['full_name']) ?></div>
                            <div class="customer-email"><?= htmlspecialchars($o['email']) ?></div>
                        </td>
                        <td style="color:#6e6e73;"><?= $o['item_count'] ?> item<?= $o['item_count']!=1?'s':'' ?></td>
                        <td><strong>$<?= number_format((float)$o['total'],2) ?></strong></td>
                        <td style="color:#6e6e73;"><?= htmlspecialchars($o['payment_method']) ?></td>
                        <td style="color:#6e6e73;white-space:nowrap;"><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                        <td onclick="event.stopPropagation()">
                            <!--
                              Inline status dropdown — changing it calls updateStatus() via JS.
                              event.stopPropagation() stops the row click from opening the detail panel.
                            -->
                            <select class="status-select <?= $o['status'] ?>"
                                    onchange="updateStatus(<?= $o['id'] ?>, this)"
                                    id="sel-<?= $o['id'] ?>">
                                <option value="pending"    <?= $o['status']==='pending'    ?'selected':'' ?>>Pending</option>
                                <option value="processing" <?= $o['status']==='processing' ?'selected':'' ?>>Processing</option>
                                <option value="shipped"    <?= $o['status']==='shipped'    ?'selected':'' ?>>Shipped</option>
                                <option value="delivered"  <?= $o['status']==='delivered'  ?'selected':'' ?>>Delivered</option>
                                <option value="cancelled"  <?= $o['status']==='cancelled'  ?'selected':'' ?>>Cancelled</option>
                            </select>
                        </td>
                        <td onclick="event.stopPropagation()">
                            <a href="orders.php?id=<?= $o['id'] ?>" style="color:#0071e3;font-size:13px;font-weight:600;">View →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main><!-- end admin-main -->
</div><!-- end admin-wrap -->

<!-- ===== ORDER DETAIL SLIDE-IN PANEL ===== -->
<!--
  This panel slides in from the right when admin clicks a row.
  It loads the order details via AJAX so the page doesn't reload.
-->
<div class="detail-overlay" id="detailOverlay" onclick="closeDetail(event)">
    <div class="detail-panel" id="detailPanel">
        <div class="detail-header">
            <h2 id="detailTitle">Order Details</h2>
            <button class="detail-close" onclick="closeDetail()">✕</button>
        </div>
        <div class="detail-body" id="detailBody">
            <!-- Filled by JavaScript below -->
            <p style="color:#6e6e73;text-align:center;padding:40px;">Loading…</p>
        </div>
    </div>
</div>

<?php
// If ?id= is set, pre-load detail data into JS variable so the panel opens on page load
$preloadDetail = null;
if ($viewId && $orderDetail) {
    $preloadDetail = json_encode($orderDetail);
}
?>

<script src="https://unpkg.com/feather-icons"></script>
<script>
feather.replace();

// ── Update order status inline ───────────────────────────
function updateStatus(orderId, selectEl) {
    const newStatus = selectEl.value;

    // Update visual styling of the dropdown immediately
    selectEl.className = 'status-select ' + newStatus;

    fetch('orders.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax=1&order_id=' + orderId + '&status=' + newStatus
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            alert('Failed to update status.');
        }
    });
}

// ── Open detail panel ────────────────────────────────────
function openDetail(orderId) {
    document.getElementById('detailOverlay').classList.add('open');

    // Fetch order detail from PHP
    fetch('orders.php?id=' + orderId)
        .then(r => r.text())
        .then(html => {
            // Extract the detail panel data from the PHP page
            // We call the standalone fetch endpoint instead
        });

    // Actually load via the checkout.php detail endpoint
    fetch('../php/checkout.php?action=detail&id=' + orderId)
        .then(r => r.json())
        .then(res => {
            if (res.success) renderDetail(res.order);
        });
}

function closeDetail(e) {
    // Close only if clicking the overlay background, not the panel itself
    if (e && e.target !== document.getElementById('detailOverlay')) return;
    document.getElementById('detailOverlay').classList.remove('open');
}

// Close with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('detailOverlay').classList.remove('open');
});

// ── Render order detail HTML ─────────────────────────────
function renderDetail(order) {
    document.getElementById('detailTitle').textContent = order.order_number;

    const statusColors = {
        pending:    '#856404', processing: '#004085',
        shipped:    '#155724', delivered: '#0c5460', cancelled: '#721c24'
    };
    const statusBg = {
        pending:    '#fff3cd', processing: '#cce5ff',
        shipped:    '#d4edda', delivered: '#d1ecf1',  cancelled: '#f8d7da'
    };

    const date = new Date(order.created_at).toLocaleDateString('en-US', {
        year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit'
    });

    // Build items HTML
    let itemsHtml = '';
    (order.items || []).forEach(function(item) {
        const img = item.image_main
            ? `<img src="../${item.image_main}" alt="${item.product_name}" onerror="this.style.display='none'">`
            : `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="#aaa" stroke-width="1.5" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/></svg>`;
        itemsHtml += `
            <div class="order-item-row">
                <div class="item-thumb">${img}</div>
                <div style="flex:1;">
                    <div style="font-weight:600;font-size:14px;">${item.product_name}</div>
                    <div style="font-size:12px;color:#6e6e73;">${item.variant_info || 'Standard'} × ${item.quantity}</div>
                </div>
                <div style="font-weight:700;">$${parseFloat(item.total_price).toFixed(2)}</div>
            </div>`;
    });

    document.getElementById('detailBody').innerHTML = `
        <!-- Status badge -->
        <div style="display:inline-block;padding:6px 16px;border-radius:20px;font-weight:700;font-size:13px;
             background:${statusBg[order.status]};color:${statusColors[order.status]};margin-bottom:20px;">
            ${order.status.charAt(0).toUpperCase() + order.status.slice(1)}
        </div>

        <!-- Customer info -->
        <div class="detail-section">
            <h3>Customer</h3>
            <div class="detail-row"><span>Name</span><span>${order.full_name}</span></div>
            <div class="detail-row"><span>Email</span><span>${order.email}</span></div>
            ${order.phone ? `<div class="detail-row"><span>Phone</span><span>${order.phone}</span></div>` : ''}
        </div>

        <!-- Order info -->
        <div class="detail-section">
            <h3>Order Info</h3>
            <div class="detail-row"><span>Order #</span><span style="font-family:monospace;font-weight:700;">${order.order_number}</span></div>
            <div class="detail-row"><span>Date</span><span>${date}</span></div>
            <div class="detail-row"><span>Payment</span><span>${order.payment_method}</span></div>
            <div class="detail-row"><span>Shipping To</span><span>${order.shipping_address}</span></div>
            ${order.notes ? `<div class="detail-row"><span>Notes</span><span>${order.notes}</span></div>` : ''}
        </div>

        <!-- Items -->
        <div class="detail-section">
            <h3>Items</h3>
            ${itemsHtml}
        </div>

        <!-- Totals -->
        <div class="detail-section">
            <h3>Totals</h3>
            <div class="detail-row"><span>Subtotal</span><span>$${parseFloat(order.subtotal).toFixed(2)}</span></div>
            <div class="detail-row"><span>Shipping</span><span>${parseFloat(order.shipping)===0 ? '<span style="color:#30d158;font-weight:700;">FREE</span>' : '$'+parseFloat(order.shipping).toFixed(2)}</span></div>
            <div class="detail-row"><span>Tax (5%)</span><span>$${parseFloat(order.tax).toFixed(2)}</span></div>
            <div class="total-row"><span>Total</span><span>$${parseFloat(order.total).toFixed(2)}</span></div>
        </div>

        <!-- Status update -->
        <div class="detail-section">
            <h3>Update Status</h3>
            <div class="status-update-form">
                <select id="detailStatusSel">
                    <option value="pending"    ${order.status==='pending'    ?'selected':''}>Pending</option>
                    <option value="processing" ${order.status==='processing' ?'selected':''}>Processing</option>
                    <option value="shipped"    ${order.status==='shipped'    ?'selected':''}>Shipped</option>
                    <option value="delivered"  ${order.status==='delivered'  ?'selected':''}>Delivered</option>
                    <option value="cancelled"  ${order.status==='cancelled'  ?'selected':''}>Cancelled</option>
                </select>
                <button onclick="updateStatusFromDetail(${order.id})">Save</button>
                <span class="save-badge" id="savedBadge">✓ Saved</span>
            </div>
        </div>
    `;
}

function updateStatusFromDetail(orderId) {
    const newStatus = document.getElementById('detailStatusSel').value;

    fetch('orders.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax=1&order_id=' + orderId + '&status=' + newStatus
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            // Show saved confirmation
            const badge = document.getElementById('savedBadge');
            badge.style.display = 'inline';
            setTimeout(() => badge.style.display = 'none', 2000);

            // Also update the dropdown in the table row
            const sel = document.getElementById('sel-' + orderId);
            if (sel) {
                sel.value = newStatus;
                sel.className = 'status-select ' + newStatus;
            }
        }
    });
}

// ── Auto-open detail if ?id= is in URL ───────────────────
<?php if ($viewId && $orderDetail): ?>
window.addEventListener('DOMContentLoaded', function() {
    openDetail(<?= $viewId ?>);
});
<?php endif; ?>
</script>
</body>
</html>