<?php
require_once __DIR__ . '/session_config.php';

require_once __DIR__ . '/document_tracking.php';

$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!$isAdmin && $userId <= 0) {
    header('Location: index.php');
    exit;
}

require 'db.php';

$documentsTableCreated = $conn->query("
    CREATE TABLE IF NOT EXISTS documents (
        id int(11) NOT NULL AUTO_INCREMENT,
        qr_code varchar(64) NOT NULL,
        user_id int(11) NOT NULL,
        title varchar(180) NOT NULL,
        document_type varchar(100) NOT NULL,
        department varchar(150) NOT NULL,
        priority varchar(30) NOT NULL DEFAULT 'Normal',
        description text DEFAULT NULL,
        file_path varchar(255) DEFAULT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY qr_code (qr_code),
        KEY user_id (user_id)
    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_general_ci
");

if (
    !$documentsTableCreated ||
    !ensureDocumentQrCodeColumn($conn) ||
    !ensureDocumentTrackingEventsTable($conn)
) {
    error_log('Could not initialize My Documents data: ' . $conn->error);
    http_response_code(500);
    exit('My Documents is temporarily unavailable. Please contact the administrator.');
}

$documents = [];
$message = '';
$messageType = '';
$sql = "
    SELECT
        d.id,
        d.qr_code,
        d.title,
        d.document_type,
        d.department,
        d.priority,
        d.description,
        d.created_at,
        COALESCE(
            (
                SELECT event.office
                FROM document_tracking_events AS event
                WHERE event.document_id = d.id
                ORDER BY event.id DESC
                LIMIT 1
            ),
            u.department,
            'Office not recorded'
        ) AS current_office
    FROM documents AS d
    LEFT JOIN users AS u ON u.id = d.user_id
";

if (!$isAdmin) {
    $sql .= ' WHERE d.user_id = ?';
}

$sql .= ' ORDER BY d.created_at DESC, d.id DESC';
$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Could not prepare My Documents query: ' . $conn->error);
    http_response_code(500);
    exit('My Documents is temporarily unavailable. Please contact the administrator.');
}

if (!$isAdmin) {
    $stmt->bind_param('i', $userId);
}

if (!$stmt->execute()) {
    error_log('Could not load My Documents: ' . $stmt->error);
    http_response_code(500);
    exit('My Documents could not be loaded. Please try again later.');
}

$result = $stmt->get_result();
while ($document = $result->fetch_assoc()) {
    $documents[] = $document;
}
$stmt->close();

if (($_GET['created'] ?? '') === '1') {
    $message = 'Your document was created and saved in this list.';
    $messageType = 'success';
}

function myDocumentsEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Documents | DocTrack</title>
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

        button {
            font: inherit;
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
            text-align: left;
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

        .nav-item:hover,
        .nav-item.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }

        .nav-item.active {
            background: #2e9d63;
        }

        .nav-logout {
            margin-top: 18px;
            color: #ffb8b8;
        }

        .dashboard-page {
            min-height: 100vh;
            margin-left: 68px;
            padding: 32px;
        }

        .page-container {
            width: min(1200px, 100%);
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

        .header-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
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

        .action-link.primary {
            border-color: #0b7546;
            background: #0b7546;
            color: #fff;
        }

        .message {
            margin-bottom: 18px;
            padding: 13px 15px;
            border: 1px solid #c8e6d1;
            border-radius: 10px;
            background: #eff9f1;
            color: #17613b;
            font-size: 0.9rem;
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
            padding: 12px 16px;
            background: #f5f9f6;
            color: #617168;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .documents-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #edf1ee;
            color: #34473b;
            font-size: 0.84rem;
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
            cursor: pointer;
        }

        .qr-link canvas,
        .qr-link img {
            display: block;
            width: 48px;
            height: 48px;
        }

        .qr-link:hover,
        .qr-link:focus-visible {
            border-color: #0b7546;
            box-shadow: 0 0 0 3px rgba(11,117,70,0.12);
            outline: none;
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

        .office-status i {
            font-size: 0.72rem;
        }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 12px;
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
            max-width: 420px;
            margin: 7px auto 18px;
            color: #718078;
            font-size: 0.86rem;
            line-height: 1.55;
        }

        .document-modal[hidden] {
            display: none;
        }

        .document-modal {
            position: fixed;
            inset: 0;
            z-index: 1500;
            display: grid;
            place-items: center;
            padding: 20px;
            background: rgba(13, 30, 21, 0.58);
        }

        .document-dialog {
            position: relative;
            width: min(680px, 100%);
            max-height: min(90vh, 820px);
            overflow: auto;
            border: 1px solid #e1e9e4;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(0,0,0,0.25);
        }

        .document-dialog-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 22px 24px;
            border-bottom: 1px solid #edf1ee;
        }

        .document-dialog-header h2 {
            color: #123d2c;
            font-size: 1.15rem;
        }

        .document-dialog-header p {
            margin-top: 5px;
            color: #718078;
            font-size: 0.82rem;
        }

        .modal-close {
            display: inline-flex;
            flex: 0 0 36px;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 1px solid #dce7df;
            border-radius: 9px;
            background: #fff;
            color: #43564a;
            cursor: pointer;
        }

        .document-dialog-content {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 170px;
            gap: 22px;
            padding: 22px 24px;
        }

        .document-detail-list {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 12px 14px;
            align-content: start;
            font-size: 0.83rem;
        }

        .document-detail-list dt {
            color: #718078;
            font-weight: 600;
        }

        .document-detail-list dd {
            min-width: 0;
            color: #233e30;
            font-weight: 700;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .modal-qr-panel {
            padding: 12px;
            border: 1px solid #e1e9e4;
            border-radius: 12px;
            text-align: center;
        }

        .modal-qr-panel strong {
            display: block;
            margin-bottom: 10px;
            color: #17613b;
            font-size: 0.78rem;
        }

        .modal-qr-panel canvas,
        .modal-qr-panel img {
            display: block;
            width: 140px;
            height: 140px;
            margin: 0 auto;
        }

        .modal-qr-value {
            margin-top: 8px;
            color: #718078;
            font-size: 0.65rem;
            overflow-wrap: anywhere;
        }

        .document-dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 24px 22px;
            border-top: 1px solid #edf1ee;
        }

        .document-dialog-actions a,
        .document-dialog-actions button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 14px;
            border: 1px solid #dce7df;
            border-radius: 9px;
            background: #fff;
            color: #17613b;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .document-dialog-actions .modal-print {
            border-color: #0b7546;
            background: #0b7546;
            color: #fff;
        }

        .sidebar-overlay {
            display: none;
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

        @media (max-width: 560px) {
            .document-modal {
                padding: 12px;
            }

            .document-dialog-content {
                grid-template-columns: 1fr;
                padding: 18px;
            }

            .document-detail-list {
                grid-template-columns: 110px minmax(0, 1fr);
            }

            .modal-qr-panel {
                width: fit-content;
                margin: 0 auto;
            }

            .document-dialog-actions {
                flex-wrap: wrap;
                padding: 16px 18px;
            }
        }

        @media print {
            body.print-document-mode > *:not(#documentPrintModal) {
                display: none !important;
            }

            body.print-document-mode #documentPrintModal {
                position: static;
                display: block !important;
                padding: 0;
                background: #fff;
            }

            body.print-document-mode .document-dialog {
                width: 100%;
                max-height: none;
                overflow: visible;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            body.print-document-mode .document-dialog-header {
                padding: 0 0 16px;
            }

            body.print-document-mode .document-dialog-content {
                grid-template-columns: minmax(0, 1fr) 180px;
                padding: 20px 0;
            }

            body.print-document-mode .modal-qr-panel canvas,
            body.print-document-mode .modal-qr-panel img {
                width: 150px;
                height: 150px;
            }

            body.print-document-mode .modal-close,
            body.print-document-mode .document-dialog-actions {
                display: none !important;
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
                <a href="my_documents.php" class="nav-item active" aria-current="page"><i class="fa-solid fa-folder-open"></i>My Documents</a>
                <a href="received_documents.php" class="nav-subitem"><i class="fa-solid fa-inbox"></i>Received Documents</a>
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
                    <h1>My Documents</h1>
                    <p><?php echo $isAdmin ? 'Browse documents saved in DocTrack.' : 'Documents you created are saved here with their QR codes and latest office.'; ?></p>
                </div>
                <div class="header-actions">
                    <?php if (!$isAdmin): ?>
                        <a class="action-link primary" href="create_document.php"><i class="fa-solid fa-plus"></i> Create Document</a>
                    <?php endif; ?>
                    <a class="action-link" href="track_document.php"><i class="fa-solid fa-location-dot"></i> Track Document</a>
                </div>
            </header>

            <?php if ($message !== ''): ?>
                <div class="message <?php echo myDocumentsEscape($messageType); ?>" role="status">
                    <?php echo myDocumentsEscape($message); ?>
                </div>
            <?php endif; ?>

            <section class="documents-card" aria-labelledby="documentsTitle">
                <div class="documents-card-heading">
                    <div>
                        <h2 id="documentsTitle"><?php echo $isAdmin ? 'All Documents' : 'Saved Documents'; ?></h2>
                        <p>Scan a QR code or open a document to see its complete office history.</p>
                    </div>
                    <span class="document-count" aria-label="<?php echo count($documents); ?> documents"><?php echo count($documents); ?></span>
                </div>

                <?php if ($documents === []): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fa-solid fa-folder-open"></i></div>
                        <h3>No documents yet</h3>
                        <p>Create a document and it will be saved here automatically with its QR code and tracking history.</p>
                        <?php if (!$isAdmin): ?>
                            <a class="action-link primary" href="create_document.php"><i class="fa-solid fa-plus"></i> Create your first document</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>QR Code</th>
                                    <th>Document</th>
                                    <th>Type</th>
                                    <th>Destination</th>
                                    <th>Current Office</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $document): ?>
                                    <tr>
                                        <td>
                                            <button
                                                class="qr-link"
                                                type="button"
                                                aria-label="View and print <?php echo myDocumentsEscape($document['title']); ?> details"
                                                aria-haspopup="dialog"
                                                title="View and print document"
                                                data-qr-code="<?php echo myDocumentsEscape($document['qr_code']); ?>"
                                                data-title="<?php echo myDocumentsEscape($document['title']); ?>"
                                                data-document-type="<?php echo myDocumentsEscape($document['document_type']); ?>"
                                                data-department="<?php echo myDocumentsEscape($document['department']); ?>"
                                                data-current-office="<?php echo myDocumentsEscape($document['current_office']); ?>"
                                                data-priority="<?php echo myDocumentsEscape($document['priority']); ?>"
                                                data-created-at="<?php echo myDocumentsEscape(date('M j, Y g:i A', strtotime($document['created_at']))); ?>"
                                                data-description="<?php echo myDocumentsEscape($document['description'] ?? ''); ?>"
                                                data-history-url="track_document.php?qr_code=<?php echo rawurlencode($document['qr_code']); ?>"
                                            >
                                                <span class="document-qr" data-qr-code="<?php echo myDocumentsEscape($document['qr_code']); ?>"></span>
                                            </button>
                                        </td>
                                        <td><span class="document-title"><?php echo myDocumentsEscape($document['title']); ?></span></td>
                                        <td><span class="document-type"><?php echo myDocumentsEscape($document['document_type']); ?></span></td>
                                        <td><?php echo myDocumentsEscape($document['department']); ?></td>
                                        <td><span class="office-status"><i class="fa-solid fa-location-dot"></i><?php echo myDocumentsEscape($document['current_office']); ?></span></td>
                                        <td><?php echo myDocumentsEscape(date('M j, Y g:i A', strtotime($document['created_at']))); ?></td>
                                        <td>
                                            <div class="row-actions">
                                                <a href="track_document.php?qr_code=<?php echo rawurlencode($document['qr_code']); ?>">View history</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <div class="document-modal" id="documentPrintModal" hidden>
        <section class="document-dialog" role="dialog" aria-modal="true" aria-labelledby="documentModalTitle" aria-describedby="documentModalSubtitle">
            <header class="document-dialog-header">
                <div>
                    <h2 id="documentModalTitle">Document Details</h2>
                    <p id="documentModalSubtitle">Review the saved document and print its QR code.</p>
                </div>
                <button class="modal-close" id="closeDocumentModal" type="button" aria-label="Close document details">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <div class="document-dialog-content">
                <dl class="document-detail-list">
                    <dt>Document</dt><dd id="modalDocumentTitle"></dd>
                    <dt>Document type</dt><dd id="modalDocumentType"></dd>
                    <dt>Destination</dt><dd id="modalDepartment"></dd>
                    <dt>Current office</dt><dd id="modalCurrentOffice"></dd>
                    <dt>Priority</dt><dd id="modalPriority"></dd>
                    <dt>Created</dt><dd id="modalCreatedAt"></dd>
                    <dt>Description</dt><dd id="modalDescription"></dd>
                </dl>
                <div class="modal-qr-panel">
                    <strong>SCAN TO TRACK</strong>
                    <div id="modalQrCode"></div>
                    <p class="modal-qr-value" id="modalQrValue"></p>
                </div>
            </div>
            <footer class="document-dialog-actions">
                <a id="modalHistoryLink" href="track_document.php"><i class="fa-solid fa-clock-rotate-left"></i> View history</a>
                <button class="modal-print" id="printModalDocument" type="button"><i class="fa-solid fa-print"></i> Print Document</button>
            </footer>
        </section>
    </div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const mobileMenu = document.getElementById('mobileMenu');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const documentModal = document.getElementById('documentPrintModal');
        const closeDocumentModal = document.getElementById('closeDocumentModal');
        let lastQrTrigger = null;

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
            if (event.key === 'Escape' && !documentModal.hidden) {
                closeModal();
                return;
            }
            if (event.key === 'Tab' && !documentModal.hidden) {
                const focusableElements = documentModal.querySelectorAll('a[href], button:not([disabled])');
                const firstFocusable = focusableElements[0];
                const lastFocusable = focusableElements[focusableElements.length - 1];
                if (event.shiftKey && document.activeElement === firstFocusable) {
                    event.preventDefault();
                    lastFocusable.focus();
                } else if (!event.shiftKey && document.activeElement === lastFocusable) {
                    event.preventDefault();
                    firstFocusable.focus();
                }
                return;
            }
            if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setMobileSidebarOpen(false);
            }
        });

        function closeModal() {
            documentModal.hidden = true;
            document.body.classList.remove('print-document-mode');
            document.getElementById('modalQrCode').replaceChildren();
            if (lastQrTrigger) {
                lastQrTrigger.focus();
            }
        }

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('.qr-link');
            if (!trigger) {
                return;
            }

            lastQrTrigger = trigger;
            document.getElementById('documentModalTitle').textContent = 'Document Details: ' + trigger.dataset.title;
            document.getElementById('modalDocumentTitle').textContent = trigger.dataset.title;
            document.getElementById('modalDocumentType').textContent = trigger.dataset.documentType;
            document.getElementById('modalDepartment').textContent = trigger.dataset.department;
            document.getElementById('modalCurrentOffice').textContent = trigger.dataset.currentOffice;
            document.getElementById('modalPriority').textContent = trigger.dataset.priority;
            document.getElementById('modalCreatedAt').textContent = trigger.dataset.createdAt;
            document.getElementById('modalDescription').textContent = trigger.dataset.description || 'None provided';
            document.getElementById('modalQrValue').textContent = trigger.dataset.qrCode;
            document.getElementById('modalHistoryLink').href = trigger.dataset.historyUrl;

            const qrContainer = document.getElementById('modalQrCode');
            qrContainer.replaceChildren();
            if (typeof QRCode === 'function') {
                new QRCode(qrContainer, {
                    text: trigger.dataset.qrCode,
                    width: 140,
                    height: 140,
                    correctLevel: QRCode.CorrectLevel.M
                });
            }

            documentModal.hidden = false;
            closeDocumentModal.focus();
        });

        closeDocumentModal.addEventListener('click', closeModal);
        documentModal.addEventListener('click', function (event) {
            if (event.target === documentModal) {
                closeModal();
            }
        });

        document.getElementById('printModalDocument').addEventListener('click', function () {
            document.body.classList.add('print-document-mode');
            window.print();
        });
        window.addEventListener('afterprint', function () {
            document.body.classList.remove('print-document-mode');
        });
    </script>
    <?php if ($documents !== []): ?>
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
