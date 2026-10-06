<?php

require_once __DIR__ . '/session_config.php';

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] === 0) {
    header('Location: index.php');
    exit;
}

require 'db.php';
require_once __DIR__ . '/document_tracking.php';

$departmentOptions = require __DIR__ . '/department_options.php';

/*
|--------------------------------------------------------------------------
| Create documents table if it does not exist
|--------------------------------------------------------------------------
*/

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
    error_log('Could not initialize documents table: ' . $conn->error);
    http_response_code(500);
    exit('Document creation is temporarily unavailable. Please contact the administrator.');
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


/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = '';
$createdQrCode = '';
$createdDocument = null;

$userName = $_SESSION['user_name'] ?? 'User';

$allowedTitles = [
    'REQUEST FORM',
    'JOB REQUEST',
    'MATERIALS/SUPPLIES',
    'CASH/CHECK REQUISITION',
    'CANTEEN REQUISITION SLIP',
    'VISUALS AUDIO',
    'PROPOSAL',
    'EXCUSE',
    'RESIGNATION',
];

$allowedTypes = [
    'PDF',
    'Memo',
    'Letter',
    'Report',
    'Request',
    'Canteen Requisition Slip',
    'Other'
];

$allowedExtensions = [
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'jpg',
    'jpeg',
    'png'
];


/*
|--------------------------------------------------------------------------
| Form Submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title = trim($_POST['title'] ?? '');
    $documentType = trim($_POST['document_type'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $priority = trim($_POST['priority'] ?? 'Normal');
    $description = trim($_POST['description'] ?? '');

    $filePath = null;


    /*
    |--------------------------------------------------------------------------
    | Validate Form
    |--------------------------------------------------------------------------
    */

    if (
        !in_array($title, $allowedTitles, true) ||
        !in_array($documentType, $allowedTypes, true) ||
        !in_array($department, $departmentOptions, true) ||
        !in_array($priority, ['Normal', 'Urgent'], true)
    ) {

        $message = 'Please complete all required document details.';
        $messageType = 'error';

    }


    /*
    |--------------------------------------------------------------------------
    | File Upload
    |--------------------------------------------------------------------------
    */

    elseif (
        isset($_FILES['document_file']) &&
        $_FILES['document_file']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        $uploadedFile = $_FILES['document_file'];

        $extension = strtolower(
            pathinfo(
                $uploadedFile['name'],
                PATHINFO_EXTENSION
            )
        );


        if (
            $uploadedFile['error'] !== UPLOAD_ERR_OK ||
            !in_array($extension, $allowedExtensions, true)
        ) {

            $message = 'Please upload a valid PDF, office document, or image file.';
            $messageType = 'error';

        }

        elseif ($uploadedFile['size'] > 10 * 1024 * 1024) {

            $message = 'The uploaded file must be 10 MB or smaller.';
            $messageType = 'error';

        }

        else {

            $uploadDirectory =
                __DIR__ .
                DIRECTORY_SEPARATOR .
                'uploads' .
                DIRECTORY_SEPARATOR .
                'documents';


            if (!is_dir($uploadDirectory)) {

                mkdir(
                    $uploadDirectory,
                    0755,
                    true
                );

            }


            $storedName =
                bin2hex(random_bytes(12)) .
                '.' .
                $extension;


            $storedPath =
                $uploadDirectory .
                DIRECTORY_SEPARATOR .
                $storedName;


            if (
                move_uploaded_file(
                    $uploadedFile['tmp_name'],
                    $storedPath
                )
            ) {

                $filePath =
                    'uploads/documents/' .
                    $storedName;

            }

            else {

                $message =
                    'The document could not be uploaded. Please try again.';

                $messageType = 'error';

            }

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Save Document
    |--------------------------------------------------------------------------
    */

    if ($message === '') {

        $qrCode = 'QR-' . strtoupper(bin2hex(random_bytes(16)));


        $stmt = $conn->prepare("
            INSERT INTO documents
            (
                qr_code,
                user_id,
                title,
                document_type,
                department,
                priority,
                description,
                file_path
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");


        $stmt->bind_param(
            'sissssss',
            $qrCode,
            $_SESSION['user_id'],
            $title,
            $documentType,
            $department,
            $priority,
            $description,
            $filePath
        );


        $transactionStarted = $conn->begin_transaction();
        $documentSaved = $transactionStarted && $stmt->execute();
        $eventSaved = $documentSaved && recordDocumentTrackingEvent(
            $conn,
            (int) $stmt->insert_id,
            'created',
            trim((string) ($_SESSION['user_department'] ?? '')) ?: 'Department not set',
            (int) $_SESSION['user_id'],
            trim((string) ($_SESSION['user_name'] ?? 'User'))
        );
        $transactionCommitted = $eventSaved && $conn->commit();

        if ($transactionCommitted) {
            $message =
                'Document created successfully. Scan or save the QR code below to track it.';

            $messageType = 'success';
            $createdQrCode = $qrCode;
            $createdDocument = [
                'title' => $title,
                'document_type' => $documentType,
                'department' => $department,
                'priority' => $priority,
                'description' => $description,
                'file_path' => $filePath,
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => trim((string) ($_SESSION['user_name'] ?? 'User')),
            ];

            $_POST = [];
        } else {
            if ($transactionStarted) {
                $conn->rollback();
            }

            if (!$documentSaved) {
                error_log('Could not save document: ' . $stmt->error);
            } elseif (!$eventSaved || !$transactionCommitted) {
                error_log('Could not commit document and initial tracking event.');
            }

            $message =
                'The document could not be saved with its initial tracking record. Please try again.';

            $messageType = 'error';
        }

    }

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Create Document | DocTrack</title>


    <!-- Google Font -->

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >


    <!-- Font Awesome -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <style>

        /* ==================================================
           GLOBAL
        ================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }


        body {
            font-family: 'Inter', sans-serif;
            background: #f4f8f5;
            color: #17251c;
        }


        a {
            text-decoration: none;
        }


        button,
        input,
        select,
        textarea {
            font-family: inherit;
        }


        /* ==================================================
           MAIN LAYOUT
        ================================================== */

        .dashboard-shell {
            min-height: 100vh;
        }


        /* ==================================================
           SIDEBAR
        ================================================== */

        .dashboard-sidebar {
            width: 68px;
            min-height: 100vh;

            background: #0b3d2e;

            color: white;

            padding: 24px 8px;

            position: fixed;

            top: 0;
            left: 0;
            bottom: 0;

            z-index: 1000;

            overflow-x: hidden;
            transition: width 0.25s ease, padding 0.25s ease;
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

            border-bottom:
                1px solid
                rgba(255,255,255,0.12);
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
            width: 46px;
            height: 46px;

            border-radius: 12px;

            background: #2e9d63;

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 15px;

            font-weight: 800;
        }


        .sidebar-brand strong {
            display: block;

            font-size: 14px;

            font-weight: 700;
        }


        .sidebar-brand small {
            display: block;

            color: #a9c8b7;

            font-size: 11px;

            margin-top: 3px;
        }


        /* ==================================================
           NAVIGATION
        ================================================== */

        .sidebar-nav {
            display: flex;

            flex-direction: column;

            gap: 6px;
        }


        .nav-item {
            width: 100%;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 0;

            padding: 13px 0;

            border-radius: 10px;

            color: #c7ddd2;

            background: transparent;

            border: none;

            cursor: pointer;

            font-size: 0;

            font-weight: 500;

            text-align: left;

            transition: 0.2s ease;
        }

        .dashboard-sidebar:hover .nav-item,
        .dashboard-sidebar:focus-within .nav-item,
        .dashboard-sidebar.is-open .nav-item {
            justify-content: flex-start;
            gap: 13px;
            padding: 13px 14px;
            font-size: 13px;
        }


        .nav-item i {
            width: 20px;

            text-align: center;

            font-size: 15px;
        }


        .nav-item:hover {
            background:
                rgba(255,255,255,0.08);

            color: white;

            transform:
                translateX(2px);
        }


        .nav-item.active {
            background: #2e9d63;

            color: white;

            box-shadow:
                0 5px 15px
                rgba(46,157,99,0.25);
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


        /* ==================================================
           MAIN PAGE
        ================================================== */

        .dashboard-page {
            width: calc(100% - 68px);

            margin-left: 68px;

            min-height: 100vh;

            padding: 32px;
        }


        .page-container {
            max-width: 1100px;

            margin: auto;
        }


        /* ==================================================
           TOP HEADER
        ================================================== */

        .document-page-header {
            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 24px;
        }


        .page-heading {
            display: flex;

            align-items: center;

            gap: 15px;
        }


        .back-button {
            width: 42px;
            height: 42px;

            border-radius: 11px;

            background: white;

            border:
                1px solid
                #e1eae4;

            color: #0b3d2e;

            display: flex;

            align-items: center;
            justify-content: center;

            transition: 0.2s ease;
        }


        .back-button:hover {
            background: #e8f6ed;

            color: #167447;

            transform:
                translateX(-2px);
        }


        .page-heading h1 {
            font-size: 25px;

            font-weight: 800;

            color: #123d2c;
        }


        .page-heading p {
            font-size: 13px;

            color: #78857e;

            margin-top: 4px;
        }


        /* ==================================================
           FORM CARD
        ================================================== */

        .document-form-card {
            background: white;

            border-radius: 18px;

            border:
                1px solid
                #e3ece6;

            box-shadow:
                0 8px 30px
                rgba(20,60,40,0.06);

            overflow: hidden;
        }


        .form-card-header {
            padding: 25px 28px;

            background:
                linear-gradient(
                    135deg,
                    #0b3d2e,
                    #167447
                );

            color: white;

            display: flex;

            align-items: center;

            gap: 16px;
        }


        .form-header-icon {
            width: 48px;
            height: 48px;

            border-radius: 12px;

            background:
                rgba(255,255,255,0.15);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 20px;
        }


        .form-card-header h2 {
            font-size: 18px;

            margin-bottom: 4px;
        }


        .form-card-header p {
            color: #cbe5d7;

            font-size: 12px;
        }


        .document-form {
            padding: 28px;
        }


        /* ==================================================
           FORM GRID
        ================================================== */

        .form-section-title {
            display: flex;

            align-items: center;

            gap: 9px;

            color: #173e2d;

            font-size: 14px;

            font-weight: 700;

            margin-bottom: 18px;
        }


        .form-section-title i {
            color: #16804b;
        }


        .form-row {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 20px;

            margin-bottom: 20px;
        }


        .field-group {
            display: flex;

            flex-direction: column;

            margin-bottom: 20px;
        }


        .form-row .field-group {
            margin-bottom: 0;
        }


        .field-group label {
            font-size: 12px;

            font-weight: 700;

            color: #34483d;

            margin-bottom: 8px;
        }


        .field-group label span {
            font-weight: 400;

            color: #9aa59f;
        }


        .required {
            color: #d34e4e;
        }


        .field-group input,
        .field-group select,
        .field-group textarea {
            width: 100%;

            border:
                1px solid
                #dce6df;

            background: #fbfdfb;

            border-radius: 10px;

            padding:
                12px
                14px;

            font-size: 13px;

            color: #263c31;

            outline: none;

            transition:
                border 0.2s ease,
                box-shadow 0.2s ease,
                background 0.2s ease;
        }


        .field-group input {
            height: 46px;
        }


        .field-group select {
            height: 46px;

            cursor: pointer;
        }

        .department-picker {
            position: relative;
        }

        .department-picker-button {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border: 1px solid #dce6df;
            border-radius: 10px;
            background: #fbfdfb;
            color: #263c31;
            font-size: 13px;
            text-align: left;
            cursor: pointer;
        }

        .department-picker-button:focus-visible,
        .department-picker-button[aria-expanded="true"] {
            border-color: #2e9d63;
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(46,157,99,0.10);
        }

        .department-picker-button i {
            color: #64756b;
            transition: transform 0.18s ease;
        }

        .department-picker-button[aria-expanded="true"] i {
            transform: rotate(180deg);
        }

        .department-picker-menu {
            position: fixed;
            z-index: 5000;
            display: none;
            max-height: 260px;
            overflow-y: auto;
            padding: 5px;
            border: 1px solid #dce6df;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(20,60,40,0.16);
        }

        .department-picker-menu.is-open {
            display: block;
        }

        .department-picker-option {
            display: block;
            width: 100%;
            min-height: 38px;
            padding: 9px 11px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: #263c31;
            font-size: 13px;
            text-align: left;
            cursor: pointer;
        }

        .department-picker-option:hover,
        .department-picker-option:focus-visible,
        .department-picker-option[aria-selected="true"] {
            outline: none;
            background: #eaf7ef;
            color: #126a40;
        }

        .department-picker-native {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0 !important;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            clip-path: inset(50%);
            white-space: nowrap;
        }


        .field-group textarea {
            min-height: 120px;

            resize: vertical;
        }


        .field-group input:focus,
        .field-group select:focus,
        .field-group textarea:focus {
            border-color: #2e9d63;

            background: white;

            box-shadow:
                0 0 0 3px
                rgba(46,157,99,0.10);
        }


        .field-group input::placeholder,
        .field-group textarea::placeholder {
            color: #a6b0aa;
        }


        /* ==================================================
           PRIORITY
        ================================================== */

        .priority-options {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 10px;
        }


        .priority-option {
            position: relative;
        }


        .priority-option input {
            position: absolute;

            opacity: 0;
        }


        .priority-label {
            display: flex;

            align-items: center;

            gap: 9px;

            padding: 11px 13px;

            border:
                1px solid
                #dce6df;

            border-radius: 10px;

            cursor: pointer;

            font-size: 12px;

            font-weight: 600;

            color: #59675f;

            background: #fbfdfb;

            transition: 0.2s ease;
        }


        .priority-label i {
            color: #8d9992;
        }


        .priority-option input:checked +
        .priority-label {
            border-color: #2e9d63;

            background: #eaf7ef;

            color: #167447;
        }


        .priority-option input:checked +
        .priority-label i {
            color: #16804b;
        }


        /* ==================================================
           UPLOAD AREA
        ================================================== */

        .upload-box {
            border:
                1px dashed
                #b8d4c2;

            background: #f7fbf8;

            border-radius: 14px;

            padding: 20px;

            margin-top: 5px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

            transition: 0.2s ease;
        }


        .upload-box:hover {
            border-color: #2e9d63;

            background: #f1faf4;
        }


        .upload-info {
            display: flex;

            align-items: center;

            gap: 14px;
        }


        .upload-icon {
            width: 46px;
            height: 46px;

            flex-shrink: 0;

            border-radius: 11px;

            background: #dff3e7;

            color: #16804b;

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 19px;
        }


        .upload-box strong {
            display: block;

            font-size: 13px;

            color: #244335;

            margin-bottom: 4px;
        }


        .upload-box p {
            font-size: 11px;

            color: #89958e;

            line-height: 1.5;
        }


        .upload-actions {
            display: flex;

            align-items: center;

            gap: 10px;

            flex-shrink: 0;
        }


        .upload-button {
            padding:
                10px
                15px;

            border-radius: 9px;

            background: #0b3d2e;

            color: white;

            font-size: 12px;

            font-weight: 700;

            cursor: pointer;

            transition: 0.2s ease;
        }


        .upload-button:hover {
            background: #167447;
        }


        .upload-file-name {
            max-width: 180px;

            overflow: hidden;

            text-overflow: ellipsis;

            white-space: nowrap;

            font-size: 11px;

            color: #718078;
        }


        #document-file {
            display: none;
        }


        /* ==================================================
           FORM FOOTER
        ================================================== */

        .form-footer {
            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-top: 25px;

            padding-top: 22px;

            border-top:
                1px solid
                #edf2ee;
        }


        .form-note {
            display: flex;

            align-items: center;

            gap: 7px;

            font-size: 11px;

            color: #8a968f;
        }


        .form-note i {
            color: #16804b;
        }


        .create-button {
            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 9px;

            min-width: 170px;

            padding:
                13px
                20px;

            border: none;

            border-radius: 10px;

            background: #0b3d2e;

            color: white;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

            box-shadow:
                0 6px 15px
                rgba(11,61,46,0.16);

            transition: 0.2s ease;
        }


        .create-button:hover {
            background: #167447;

            transform:
                translateY(-1px);

            box-shadow:
                0 8px 18px
                rgba(11,61,46,0.20);
        }


        /* ==================================================
           MESSAGES
        ================================================== */

        .status-message {
            margin:
                20px
                28px
                0;

            padding:
                13px
                15px;

            border-radius: 10px;

            font-size: 12px;

            font-weight: 600;

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .status-message.success {
            background: #e7f7ed;

            color: #167447;

            border:
                1px solid
                #c6ead4;
        }


        .status-message.success::before {
            content: "\f058";

            font-family:
                "Font Awesome 6 Free";

            font-weight: 900;

            font-size: 16px;
        }


        .status-message.error {
            background: #fff0f0;

            color: #b53f3f;

            border:
                1px solid
                #f3d1d1;
        }


        .status-message.error::before {
            content: "\f06a";

            font-family:
                "Font Awesome 6 Free";

            font-weight: 900;

            font-size: 16px;
        }

        .created-qr-card {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: 18px 28px 0;
            padding: 18px;
            border: 1px solid #c6ead4;
            border-radius: 12px;
            background: #f5fbf6;
        }

        .created-qr-image {
            display: flex;
            flex: 0 0 150px;
            align-items: center;
            justify-content: center;
            width: 150px;
            height: 150px;
            padding: 8px;
            border: 1px solid #e1e9e4;
            border-radius: 10px;
            background: #fff;
        }

        .created-qr-print-column {
            display: flex;
            flex: 0 0 150px;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .created-qr-image canvas,
        .created-qr-image img {
            display: block;
            max-width: 100%;
            height: auto;
        }

        .created-qr-image {
            min-width: 150px;
        }

        .created-qr-details h3 {
            color: #123d2c;
            font-size: 15px;
        }

        .created-qr-details p {
            margin-top: 7px;
            color: #617168;
            font-size: 12px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .created-qr-details a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 12px;
            color: #0b7546;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .created-qr-print-column .created-print-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            width: 100%;
            padding: 9px 10px;
            border: 0;
            border-radius: 8px;
            background: #0b7546;
            color: #fff;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .created-qr-error {
            color: #a83030 !important;
        }

        @media (max-width: 550px) {
            .created-qr-card {
                align-items: flex-start;
                flex-direction: column;
                margin-right: 16px;
                margin-left: 16px;
            }
        }

        .created-document-print-sheet {
            display: none;
        }

        @media print {
            body.print-created-document > *:not(.created-document-print-sheet) {
                display: none !important;
            }

            body.print-created-document .created-document-print-sheet {
                display: block !important;
                padding: 24px;
                color: #111;
                font-family: Arial, sans-serif;
            }

            .print-sheet-header {
                display: flex;
                align-items: center;
                gap: 16px;
                padding-bottom: 18px;
                border-bottom: 2px solid #1b6840;
            }

            .print-sheet-header img {
                width: 68px;
                height: 68px;
                object-fit: contain;
            }

            .print-sheet-header h1 {
                margin: 0;
                color: #164b31;
                font-size: 20px;
            }

            .print-sheet-header p {
                margin: 5px 0 0;
                color: #4b5b50;
                font-size: 12px;
            }

            .print-sheet-content {
                display: grid;
                grid-template-columns: minmax(0, 1fr) 190px;
                gap: 24px;
                margin-top: 24px;
            }

            .print-sheet-content h2 {
                margin: 0 0 18px;
                color: #164b31;
                font-size: 18px;
            }

            .print-document-details {
                width: 100%;
                border-collapse: collapse;
            }

            .print-document-details th,
            .print-document-details td {
                padding: 10px 8px;
                border-bottom: 1px solid #d9e3dc;
                font-size: 12px;
                text-align: left;
                vertical-align: top;
            }

            .print-document-details th {
                width: 135px;
                color: #43564a;
            }

            .print-qr-panel {
                text-align: center;
            }

            .print-qr-panel h3 {
                margin: 0 0 10px;
                color: #164b31;
                font-size: 13px;
            }

            .print-qr-panel img,
            .print-qr-panel canvas {
                display: block;
                width: 170px;
                height: 170px;
                margin: 0 auto;
            }

            .print-qr-panel p {
                margin-top: 8px;
                font-size: 10px;
                overflow-wrap: anywhere;
            }
        }


        /* ==================================================
           MOBILE MENU
        ================================================== */

        .sidebar-hamburger {
            display: none;

            position: fixed;

            top: 18px;
            left: 18px;

            z-index: 2000;

            width: 42px;
            height: 42px;

            border: none;

            border-radius: 10px;

            background: #0b3d2e;

            color: white;

            cursor: pointer;

            font-size: 17px;
        }


        /* ==================================================
           RESPONSIVE
        ================================================== */

        @media (max-width: 850px) {

            .form-row {
                grid-template-columns: 1fr;
            }


            .upload-box {
                flex-direction: column;

                align-items: flex-start;
            }


            .upload-actions {
                width: 100%;
            }

        }


        @media (max-width: 768px) {

            .dashboard-sidebar {
                width: 260px;
                padding: 24px 16px;
                transform:
                    translateX(-100%);
            }


            .dashboard-sidebar.is-open {
                transform:
                    translateX(0);
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


            .dashboard-page {
                width: 100%;

                margin-left: 0;

                padding:
                    20px;
            }


            .sidebar-hamburger {
                display: flex;

                align-items: center;

                justify-content: center;
            }


            .document-page-header {
                padding-left: 55px;
            }

        }


        @media (max-width: 550px) {

            .dashboard-page {
                padding: 15px;
            }


            .page-heading h1 {
                font-size: 21px;
            }


            .page-heading p {
                font-size: 11px;
            }


            .document-form {
                padding: 20px;
            }


            .form-card-header {
                padding: 20px;
            }


            .form-card-header h2 {
                font-size: 16px;
            }


            .form-footer {
                flex-direction: column;

                align-items: stretch;

                gap: 15px;
            }


            .create-button {
                width: 100%;
            }


            .form-note {
                justify-content: center;
            }


            .upload-info {
                align-items: flex-start;
            }


            .upload-actions {
                flex-direction: column;

                align-items: stretch;
            }


            .upload-button {
                text-align: center;
            }


            .upload-file-name {
                max-width: 100%;

                text-align: center;
            }

        }

    </style>

</head>


<body>


<div class="dashboard-shell">


    <!-- ==================================================
         SIDEBAR
    ================================================== -->

    <aside
        class="dashboard-sidebar"
        id="sidebar"
        aria-label="Main navigation"
    >

        <div class="sidebar-brand">

            <div class="mini-logo">
                CSJ
            </div>

            <div>

                <strong>
                    Colegio de San Jose
                </strong>

                <small>
                    Document Tracking System
                </small>

            </div>

        </div>


        <nav class="sidebar-nav">


            <a
                href="dashboard.php"
                class="nav-item"
            >

                <i class="fa-solid fa-chart-pie"></i>

                Dashboard

            </a>


            <a
                href="create_document.php"
                class="nav-item active"
            >

                <i class="fa-solid fa-file-circle-plus"></i>

                Create Document

            </a>


            <a
                href="track_document.php"
                class="nav-item"
            >

                <i class="fa-solid fa-location-dot"></i>

                Track Document

            </a>


                <div class="documents-nav-group">
                    <a
                        href="my_documents.php"
                        class="nav-item"
                    >

                        <i class="fa-solid fa-folder-open"></i>

                        My Documents

                    </a>

                    <a href="received_documents.php" class="nav-subitem">
                        <i class="fa-solid fa-inbox"></i>
                        Received Documents
                    </a>
                </div>


                <a
                href="#"
                class="nav-item"
            >

                <i class="fa-solid fa-inbox"></i>

                Incoming Requests

            </a>


            <a
                href="#"
                class="nav-item"
            >

                <i class="fa-solid fa-chart-column"></i>

                Reports

            </a>


            <a
                href="#"
                class="nav-item"
            >

                <i class="fa-solid fa-gear"></i>

                Settings

            </a>


            <a
                href="logout.php"
                class="nav-item nav-logout"
            >

                <i class="fa-solid fa-right-from-bracket"></i>

                Log Out

            </a>

        </nav>

    </aside>



    <!-- ==================================================
         MAIN CONTENT
    ================================================== -->

    <div class="dashboard-page">

        <button
            class="sidebar-hamburger"
            id="sidebarToggle"
            type="button"
            aria-label="Open navigation menu"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


        <div class="page-container">


            <!-- ==================================================
                 PAGE HEADER
            ================================================== -->

            <header class="document-page-header">

                <div class="page-heading">

                    <a
                        href="dashboard.php"
                        class="back-button"
                        aria-label="Back to Dashboard"
                    >

                        <i class="fa-solid fa-arrow-left"></i>

                    </a>


                    <div>

                        <h1>
                            Create Document
                        </h1>

                        <p>
                            Add a new document to the DocTrack system.
                        </p>

                    </div>

                </div>

            </header>



            <!-- ==================================================
                 FORM CARD
            ================================================== -->

            <main class="document-form-card">


                <!-- HEADER -->

                <div class="form-card-header">

                    <div class="form-header-icon">

                        <i class="fa-solid fa-file-circle-plus"></i>

                    </div>


                    <div>

                        <h2>
                            Document Information
                        </h2>

                        <p>
                            Enter the details of the document you want to track.
                        </p>

                    </div>

                </div>



                <!-- MESSAGES -->

                <?php if ($message !== ''): ?>

                    <div
                        class="status-message <?php echo $messageType; ?>"
                    >

                        <?php
                        echo htmlspecialchars(
                            $message,
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>

                    </div>

                <?php endif; ?>

                <?php if ($createdQrCode !== ''): ?>
                    <section class="created-qr-card" aria-labelledby="createdQrTitle">
                        <div class="created-qr-print-column">
                            <div class="created-qr-image" id="createdQrImage" data-qr-code="<?php echo htmlspecialchars($createdQrCode, ENT_QUOTES, 'UTF-8'); ?>"></div>
                            <button class="created-print-button" id="printCreatedDocument" type="button">
                                <i class="fa-solid fa-print"></i> Print Document
                            </button>
                        </div>
                        <div class="created-qr-details">
                            <h3 id="createdQrTitle">Document QR code</h3>
                            <p>This QR code is saved with your document. Scan it to open its tracking history.</p>
                            <p><strong>QR value:</strong> <?php echo htmlspecialchars($createdQrCode, ENT_QUOTES, 'UTF-8'); ?></p>
                            <a href="track_document.php?qr_code=<?php echo rawurlencode($createdQrCode); ?>">
                                <i class="fa-solid fa-location-dot"></i> View document tracking
                            </a>
                            <a href="my_documents.php?created=1">
                                <i class="fa-solid fa-folder-open"></i> Go to My Documents
                            </a>
                            <p class="created-qr-error" id="createdQrError" hidden>The QR image could not be generated. The QR value is still saved above.</p>
                        </div>
                    </section>
                <?php endif; ?>



                <!-- FORM -->

                <form
                    class="document-form"
                    method="post"
                    enctype="multipart/form-data"
                >


                    <!-- BASIC INFORMATION -->

                    <div class="form-section-title">

                        <i class="fa-solid fa-circle-info"></i>

                        Basic Information

                    </div>


                    <div class="form-row">


                        <!-- DOCUMENT TITLE -->

                        <div class="field-group">

                            <label for="title">

                                Document Title

                                <span class="required">*</span>

                            </label>


                            <select
                                id="title"
                                name="title"
                                required
                            >

                                <option value="">
                                    Select document title
                                </option>

                                <?php foreach ($allowedTitles as $allowedTitle): ?>
                                    <option
                                        value="<?php echo htmlspecialchars(
                                            $allowedTitle,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>"
                                        <?php echo ($_POST['title'] ?? '') === $allowedTitle
                                            ? 'selected'
                                            : ''; ?>
                                    >
                                        <?php echo htmlspecialchars(
                                            $allowedTitle,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>

                            </select>

                        </div>



                        <!-- DOCUMENT TYPE -->

                        <div class="field-group">

                            <label for="document-type">

                                Document Type

                                <span class="required">*</span>

                            </label>


                            <select
                                id="document-type"
                                name="document_type"
                                required
                            >

                                <option value="">
                                    Select document type
                                </option>


                                <?php foreach ($allowedTypes as $type): ?>

                                    <option
                                        value="<?php echo htmlspecialchars(
                                            $type,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>"

                                        <?php
                                        echo
                                        ($_POST['document_type'] ?? '') === $type
                                            ? 'selected'
                                            : '';
                                        ?>
                                    >

                                        <?php echo htmlspecialchars(
                                            $type,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>



                    <!-- DESTINATION -->

                    <div class="form-row">


                        <div class="field-group">

                            <label for="department">

                                Destination Department / Office

                                <span class="required">*</span>

                            </label>


                            <div class="department-picker" id="departmentPicker">
                                <button
                                    class="department-picker-button"
                                    id="departmentPickerButton"
                                    type="button"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    aria-controls="departmentPickerMenu"
                                >
                                    <span id="departmentPickerValue">Select department / office</span>
                                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                </button>
                                <select
                                    class="department-picker-native"
                                    id="department"
                                    name="department"
                                    required
                                    tabindex="-1"
                                    aria-hidden="true"
                                >
                                    <option value="">Select department / office</option>
                                    <?php foreach ($departmentOptions as $departmentOption): ?>
                                        <option
                                            value="<?php echo htmlspecialchars(
                                                $departmentOption,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                                            <?php echo ($_POST['department'] ?? '') === $departmentOption
                                                ? 'selected'
                                                : ''; ?>
                                        >
                                            <?php echo htmlspecialchars(
                                                $departmentOption,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div
                                    class="department-picker-menu"
                                    id="departmentPickerMenu"
                                    role="listbox"
                                    aria-label="Department or office"
                                >
                                    <?php foreach ($departmentOptions as $departmentOption): ?>
                                        <button
                                            class="department-picker-option"
                                            type="button"
                                            role="option"
                                            data-value="<?php echo htmlspecialchars(
                                                $departmentOption,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                                            aria-selected="<?php echo ($_POST['department'] ?? '') === $departmentOption
                                                ? 'true'
                                                : 'false'; ?>"
                                        >
                                            <?php echo htmlspecialchars(
                                                $departmentOption,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        </div>



                        <!-- PRIORITY -->

                        <div class="field-group">

                            <label>

                                Priority

                                <span class="required">*</span>

                            </label>


                            <div class="priority-options">


                                <div class="priority-option">

                                    <input
                                        type="radio"
                                        id="normal"
                                        name="priority"
                                        value="Normal"

                                        <?php
                                        echo
                                        ($_POST['priority'] ?? 'Normal') === 'Normal'
                                            ? 'checked'
                                            : '';
                                        ?>
                                    >


                                    <label
                                        for="normal"
                                        class="priority-label"
                                    >

                                        <i class="fa-solid fa-circle-check"></i>

                                        Normal

                                    </label>

                                </div>



                                <div class="priority-option">

                                    <input
                                        type="radio"
                                        id="urgent"
                                        name="priority"
                                        value="Urgent"

                                        <?php
                                        echo
                                        ($_POST['priority'] ?? '') === 'Urgent'
                                            ? 'checked'
                                            : '';
                                        ?>
                                    >


                                    <label
                                        for="urgent"
                                        class="priority-label"
                                    >

                                        <i class="fa-solid fa-triangle-exclamation"></i>

                                        Urgent

                                    </label>

                                </div>

                            </div>

                        </div>

                    </div>



                    <!-- DESCRIPTION -->

                    <div class="form-section-title">

                        <i class="fa-solid fa-align-left"></i>

                        Additional Information

                    </div>


                    <div class="field-group">

                        <label for="description">

                            Description

                            <span>
                                (Optional)
                            </span>

                        </label>


                        <textarea
                            id="description"
                            name="description"
                            rows="5"
                            placeholder="Add notes, instructions, or other information about this document..."
                        ><?php echo htmlspecialchars(
                            $_POST['description'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?></textarea>

                    </div>



                    <!-- FILE UPLOAD -->

                    <div class="form-section-title">

                        <i class="fa-solid fa-paperclip"></i>

                        Document Attachment

                    </div>


                    <div class="upload-box">


                        <div class="upload-info">

                            <div class="upload-icon">

                                <i class="fa-solid fa-cloud-arrow-up"></i>

                            </div>


                            <div>

                                <strong>
                                    Attach Physical Document
                                </strong>

                                <p>
                                    Upload a scanned copy or photo for tracking.
                                    Supported files: PDF, Word, Excel, JPG, PNG.
                                    Maximum size: 10 MB.
                                </p>

                            </div>

                        </div>


                        <div class="upload-actions">

                            <label
                                class="upload-button"
                                for="document-file"
                            >

                                <i class="fa-solid fa-upload"></i>

                                Choose File

                            </label>


                            <span
                                class="upload-file-name"
                                id="upload-file-name"
                            >
                                No file selected
                            </span>

                        </div>


                        <input
                            id="document-file"
                            type="file"
                            name="document_file"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
                        >

                    </div>



                    <!-- FORM FOOTER -->

                    <div class="form-footer">


                        <div class="form-note">

                            <i class="fa-solid fa-shield-halved"></i>

                            Your document information is securely stored.

                        </div>


                        <button
                            class="create-button"
                            type="submit"
                        >

                            <i class="fa-solid fa-plus"></i>

                            Create Document

                        </button>

                    </div>


                </form>

            </main>

        </div>

    </div>

</div>

<?php if ($createdDocument !== null): ?>
    <section class="created-document-print-sheet" aria-label="Printable document details">
        <header class="print-sheet-header">
            <div>
                <h1>COLEGIO DE SAN JOSE</h1>
                <p>DOCUMENT TRACKING SYSTEM</p>
            </div>
        </header>
        <div class="print-sheet-content">
            <div>
                <h2><?php echo htmlspecialchars($createdDocument['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <table class="print-document-details">
                    <tbody>
                        <tr><th>QR Code</th><td><?php echo htmlspecialchars($createdQrCode, ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Document Type</th><td><?php echo htmlspecialchars($createdDocument['document_type'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Destination Office</th><td><?php echo htmlspecialchars($createdDocument['department'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Priority</th><td><?php echo htmlspecialchars($createdDocument['priority'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Created By</th><td><?php echo htmlspecialchars($createdDocument['created_by'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Created</th><td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($createdDocument['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                        <tr><th>Description</th><td><?php echo nl2br(htmlspecialchars($createdDocument['description'] !== '' ? $createdDocument['description'] : 'None provided', ENT_QUOTES, 'UTF-8')); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <aside class="print-qr-panel">
                <h3>SCAN TO TRACK</h3>
                <div id="createdPrintQrImage" data-qr-code="<?php echo htmlspecialchars($createdQrCode, ENT_QUOTES, 'UTF-8'); ?>"></div>
                <p><?php echo htmlspecialchars($createdQrCode, ENT_QUOTES, 'UTF-8'); ?></p>
            </aside>
        </div>
    </section>
<?php endif; ?>



<script>

    /* ==================================================
       MOBILE SIDEBAR
    ================================================== */

    const sidebar =
        document.getElementById('sidebar');

    const sidebarToggle =
        document.getElementById('sidebarToggle');


    if (sidebar && sidebarToggle) {

        sidebarToggle.addEventListener(
            'click',
            () => {

                sidebar.classList.toggle(
                    'is-open'
                );

            }
        );

    }

    const departmentPicker =
        document.getElementById('departmentPicker');
    const departmentSelect =
        document.getElementById('department');
    const departmentPickerButton =
        document.getElementById('departmentPickerButton');
    const departmentPickerValue =
        document.getElementById('departmentPickerValue');
    const departmentPickerMenu =
        document.getElementById('departmentPickerMenu');

    if (
        departmentPicker &&
        departmentSelect &&
        departmentPickerButton &&
        departmentPickerValue &&
        departmentPickerMenu
    ) {
        const departmentOptions =
            Array.from(departmentPickerMenu.querySelectorAll('[role="option"]'));

        document.body.appendChild(departmentPickerMenu);

        const syncDepartmentSelection = () => {
            const selectedOption =
                departmentSelect.options[departmentSelect.selectedIndex];

            departmentPickerValue.textContent =
                selectedOption && selectedOption.value
                    ? selectedOption.textContent.trim()
                    : 'Select department / office';

            departmentOptions.forEach((option) => {
                option.setAttribute(
                    'aria-selected',
                    String(option.dataset.value === departmentSelect.value)
                );
            });
        };

        const positionDepartmentMenu = () => {
            const pickerRect =
                departmentPickerButton.getBoundingClientRect();
            const minimumSpace = 180;
            const availableSpace =
                window.innerHeight - pickerRect.bottom - 12;

            if (availableSpace < minimumSpace && pickerRect.top > minimumSpace) {
                window.scrollBy({
                    top: pickerRect.bottom - (window.innerHeight - minimumSpace),
                    behavior: 'instant'
                });
            }

            const rect =
                departmentPickerButton.getBoundingClientRect();
            const spaceBelow =
                Math.max(120, window.innerHeight - rect.bottom - 12);

            departmentPickerMenu.style.left = `${rect.left}px`;
            departmentPickerMenu.style.top = `${rect.bottom + 4}px`;
            departmentPickerMenu.style.width = `${rect.width}px`;
            departmentPickerMenu.style.maxHeight = `${Math.min(260, spaceBelow)}px`;
        };

        const closeDepartmentMenu = (restoreFocus = false) => {
            departmentPickerMenu.classList.remove('is-open');
            departmentPickerButton.setAttribute('aria-expanded', 'false');

            if (restoreFocus) {
                departmentPickerButton.focus();
            }
        };

        const openDepartmentMenu = () => {
            positionDepartmentMenu();
            departmentPickerMenu.classList.add('is-open');
            departmentPickerButton.setAttribute('aria-expanded', 'true');

            const selectedOption =
                departmentOptions.find(
                    (option) => option.dataset.value === departmentSelect.value
                );

            (selectedOption || departmentOptions[0])?.focus();
        };

        departmentPickerButton.addEventListener('click', () => {
            if (departmentPickerMenu.classList.contains('is-open')) {
                closeDepartmentMenu();
            } else {
                openDepartmentMenu();
            }
        });

        departmentPickerButton.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                openDepartmentMenu();
            }
        });

        departmentOptions.forEach((option, index) => {
            option.addEventListener('click', () => {
                departmentSelect.value = option.dataset.value;
                departmentSelect.dispatchEvent(
                    new Event('change', { bubbles: true })
                );
                closeDepartmentMenu(true);
            });

            option.addEventListener('keydown', (event) => {
                let nextIndex = index;

                if (event.key === 'ArrowDown') {
                    nextIndex = (index + 1) % departmentOptions.length;
                } else if (event.key === 'ArrowUp') {
                    nextIndex = (index - 1 + departmentOptions.length) % departmentOptions.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = departmentOptions.length - 1;
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeDepartmentMenu(true);
                    return;
                } else if (event.key === 'Tab') {
                    closeDepartmentMenu();
                    return;
                } else {
                    return;
                }

                event.preventDefault();
                departmentOptions[nextIndex].focus();
            });
        });

        departmentSelect.addEventListener('change', syncDepartmentSelection);
        departmentSelect.addEventListener('invalid', (event) => {
            event.preventDefault();
            openDepartmentMenu();
            departmentPickerButton.focus();
        });

        document.addEventListener('click', (event) => {
            if (
                !departmentPicker.contains(event.target) &&
                !departmentPickerMenu.contains(event.target)
            ) {
                closeDepartmentMenu();
            }
        });

        window.addEventListener('resize', () => {
            if (departmentPickerMenu.classList.contains('is-open')) {
                positionDepartmentMenu();
            }
        });

        window.addEventListener('scroll', () => {
            if (departmentPickerMenu.classList.contains('is-open')) {
                positionDepartmentMenu();
            }
        }, true);

        syncDepartmentSelection();
    }



    /* ==================================================
       FILE NAME DISPLAY
    ================================================== */

    const documentFile =
        document.getElementById(
            'document-file'
        );

    const uploadFileName =
        document.getElementById(
            'upload-file-name'
        );


    if (
        documentFile &&
        uploadFileName
    ) {

        documentFile.addEventListener(
            'change',
            () => {

                if (
                    documentFile.files &&
                    documentFile.files.length > 0
                ) {

                    uploadFileName.textContent =
                        documentFile.files[0].name;

                }

                else {

                    uploadFileName.textContent =
                        'No file selected';

                }

            }
        );

    }

</script>

<?php if ($createdQrCode !== ''): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const createdQrImage = document.getElementById('createdQrImage');
        if (typeof QRCode === 'function' && createdQrImage) {
            new QRCode(createdQrImage, {
                text: createdQrImage.dataset.qrCode,
                width: 132,
                height: 132,
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            document.getElementById('createdQrError').hidden = false;
        }

        const createdPrintQrImage = document.getElementById('createdPrintQrImage');
        if (typeof QRCode === 'function' && createdPrintQrImage) {
            new QRCode(createdPrintQrImage, {
                text: createdPrintQrImage.dataset.qrCode,
                width: 170,
                height: 170,
                correctLevel: QRCode.CorrectLevel.M
            });
        }

        document.getElementById('printCreatedDocument').addEventListener('click', function () {
            document.body.classList.add('print-created-document');
            window.print();
        });
        window.addEventListener('afterprint', function () {
            document.body.classList.remove('print-created-document');
        });
    </script>
<?php endif; ?>


</body>

</html>
```
