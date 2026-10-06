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

if (!$documentsTableCreated) {
    error_log('Could not initialize documents table for tracking: ' . $conn->error);
    http_response_code(500);
    exit('Document tracking is temporarily unavailable. Please contact the administrator.');
}

if (!ensureDocumentQrCodeColumn($conn)) {
    http_response_code(500);
    exit('Document QR codes are temporarily unavailable. Please contact the administrator.');
}

if (!ensureDocumentTrackingEventsTable($conn)) {
    error_log('Could not initialize document tracking events: ' . $conn->error);
    http_response_code(500);
    exit('Document tracking is temporarily unavailable. Please contact the administrator.');
}

$userOffice = trim((string) ($_SESSION['user_department'] ?? ''));
$receiverName = trim((string) ($_SESSION['user_name'] ?? ''));

if ($userId > 0) {
    $userStmt = $conn->prepare(
        'SELECT CONCAT_WS(" ", first_name, last_name) AS full_name, department
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    if (!$userStmt) {
        error_log('Could not prepare tracking receiver lookup: ' . $conn->error);
        http_response_code(500);
        exit('Document tracking is temporarily unavailable. Please contact the administrator.');
    }

    $userStmt->bind_param('i', $userId);
    if (!$userStmt->execute()) {
        error_log('Could not load tracking receiver: ' . $userStmt->error);
        http_response_code(500);
        exit('Document tracking is temporarily unavailable. Please contact the administrator.');
    }

    $userResult = $userStmt->get_result();
    $receiver = $userResult->fetch_assoc();
    $userStmt->close();

    if ($receiver) {
        $userOffice = trim((string) $receiver['department']);
        $receiverName = trim((string) $receiver['full_name']);
    }
}

if (empty($_SESSION['tracking_csrf_token'])) {
    $_SESSION['tracking_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['tracking_csrf_token'];
$qrCode = trim((string) (
    $_POST['qr_code'] ??
    $_GET['qr_code'] ??
    $_GET['tracking_number'] ??
    ''
));
$document = null;
$timeline = [];
$userDocuments = [];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'lookup';

    if ($action === 'approve' || $action === 'reject') {
        $postedToken = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedToken)) {
            $message = 'The tracking form expired. Refresh the page and try again.';
            $messageType = 'error';
        } elseif ((!$isAdmin && $userId <= 0) || $userOffice === '' || $userOffice === 'Department not set') {
            $message = 'A user account with a registered department is required to approve or reject a document.';
            $messageType = 'error';
        } elseif ($qrCode === '') {
            $message = 'Scan or enter a QR code before making a decision.';
            $messageType = 'error';
        } else {
            $decisionLookup = $conn->prepare(
                'SELECT id
                 FROM documents
                 WHERE qr_code = ?
                 LIMIT 1'
            );

            if (!$decisionLookup) {
                error_log('Could not prepare document decision lookup: ' . $conn->error);
                $message = 'The document decision could not be recorded. Please try again.';
                $messageType = 'error';
            } else {
                $decisionLookup->bind_param('s', $qrCode);

                if (!$decisionLookup->execute()) {
                    error_log('Could not look up document for decision: ' . $decisionLookup->error);
                    $message = 'The document decision could not be recorded. Please try again.';
                    $messageType = 'error';
                } else {
                    $decisionDocument = $decisionLookup->get_result()->fetch_assoc();

                    if (!$decisionDocument) {
                        $message = 'No document was found for that QR code.';
                        $messageType = 'error';
                    } else {
                        $decision = $action === 'approve' ? 'approved' : 'rejected';
                        $decisionUserId = $userId > 0 ? $userId : null;
                        if (recordDocumentTrackingEvent(
                            $conn,
                            (int) $decisionDocument['id'],
                            $decision,
                            $userOffice,
                            $decisionUserId,
                            $receiverName
                        )) {
                            header('Location: track_document.php?qr_code=' . rawurlencode($qrCode) . '&decision=' . $decision);
                            exit;
                        }

                        $message = 'The document decision could not be recorded. Please try again.';
                        $messageType = 'error';
                    }
                }

                $decisionLookup->close();
            }
        }
    } elseif ($action === 'receive') {
        $postedToken = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedToken)) {
            $message = 'The tracking form expired. Refresh the page and try again.';
            $messageType = 'error';
        } elseif ($isAdmin || $userId <= 0 || $userOffice === '' || $userOffice === 'Department not set') {
            $message = 'A staff account with a registered department is required to record receipt.';
            $messageType = 'error';
        } elseif ($qrCode === '') {
            $message = 'Scan or enter a QR code before recording receipt.';
            $messageType = 'error';
        } else {
            $lookupStmt = $conn->prepare(
                'SELECT d.id, d.department, u.department AS creator_department
                 FROM documents d
                 LEFT JOIN users u ON u.id = d.user_id
                 WHERE d.qr_code = ?
                 LIMIT 1'
            );

            if (!$lookupStmt) {
                error_log('Could not prepare document receipt lookup: ' . $conn->error);
                $message = 'The receipt could not be recorded. Please try again.';
                $messageType = 'error';
            } else {
                $lookupStmt->bind_param('s', $qrCode);

                if (!$lookupStmt->execute()) {
                    error_log('Could not look up document for receipt: ' . $lookupStmt->error);
                    $message = 'The receipt could not be recorded. Please try again.';
                    $messageType = 'error';
                } else {
                    $targetDocument = $lookupStmt->get_result()->fetch_assoc();

                    if (!$targetDocument) {
                        $message = 'No document was found for that QR code.';
                        $messageType = 'error';
                    } else {
                        $latestStmt = $conn->prepare(
                            'SELECT office
                             FROM document_tracking_events
                             WHERE document_id = ?
                             ORDER BY id DESC
                             LIMIT 1'
                        );

                        if (!$latestStmt) {
                            error_log('Could not prepare current document office lookup: ' . $conn->error);
                            $message = 'The receipt could not be recorded. Please try again.';
                            $messageType = 'error';
                        } else {
                            $documentId = (int) $targetDocument['id'];
                            $latestStmt->bind_param('i', $documentId);

                            if (!$latestStmt->execute()) {
                                error_log('Could not load current document office: ' . $latestStmt->error);
                                $message = 'The receipt could not be recorded. Please try again.';
                                $messageType = 'error';
                            } else {
                                $latestEvent = $latestStmt->get_result()->fetch_assoc();
                                $currentOffice = trim((string) ($latestEvent['office'] ?? $targetDocument['creator_department'] ?? ''));

                                if ($currentOffice === $userOffice) {
                                    $message = 'This document is already recorded at ' . $userOffice . '.';
                                    $messageType = 'error';
                                } elseif (recordDocumentTrackingEvent(
                                    $conn,
                                    $documentId,
                                    'received',
                                    $userOffice,
                                    $userId,
                                    $receiverName
                                )) {
                                    header('Location: track_document.php?qr_code=' . rawurlencode($qrCode) . '&receipt=recorded');
                                    exit;
                                } else {
                                    $message = 'The receipt could not be recorded. Please try again.';
                                    $messageType = 'error';
                                }
                            }

                            $latestStmt->close();
                        }
                    }
                }

                $lookupStmt->close();
            }
        }
    } elseif ($qrCode === '') {
        $message = 'Scan or enter a QR code to look up a document.';
        $messageType = 'error';
    } else {
        header('Location: track_document.php?qr_code=' . rawurlencode($qrCode));
        exit;
    }
}

if (($_GET['receipt'] ?? '') === 'recorded') {
    $message = 'Receipt recorded with your registered office and account.';
    $messageType = 'success';
}

if (($_GET['decision'] ?? '') === 'approved') {
    $message = 'Document approved. Your decision and office were saved to its history.';
    $messageType = 'success';
} elseif (($_GET['decision'] ?? '') === 'rejected') {
    $message = 'Document rejected. Your decision and office were saved to its history.';
    $messageType = 'success';
}

if ($qrCode !== '') {
    $stmt = $conn->prepare(
        'SELECT d.id, d.qr_code, d.title, d.document_type, d.department,
                d.priority, d.description, d.created_at, d.user_id,
                CONCAT_WS(" ", u.first_name, u.last_name) AS creator_name,
                u.department AS creator_department
         FROM documents d
         LEFT JOIN users u ON u.id = d.user_id
         WHERE d.qr_code = ?
         LIMIT 1'
    );

    if (!$stmt) {
        error_log('Could not prepare tracked document lookup: ' . $conn->error);
        $message = 'Document tracking is temporarily unavailable. Please try again later.';
        $messageType = 'error';
    } else {
        $stmt->bind_param('s', $qrCode);

        if (!$stmt->execute()) {
            error_log('Could not execute tracked document lookup: ' . $stmt->error);
            $message = 'The document could not be looked up. Please try again.';
            $messageType = 'error';
        } else {
            $document = $stmt->get_result()->fetch_assoc() ?: null;

            if ($document === null) {
                $message = 'No document was found for that QR code. Check it and try again.';
                $messageType = 'error';
            } else {
                $eventsStmt = $conn->prepare(
                    'SELECT event_type, office, received_by_name, created_at
                     FROM document_tracking_events
                     WHERE document_id = ?
                     ORDER BY id ASC'
                );

                if (!$eventsStmt) {
                    error_log('Could not prepare document timeline lookup: ' . $conn->error);
                    $message = 'The document was found, but its tracking history is unavailable.';
                    $messageType = 'error';
                } else {
                    $documentId = (int) $document['id'];
                    $eventsStmt->bind_param('i', $documentId);

                    if (!$eventsStmt->execute()) {
                        error_log('Could not load document timeline: ' . $eventsStmt->error);
                        $message = 'The document was found, but its tracking history is unavailable.';
                        $messageType = 'error';
                    } else {
                        $eventResult = $eventsStmt->get_result();
                        while ($event = $eventResult->fetch_assoc()) {
                            $timeline[] = $event;
                        }
                    }

                    $eventsStmt->close();
                }

                if ($timeline === []) {
                    $timeline[] = [
                        'event_type' => 'created',
                        'office' => trim((string) ($document['creator_department'] ?? '')) ?: 'Office not recorded',
                        'received_by_name' => trim((string) ($document['creator_name'] ?? '')) ?: 'Creator not recorded',
                        'created_at' => $document['created_at'],
                    ];
                }
            }
        }

        $stmt->close();
    }
}

$documentsSql = $isAdmin
    ? 'SELECT d.qr_code, d.title, d.department, d.created_at
       FROM documents d
       ORDER BY d.created_at DESC
       LIMIT 20'
    : 'SELECT d.qr_code, d.title, d.department, d.created_at
       FROM documents d
       WHERE d.user_id = ?
       ORDER BY d.created_at DESC
       LIMIT 20';
$documentsStmt = $conn->prepare($documentsSql);

if (!$documentsStmt) {
    error_log('Could not prepare created documents list: ' . $conn->error);
    if ($message === '') {
        $message = 'Your document list is temporarily unavailable.';
        $messageType = 'error';
    }
} else {
    if (!$isAdmin) {
        $documentsStmt->bind_param('i', $userId);
    }

    if (!$documentsStmt->execute()) {
        error_log('Could not load created documents list: ' . $documentsStmt->error);
        if ($message === '') {
            $message = 'Your document list is temporarily unavailable.';
            $messageType = 'error';
        }
    } else {
        $documentsResult = $documentsStmt->get_result();
        while ($userDocument = $documentsResult->fetch_assoc()) {
            $userDocuments[] = $userDocument;
        }
    }

    $documentsStmt->close();
}

$currentOffice = $timeline !== []
    ? trim((string) $timeline[count($timeline) - 1]['office'])
    : trim((string) ($document['creator_department'] ?? ''));
$latestDecision = null;
foreach ($timeline as $event) {
    if (in_array($event['event_type'], ['approved', 'rejected'], true)) {
        $latestDecision = $event;
    }
}
$canRecordReceipt = $document !== null
    && !$isAdmin
    && $userId > 0
    && $userOffice !== ''
    && $userOffice !== 'Department not set'
    && $currentOffice !== $userOffice;

function trackDocumentEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Document | DocTrack</title>
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

        button,
        input {
            font: inherit;
        }

        .dashboard-shell {
            min-height: 100vh;
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

        .dashboard-page {
            min-height: 100vh;
            margin-left: 68px;
            padding: 32px;
            transition: margin-left 0.25s ease;
        }

        .page-container {
            width: min(1100px, 100%);
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
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

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 15px;
            border: 1px solid #e1e9e4;
            border-radius: 11px;
            background: #fff;
            color: #0b6840;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .tracking-card,
        .result-card {
            border: 1px solid #e1e9e4;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 12px 35px rgba(18, 44, 31, 0.06);
        }

        .tracking-card {
            max-width: 760px;
            padding: 28px;
        }

        .section-heading {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 22px;
        }

        .section-icon {
            display: flex;
            flex: 0 0 46px;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: #e8f5ec;
            color: #0b7546;
        }

        .section-heading h2 {
            color: #123d2c;
            font-size: 1.12rem;
        }

        .section-heading p {
            margin-top: 5px;
            color: #718078;
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .tracking-form label {
            display: block;
            margin-bottom: 8px;
            color: #284b38;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .tracking-form input {
            width: 100%;
            min-height: 50px;
            padding: 0 14px;
            border: 1px solid #dbe5dd;
            border-radius: 11px;
            outline: none;
            color: #17251c;
            background: #fbfdfb;
        }

        .tracking-form input:focus {
            border-color: #13824e;
            box-shadow: 0 0 0 3px rgba(19,130,78,0.12);
        }

        .track-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 48px;
            margin-top: 15px;
            padding: 0 20px;
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, #0b7546, #168a53);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .message {
            margin-top: 18px;
            padding: 13px 15px;
            border-radius: 10px;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .message.error {
            border: 1px solid #f0caca;
            background: #fff3f3;
            color: #a83030;
        }

        .result-card {
            max-width: 760px;
            margin-top: 24px;
            padding: 26px 28px;
        }

        .message.success {
            border: 1px solid #c8e6d1;
            background: #eff9f1;
            color: #17613b;
        }

        .result-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 220px;
            gap: 24px;
            align-items: start;
        }

        .result-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }

        .result-heading h2 {
            color: #123d2c;
            font-size: 1.2rem;
            overflow-wrap: anywhere;
        }

        .status-badges {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            background: #e8f5ec;
            color: #167447;
            font-size: 0.75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .status-badge.decision-approved {
            background: #e8f5ec;
            color: #167447;
        }

        .status-badge.decision-rejected {
            background: #fff0f0;
            color: #a83030;
        }

        .result-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 24px;
        }

        .result-item span {
            display: block;
            margin-bottom: 5px;
            color: #718078;
            font-size: 0.73rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .result-item strong {
            color: #263c2f;
            font-size: 0.92rem;
            overflow-wrap: anywhere;
        }

        .result-note {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #edf1ee;
            color: #718078;
            font-size: 0.84rem;
            line-height: 1.55;
        }

        .qr-panel {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px;
            border: 1px solid #e5ece7;
            border-radius: 14px;
            background: #fbfdfb;
            text-align: center;
        }

        .qr-panel h3 {
            color: #234333;
            font-size: 0.9rem;
        }

        .qr-panel p {
            margin-top: 5px;
            color: #718078;
            font-size: 0.74rem;
            line-height: 1.45;
        }

        .qr-code {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 180px;
            height: 180px;
            margin: 14px 0;
            padding: 8px;
            border: 1px solid #e5ece7;
            border-radius: 10px;
            background: #fff;
        }

        .qr-code canvas,
        .qr-code img {
            display: block;
            max-width: 100%;
            height: auto;
        }

        .qr-actions {
            display: flex;
            width: 100%;
            gap: 8px;
        }

        .qr-action {
            flex: 1;
            min-height: 36px;
            padding: 0 9px;
            border: 1px solid #dce7df;
            border-radius: 8px;
            background: #fff;
            color: #17613b;
            font-size: 0.75rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .qr-action:disabled {
            color: #718078;
            cursor: wait;
        }

        .timeline-card,
        .documents-card {
            max-width: 980px;
            margin-top: 24px;
            padding: 25px 28px;
            border: 1px solid #e1e9e4;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 12px 35px rgba(18, 44, 31, 0.06);
        }

        .card-title {
            margin-bottom: 5px;
            color: #123d2c;
            font-size: 1.1rem;
        }

        .card-subtitle {
            margin-bottom: 20px;
            color: #718078;
            font-size: 0.84rem;
        }

        .timeline {
            display: grid;
            gap: 0;
        }

        .timeline-event {
            position: relative;
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: start;
            padding: 0 0 20px;
        }

        .timeline-event:not(:last-child)::before {
            position: absolute;
            top: 30px;
            bottom: 0;
            left: 16px;
            width: 2px;
            background: #e2eee5;
            content: "";
        }

        .timeline-icon {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #e8f5ec;
            color: #167447;
            font-size: 0.8rem;
        }

        .timeline-details strong {
            display: block;
            color: #263c2f;
            font-size: 0.88rem;
        }

        .timeline-details p {
            margin-top: 4px;
            color: #66766d;
            font-size: 0.82rem;
            line-height: 1.5;
        }

        .timeline-details .event-office {
            color: #17613b;
            font-weight: 700;
        }

        .timeline-time {
            color: #718078;
            font-size: 0.76rem;
            line-height: 1.5;
            text-align: right;
            white-space: nowrap;
        }

        .receipt-form {
            margin-top: 8px;
            padding-top: 18px;
            border-top: 1px solid #edf1ee;
        }

        .receipt-form p {
            margin-bottom: 12px;
            color: #66766d;
            font-size: 0.84rem;
            line-height: 1.5;
        }

        .decision-form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid #edf1ee;
        }

        .decision-form p {
            flex: 1 1 100%;
            color: #66766d;
            font-size: 0.84rem;
            line-height: 1.5;
        }

        .decision-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 18px;
            border: 0;
            border-radius: 9px;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .decision-button.approve {
            background: #16804b;
        }

        .decision-button.reject {
            background: #b83a3a;
        }

        .decision-button:hover {
            filter: brightness(0.94);
        }

        .documents-table-wrap {
            overflow-x: auto;
        }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
            text-align: left;
        }

        .documents-table th {
            padding: 11px 12px;
            background: #f5f9f6;
            color: #617168;
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .documents-table td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf1ee;
            color: #34473b;
            vertical-align: middle;
        }

        .document-link {
            color: #0b7546;
            font-weight: 700;
            text-decoration: none;
        }

        .document-link:hover {
            text-decoration: underline;
        }

        .document-qr-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            border: 1px solid #e5ece7;
            border-radius: 8px;
            background: #fff;
        }

        .document-qr-link canvas,
        .document-qr-link img {
            display: block;
            width: 48px;
            height: 48px;
        }

        .empty-documents {
            padding: 18px 12px;
            color: #718078;
            text-align: center;
        }

        .sidebar-overlay {
            display: none;
        }

        .mobile-menu {
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

        @media (max-width: 550px) {
            .dashboard-page {
                padding: 16px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
                gap: 14px;
                margin-bottom: 20px;
            }

            .tracking-card,
            .result-card {
                padding: 20px 17px;
            }

            .result-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .result-layout {
                grid-template-columns: 1fr;
            }

            .qr-panel {
                max-width: 280px;
            }

            .timeline-event {
                grid-template-columns: 34px minmax(0, 1fr);
            }

            .timeline-time {
                grid-column: 2;
                text-align: left;
            }

            .result-heading {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-shell">
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
                <a href="dashboard.php" class="nav-item"><i class="fa-solid fa-chart-pie"></i>Dashboard</a>
                <a href="create_document.php" class="nav-item"><i class="fa-solid fa-file-circle-plus"></i>Create Document</a>
                <a href="track_document.php" class="nav-item active" aria-current="page"><i class="fa-solid fa-location-dot"></i>Track Document</a>
                <div class="documents-nav-group">
                    <a href="my_documents.php" class="nav-item"><i class="fa-solid fa-folder-open"></i>My Documents</a>
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
                        <h1>Track Document</h1>
                        <p>Look up documents using their QR codes.</p>
                    </div>
                    <a class="back-link" href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                </header>

                <section class="tracking-card" aria-labelledby="trackingTitle">
                    <div class="section-heading">
                        <div class="section-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <div>
                            <h2 id="trackingTitle">Find a document</h2>
                            <p>Scan a document QR code or enter its encoded value.</p>
                        </div>
                    </div>

                    <form class="tracking-form" method="post" action="track_document.php">
                        <label for="qr_code">QR code value</label>
                        <input
                            id="qr_code"
                            name="qr_code"
                            type="text"
                            value="<?php echo trackDocumentEscape($qrCode); ?>"
                            placeholder="Scan or enter the QR code value"
                            autocomplete="off"
                            required
                        >
                        <button class="track-button" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i> Track Document
                        </button>
                    </form>

                    <?php if ($message !== ''): ?>
                        <div class="message <?php echo trackDocumentEscape($messageType); ?>" role="alert">
                            <?php echo trackDocumentEscape($message); ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($document !== null): ?>
                    <section class="result-card" aria-label="Document tracking result">
                        <div class="result-heading">
                            <h2><?php echo trackDocumentEscape($document['title']); ?></h2>
                            <div class="status-badges">
                                <span class="status-badge">
                                    <i class="fa-solid fa-location-dot"></i>
                                    At <?php echo trackDocumentEscape($currentOffice ?: 'Office not recorded'); ?>
                                </span>
                                <?php if ($latestDecision !== null): ?>
                                    <span class="status-badge decision-<?php echo trackDocumentEscape($latestDecision['event_type']); ?>">
                                        <i class="fa-solid <?php echo $latestDecision['event_type'] === 'approved' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                                        <?php echo $latestDecision['event_type'] === 'approved' ? 'Approved' : 'Rejected'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="result-layout">
                            <div>
                                <div class="result-grid">
                                    <div class="result-item">
                                        <span>QR code value</span>
                                        <strong><?php echo trackDocumentEscape($document['qr_code']); ?></strong>
                                    </div>
                                    <div class="result-item">
                                        <span>Document type</span>
                                        <strong><?php echo trackDocumentEscape($document['document_type']); ?></strong>
                                    </div>
                                    <div class="result-item">
                                        <span>Destination office</span>
                                        <strong><?php echo trackDocumentEscape($document['department']); ?></strong>
                                    </div>
                                    <div class="result-item">
                                        <span>Priority</span>
                                        <strong><?php echo trackDocumentEscape($document['priority']); ?></strong>
                                    </div>
                                    <div class="result-item">
                                        <span>Created by</span>
                                        <strong><?php echo trackDocumentEscape(trim((string) ($document['creator_name'] ?? '')) ?: 'User no longer available'); ?></strong>
                                    </div>
                                    <div class="result-item">
                                        <span>Created</span>
                                        <strong><?php echo trackDocumentEscape(date('F j, Y g:i A', strtotime($document['created_at']))); ?></strong>
                                    </div>
                                </div>
                                <?php if (trim((string) ($document['description'] ?? '')) !== ''): ?>
                                    <p class="result-note"><?php echo nl2br(trackDocumentEscape($document['description'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <aside class="qr-panel" aria-label="Document QR code">
                                <h3>Document QR Code</h3>
                                <p>Scan or save this code to look up the document.</p>
                                <div
                                    class="qr-code"
                                    id="documentQrCode"
                                    data-qr-code="<?php echo trackDocumentEscape($document['qr_code']); ?>"
                                    aria-label="QR code for <?php echo trackDocumentEscape($document['qr_code']); ?>"
                                ></div>
                                <p><?php echo trackDocumentEscape($document['qr_code']); ?></p>
                                <div class="qr-actions">
                                    <a class="qr-action" id="downloadQrCode" href="#" download="<?php echo trackDocumentEscape($document['qr_code']); ?>.png" aria-disabled="true">Preparing…</a>
                                    <button class="qr-action" type="button" onclick="window.print()">Print</button>
                                </div>
                                <p class="qr-error" id="qrCodeError" role="status" hidden>QR code could not be generated. Refresh to try again.</p>
                            </aside>
                        </div>
                        <?php if ($userId > 0 && $userOffice !== '' && $userOffice !== 'Department not set'): ?>
                            <form class="decision-form" method="post" action="track_document.php?qr_code=<?php echo rawurlencode($qrCode); ?>">
                                <input type="hidden" name="qr_code" value="<?php echo trackDocumentEscape($qrCode); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo trackDocumentEscape($csrfToken); ?>">
                                <p>Choose a decision for this verified document. Your registered office (<?php echo trackDocumentEscape($userOffice); ?>), account, and decision time will be saved in its history.</p>
                                <button class="decision-button approve" type="submit" name="action" value="approve">
                                    <i class="fa-solid fa-circle-check"></i> Approve
                                </button>
                                <button class="decision-button reject" type="submit" name="action" value="reject">
                                    <i class="fa-solid fa-circle-xmark"></i> Reject
                                </button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="timeline-card" aria-labelledby="timelineTitle">
                        <h2 class="card-title" id="timelineTitle">Office, Receipt &amp; Decision History</h2>
                        <p class="card-subtitle">See where the document has been and who received, approved, or rejected it.</p>
                        <div class="timeline">
                            <?php foreach ($timeline as $event): ?>
                                <?php
                                $eventType = (string) $event['event_type'];
                                $eventIcon = 'fa-file-circle-plus';
                                $eventLabel = 'Document created';
                                $eventActorLabel = 'Created by ';

                                if ($eventType === 'received') {
                                    $eventIcon = 'fa-inbox';
                                    $eventLabel = 'Received at office';
                                    $eventActorLabel = 'Received by ';
                                } elseif ($eventType === 'approved') {
                                    $eventIcon = 'fa-circle-check';
                                    $eventLabel = 'Document approved';
                                    $eventActorLabel = 'Approved by ';
                                } elseif ($eventType === 'rejected') {
                                    $eventIcon = 'fa-circle-xmark';
                                    $eventLabel = 'Document rejected';
                                    $eventActorLabel = 'Rejected by ';
                                }
                                ?>
                                <article class="timeline-event">
                                    <div class="timeline-icon">
                                        <i class="fa-solid <?php echo trackDocumentEscape($eventIcon); ?>"></i>
                                    </div>
                                    <div class="timeline-details">
                                        <strong><?php echo trackDocumentEscape($eventLabel); ?></strong>
                                        <p class="event-office"><?php echo trackDocumentEscape($event['office']); ?></p>
                                        <p><?php echo trackDocumentEscape($eventActorLabel . $event['received_by_name']); ?></p>
                                    </div>
                                    <time class="timeline-time" datetime="<?php echo trackDocumentEscape(date('c', strtotime($event['created_at']))); ?>">
                                        <?php echo trackDocumentEscape(date('F j, Y', strtotime($event['created_at']))); ?><br>
                                        <?php echo trackDocumentEscape(date('g:i A', strtotime($event['created_at']))); ?>
                                    </time>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($canRecordReceipt): ?>
                            <form class="receipt-form" method="post" action="track_document.php?qr_code=<?php echo rawurlencode($qrCode); ?>">
                                <input type="hidden" name="action" value="receive">
                                <input type="hidden" name="qr_code" value="<?php echo trackDocumentEscape($qrCode); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo trackDocumentEscape($csrfToken); ?>">
                                <p>Record this document as received at <strong><?php echo trackDocumentEscape($userOffice); ?></strong> by <strong><?php echo trackDocumentEscape($receiverName); ?></strong>. Your account and the current time will be saved to the history.</p>
                                <button class="track-button" type="submit">
                                    <i class="fa-solid fa-inbox"></i> Record receipt at my office
                                </button>
                            </form>
                        <?php elseif (!$isAdmin && $document !== null && $userOffice !== '' && $currentOffice === $userOffice): ?>
                            <p class="result-note">This document is already recorded at your office, <?php echo trackDocumentEscape($userOffice); ?>.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <section class="documents-card" aria-labelledby="documentsTitle">
                    <h2 class="card-title" id="documentsTitle"><?php echo $isAdmin ? 'Recent Documents' : 'Documents You Created'; ?></h2>
                    <p class="card-subtitle"><?php echo $isAdmin ? 'Recently created documents in DocTrack.' : 'Select a document to view its QR code, creation time, and office history.'; ?></p>
                    <div class="documents-table-wrap">
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>QR Code</th>
                                    <th>Document</th>
                                    <th>Destination office</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($userDocuments === []): ?>
                                    <tr><td class="empty-documents" colspan="4">No documents have been created yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($userDocuments as $userDocument): ?>
                                        <tr>
                                            <td>
                                                <a
                                                    class="document-qr-link"
                                                    href="track_document.php?qr_code=<?php echo rawurlencode($userDocument['qr_code']); ?>"
                                                    aria-label="Open document QR <?php echo trackDocumentEscape($userDocument['qr_code']); ?>"
                                                    title="Scan QR code or click to view document"
                                                >
                                                    <span
                                                        class="list-qr-code"
                                                        data-qr-code="<?php echo trackDocumentEscape($userDocument['qr_code']); ?>"
                                                    ></span>
                                                </a>
                                            </td>
                                            <td><?php echo trackDocumentEscape($userDocument['title']); ?></td>
                                            <td><?php echo trackDocumentEscape($userDocument['department']); ?></td>
                                            <td><?php echo trackDocumentEscape(date('M j, Y g:i A', strtotime($userDocument['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <?php if ($document !== null || $userDocuments !== []): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
            const qrContainer = document.getElementById('documentQrCode');
            const qrDownload = document.getElementById('downloadQrCode');
            const qrError = document.getElementById('qrCodeError');
            const listQrCodes = document.querySelectorAll('.list-qr-code');

            if (typeof QRCode === 'function') {
                listQrCodes.forEach(function (container) {
                    new QRCode(container, {
                        text: container.dataset.qrCode,
                        width: 48,
                        height: 48,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                });
            }

            if (typeof QRCode === 'function' && qrContainer) {
                new QRCode(qrContainer, {
                    text: qrContainer.dataset.qrCode,
                    width: 164,
                    height: 164,
                    correctLevel: QRCode.CorrectLevel.M
                });

                window.setTimeout(function () {
                    const qrCanvas = qrContainer.querySelector('canvas');
                    if (!qrCanvas) {
                        qrError.hidden = false;
                        return;
                    }

                    qrDownload.href = qrCanvas.toDataURL('image/png');
                    qrDownload.textContent = 'Download QR';
                    qrDownload.removeAttribute('aria-disabled');
                }, 0);
            } else if (qrError) {
                qrError.hidden = false;
            }
        </script>
    <?php endif; ?>

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
</body>
</html>
