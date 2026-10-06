<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/document_tracking.php';

$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!$isAdmin && $userId <= 0) {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/db.php';

if (!ensureDocumentQrCodeColumn($conn) || !ensureDocumentTrackingEventsTable($conn)) {
    error_log('Could not initialize Received Documents data: ' . $conn->error);
    http_response_code(500);
    exit('Received Documents is temporarily unavailable. Please contact the administrator.');
}

$userOffice = trim((string) ($_SESSION['user_department'] ?? ''));
if (!$isAdmin) {
    $userStmt = $conn->prepare('SELECT department FROM users WHERE id = ? LIMIT 1');
    if (!$userStmt) {
        error_log('Could not prepare received-document office lookup: ' . $conn->error);
        http_response_code(500);
        exit('Received Documents is temporarily unavailable. Please try again later.');
    }
    $userStmt->bind_param('i', $userId);
    if (!$userStmt->execute()) {
        error_log('Could not load received-document office: ' . $userStmt->error);
        http_response_code(500);
        exit('Received Documents is temporarily unavailable. Please try again later.');
    }
    $userOffice = trim((string) ($userStmt->get_result()->fetch_assoc()['department'] ?? $userOffice));
    $userStmt->close();
}

$sql = $isAdmin
    ? "
        SELECT
            d.id,
            d.qr_code,
            d.title,
            d.document_type,
            d.department AS destination_office,
            d.priority,
            d.created_at,
            event.office AS received_office,
            event.received_by_name,
            event.created_at AS received_at,
            CONCAT_WS(' ', creator.first_name, creator.last_name) AS creator_name,
            'Received' AS receipt_status
        FROM document_tracking_events AS event
        INNER JOIN documents AS d ON d.id = event.document_id
        LEFT JOIN users AS creator ON creator.id = d.user_id
        WHERE event.event_type = 'received'
        ORDER BY event.created_at DESC, event.id DESC
    "
    : "
        SELECT
            d.id,
            d.qr_code,
            d.title,
            d.document_type,
            d.department AS destination_office,
            d.priority,
            d.created_at,
            COALESCE(receipt.office, d.department) AS received_office,
            COALESCE(receipt.received_by_name, 'Awaiting receipt') AS received_by_name,
            receipt.created_at AS received_at,
            CONCAT_WS(' ', creator.first_name, creator.last_name) AS creator_name,
            IF(receipt.id IS NULL, 'Awaiting receipt', 'Received') AS receipt_status
        FROM documents AS d
        LEFT JOIN users AS creator ON creator.id = d.user_id
        LEFT JOIN document_tracking_events AS receipt
            ON receipt.id = (
                SELECT matching_receipt.id
                FROM document_tracking_events AS matching_receipt
                WHERE matching_receipt.document_id = d.id
                  AND matching_receipt.event_type = 'received'
                  AND (
                      matching_receipt.office = d.department
                      OR matching_receipt.received_by_user_id = ?
                  )
                ORDER BY matching_receipt.id DESC
                LIMIT 1
            )
        WHERE d.department = ?
           OR EXISTS (
                SELECT 1
                FROM document_tracking_events AS user_receipt
                WHERE user_receipt.document_id = d.id
                  AND user_receipt.event_type = 'received'
                  AND user_receipt.received_by_user_id = ?
           )
        ORDER BY COALESCE(receipt.created_at, d.created_at) DESC, d.id DESC
    ";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Could not prepare Received Documents query: ' . $conn->error);
    http_response_code(500);
    exit('Received Documents is temporarily unavailable. Please try again later.');
}

if (!$isAdmin) {
    $stmt->bind_param('isi', $userId, $userOffice, $userId);
}

if (!$stmt->execute()) {
    error_log('Could not load Received Documents: ' . $stmt->error);
    http_response_code(500);
    exit('Received Documents could not be loaded. Please try again later.');
}

$result = $stmt->get_result();
$receivedDocuments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function receivedDocumentsEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Received Documents | DocTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background: #f4f8f5;
            color: #17251c;
            font-family: 'Inter', sans-serif;
        }

        .dashboard-sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            z-index: 1000;
            width: 68px;
            min-height: 100vh;
            padding: 24px 8px;
            overflow-x: hidden;
            background: #0b3d2e;
            color: #fff;
            transition: width 0.25s ease, padding 0.25s ease, transform 0.3s ease;
        }

        .dashboard-sidebar:hover,
        .dashboard-sidebar:focus-within,
        .dashboard-sidebar.is-open {
            width: 260px;
            padding: 24px 16px;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 8px 0 28px;
            margin-bottom: 22px;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            white-space: nowrap;
        }

        .sidebar-brand > div:not(.mini-logo) {
            display: none;
        }

        .dashboard-sidebar:hover .sidebar-brand,
        .dashboard-sidebar:focus-within .sidebar-brand,
        .dashboard-sidebar.is-open .sidebar-brand {
            justify-content: flex-start;
            padding: 8px 10px 28px;
        }

        .dashboard-sidebar:hover .sidebar-brand > div:not(.mini-logo),
        .dashboard-sidebar:focus-within .sidebar-brand > div:not(.mini-logo),
        .dashboard-sidebar.is-open .sidebar-brand > div:not(.mini-logo) {
            display: block;
        }

        .mini-logo {
            display: flex;
            flex: 0 0 46px;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #2e9d63;
            font-size: 15px;
            font-weight: 800;
        }

        .sidebar-brand strong,
        .sidebar-brand small {
            display: block;
        }

        .sidebar-brand strong {
            font-size: 14px;
        }

        .sidebar-brand small {
            margin-top: 3px;
            color: #a9c8b7;
            font-size: 11px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            width: 100%;
            padding: 13px 0;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #c7ddd2;
            font-size: 0;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .nav-item i {
            flex: 0 0 20px;
            width: 20px;
            font-size: 15px;
            text-align: center;
        }

        .dashboard-sidebar:hover .nav-item,
        .dashboard-sidebar:focus-within .nav-item,
        .dashboard-sidebar.is-open .nav-item {
            justify-content: flex-start;
            gap: 13px;
            padding: 13px 14px;
            font-size: 13px;
        }

        .nav-item:hover,
        .nav-item.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }

        .nav-item.active {
            background: #2e9d63;
        }

        .nav-subitem {
            display: none;
            min-height: 38px;
            padding: 9px 12px 9px 47px;
            border-radius: 9px;
            color: #b9d3c5;
            font-size: 12px;
            text-decoration: none;
            white-space: nowrap;
        }

        .documents-nav-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            width: 100%;
        }

        .documents-nav-group:hover .nav-subitem,
        .documents-nav-group:focus-within .nav-subitem {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-subitem i {
            width: 14px;
            font-size: 12px;
            text-align: center;
        }

        .nav-subitem:hover,
        .nav-subitem.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        .nav-logout {
            margin-top: 18px;
            color: #ffb8b8;
        }

        .sidebar-overlay {
            display: none;
        }

        .dashboard-page {
            min-height: 100vh;
            margin-left: 68px;
            padding: 32px;
        }

        .page-container {
            width: min(1280px, 100%);
            margin: 0 auto;
        }

        .mobile-menu {
            display: none;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-header h1 {
            color: #123d2c;
            font-size: clamp(1.6rem, 3vw, 2rem);
            font-weight: 800;
        }

        .page-header p {
            margin-top: 6px;
            color: #718078;
            font-size: 14px;
        }

        .action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 14px;
            border: 1px solid #dce7df;
            border-radius: 10px;
            background: #fff;
            color: #17613b;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .documents-card {
            overflow: hidden;
            border: 1px solid #e1e9e4;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 12px 35px rgba(18, 44, 31, 0.06);
        }

        .documents-card-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 22px 24px;
            border-bottom: 1px solid #edf1ee;
        }

        .documents-card-heading h2 {
            color: #123d2c;
            font-size: 1.05rem;
        }

        .documents-card-heading p {
            margin-top: 5px;
            color: #718078;
            font-size: 0.83rem;
        }

        .document-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            border-radius: 999px;
            background: #e8f5ec;
            color: #17613b;
            font-size: 0.82rem;
            font-weight: 800;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            white-space: nowrap;
        }

        .documents-table th {
            padding: 12px 14px;
            background: #f5f9f6;
            color: #617168;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .documents-table td {
            padding: 13px 14px;
            border-bottom: 1px solid #edf1ee;
            color: #34473b;
            font-size: 0.82rem;
            vertical-align: middle;
        }

        .documents-table tr:last-child td {
            border-bottom: 0;
        }

        .qr-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            border: 1px solid #e5ece7;
            border-radius: 8px;
            background: #fff;
        }

        .qr-link canvas,
        .qr-link img {
            display: block;
            width: 48px;
            height: 48px;
        }

        .document-title {
            color: #233e30;
            font-weight: 700;
        }

        .document-type {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            background: #f1f6f2;
            color: #506459;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .office-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #17613b;
            font-weight: 700;
        }

        .row-actions a {
            color: #0b7546;
            font-size: 0.78rem;
            font-weight: 700;
            text-decoration: none;
        }

        .row-actions a:hover {
            text-decoration: underline;
        }

        .empty-state {
            padding: 58px 24px;
            text-align: center;
        }

        .empty-state-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            margin: 0 auto 15px;
            border-radius: 18px;
            background: #e8f5ec;
            color: #0b7546;
            font-size: 1.2rem;
        }

        .empty-state h3 {
            color: #123d2c;
            font-size: 1.05rem;
        }

        .empty-state p {
            max-width: 460px;
            margin: 7px auto 0;
            color: #718078;
            font-size: 0.86rem;
            line-height: 1.55;
        }

        @media (max-width: 768px) {
            .dashboard-sidebar {
                width: 260px;
                padding: 24px 16px;
                transform: translateX(-100%);
            }

            .dashboard-sidebar.is-open {
                transform: translateX(0);
            }

            .dashboard-sidebar .sidebar-brand {
                justify-content: flex-start;
                padding: 8px 10px 28px;
            }

            .dashboard-sidebar .sidebar-brand > div:not(.mini-logo) {
                display: block;
            }

            .dashboard-sidebar .nav-item {
                justify-content: flex-start;
                gap: 13px;
                padding: 13px 14px;
                font-size: 13px;
            }

            .dashboard-sidebar.is-open .documents-nav-group .nav-subitem {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .sidebar-overlay.is-open {
                position: fixed;
                inset: 0;
                z-index: 999;
                display: block;
                background: rgba(0,0,0,0.35);
            }

            .dashboard-page {
                margin-left: 0;
                padding: 20px;
            }

            .mobile-menu {
                position: fixed;
                top: 14px;
                left: 14px;
                z-index: 1100;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 42px;
                height: 42px;
                border: 0;
                border-radius: 10px;
                background: #0b3d2e;
                color: #fff;
                cursor: pointer;
            }

            .page-header {
                padding-left: 50px;
            }
        }

        @media (max-width: 600px) {
            .dashboard-page {
                padding: 16px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 14px;
            }

            .page-header > div:first-of-type {
                padding-left: 48px;
            }

            .documents-card-heading {
                padding: 18px 16px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="dashboard-sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <div class="mini-logo">CSJ</div>
            <div>
                <strong>Colegio de San Jose</strong>
                <small>Document Tracking System</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i>Admin Dashboard</a>
                <a href="manage_users.php" class="nav-item"><i class="fa-solid fa-users"></i>Manage Users</a>
            <?php else: ?>
                <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i>Dashboard</a>
                <a href="create_document.php" class="nav-item"><i class="fa-solid fa-file-circle-plus"></i>Create Document</a>
            <?php endif; ?>
            <a href="track_document.php" class="nav-item"><i class="fa-solid fa-location-dot"></i>Track Document</a>
            <div class="documents-nav-group">
                <a href="my_documents.php" class="nav-item"><i class="fa-solid fa-folder-open"></i>My Documents</a>
                <a href="received_documents.php" class="nav-subitem active" aria-current="page"><i class="fa-solid fa-inbox"></i>Received Documents</a>
            </div>
            <a href="#" class="nav-item"><i class="fa-solid fa-inbox"></i>Incoming Requests</a>
            <a href="#" class="nav-item"><i class="fa-solid fa-chart-column"></i>Reports</a>
            <a href="#" class="nav-item"><i class="fa-solid fa-gear"></i>Settings</a>
            <a href="logout.php" class="nav-item nav-logout"><i class="fa-solid fa-right-from-bracket"></i>Log Out</a>
        </nav>
    </aside>

    <main class="dashboard-page">
        <div class="page-container">
            <button class="mobile-menu" id="mobileMenu" type="button" aria-label="Open navigation" aria-expanded="false">
                <i class="fa-solid fa-bars"></i>
            </button>
            <header class="page-header">
                <div>
                    <h1>Received Documents</h1>
                    <p><?php echo $isAdmin ? 'Browse all documents received by offices.' : 'Incoming documents routed to ' . receivedDocumentsEscape($userOffice ?: 'your office') . ', plus documents received by your account.'; ?></p>
                </div>
                <a class="action-link" href="my_documents.php"><i class="fa-solid fa-folder-open"></i> My Documents</a>
            </header>

            <section class="documents-card" aria-labelledby="receivedDocumentsTitle">
                <div class="documents-card-heading">
                    <div>
                        <h2 id="receivedDocumentsTitle"><?php echo $isAdmin ? 'All Received Documents' : 'Incoming &amp; Received Documents'; ?></h2>
                        <p><?php echo $isAdmin ? 'Each entry records the QR code, receiving office, receiver, and receipt time.' : 'Documents addressed to your office appear here as soon as they are created. A receipt is recorded when your office receives them.'; ?></p>
                    </div>
                    <span class="document-count" aria-label="<?php echo count($receivedDocuments); ?> documents"><?php echo count($receivedDocuments); ?></span>
                </div>

                <?php if ($receivedDocuments === []): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fa-solid fa-inbox"></i></div>
                        <h3><?php echo $isAdmin ? 'No receipt records yet' : 'No documents routed to your office yet'; ?></h3>
                        <p><?php echo $isAdmin ? 'Receipt records will appear here after documents are received by an office.' : 'When a document is created for ' . receivedDocumentsEscape($userOffice ?: 'your office') . ', it will appear here with its QR code and receipt status.'; ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>QR Code</th>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Destination Office</th>
                                    <th>Received At</th>
                                    <th>Status</th>
                                    <th>Received By</th>
                                    <th>Created</th>
                                    <th>Receipt Date &amp; Time</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($receivedDocuments as $document): ?>
                                    <tr>
                                        <td>
                                            <a
                                                class="qr-link"
                                                href="track_document.php?qr_code=<?php echo rawurlencode($document['qr_code']); ?>"
                                                aria-label="View tracking for <?php echo receivedDocumentsEscape($document['title']); ?>"
                                                title="View document tracking"
                                            >
                                                <span class="document-qr" data-qr-code="<?php echo receivedDocumentsEscape($document['qr_code']); ?>"></span>
                                            </a>
                                        </td>
                                        <td><span class="document-title"><?php echo receivedDocumentsEscape($document['title']); ?></span></td>
                                        <td><span class="document-type"><?php echo receivedDocumentsEscape($document['document_type']); ?></span></td>
                                        <td><?php echo receivedDocumentsEscape($document['destination_office']); ?></td>
                                        <td><span class="office-status"><i class="fa-solid fa-location-dot"></i><?php echo receivedDocumentsEscape($document['received_office']); ?></span></td>
                                        <td><span class="document-type"><?php echo receivedDocumentsEscape($document['receipt_status']); ?></span></td>
                                        <td><?php echo receivedDocumentsEscape($document['received_by_name']); ?></td>
                                        <td><?php echo receivedDocumentsEscape(date('M j, Y g:i A', strtotime($document['created_at']))); ?></td>
                                        <td><?php echo $document['received_at'] !== null ? receivedDocumentsEscape(date('M j, Y g:i A', strtotime($document['received_at']))) : 'Awaiting receipt'; ?></td>
                                        <td><?php echo receivedDocumentsEscape(trim((string) $document['creator_name']) ?: 'User unavailable'); ?></td>
                                        <td><div class="row-actions"><a href="track_document.php?qr_code=<?php echo rawurlencode($document['qr_code']); ?>">View history</a></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        const sidebar = document.getElementById('sidebar');
        const mobileMenu = document.getElementById('mobileMenu');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function setMobileSidebarOpen(isOpen) {
            sidebar.classList.toggle('is-open', isOpen);
            sidebarOverlay.classList.toggle('is-open', isOpen);
            mobileMenu.setAttribute('aria-expanded', String(isOpen));
        }

        mobileMenu.addEventListener('click', function () {
            setMobileSidebarOpen(!sidebar.classList.contains('is-open'));
        });

        sidebarOverlay.addEventListener('click', function () {
            setMobileSidebarOpen(false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setMobileSidebarOpen(false);
            }
        });
    </script>
    <?php if ($receivedDocuments !== []): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            if (typeof QRCode === 'function') {
                document.querySelectorAll('.document-qr').forEach(function (container) {
                    new QRCode(container, {
                        text: container.dataset.qrCode,
                        width: 48,
                        height: 48,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                });
            }
        </script>
    <?php endif; ?>
</body>
</html>
