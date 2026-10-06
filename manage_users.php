<?php
require_once __DIR__ . '/session_config.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require 'db.php';

$message = '';
$messageType = 'success';

$userName = $_SESSION['user_name'] ?? 'Admin';
$userEmail = $_SESSION['user_email'] ?? 'admin@doctrack.local';
$userDepartment = $_SESSION['user_department'] ?? 'Administration';


/* =========================================================
   UPDATE USER
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {

    $userId = (int)($_POST['user_id'] ?? 0);
    $employeeId = trim($_POST['employee_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $username = trim($_POST['username'] ?? '');

    if (
        $userId <= 0 ||
        $employeeId === '' ||
        $firstName === '' ||
        $lastName === '' ||
        $contactNumber === '' ||
        $email === '' ||
        $department === '' ||
        $position === '' ||
        $username === ''
    ) {

        $message = 'Please fill in all fields.';
        $messageType = 'error';

    } else {

        $checkStmt = $conn->prepare(
            'SELECT id
             FROM users
             WHERE (employee_id = ? OR email = ? OR username = ?)
             AND id != ?
             LIMIT 1'
        );

        $checkStmt->bind_param(
            'sssi',
            $employeeId,
            $email,
            $username,
            $userId
        );

        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {

            $message = 'Employee ID, email, or username is already in use.';
            $messageType = 'error';

        } else {

            $updateStmt = $conn->prepare(
                'UPDATE users
                 SET employee_id = ?,
                     first_name = ?,
                     last_name = ?,
                     contact_number = ?,
                     email = ?,
                     department = ?,
                     position = ?,
                     username = ?
                 WHERE id = ?'
            );

            $updateStmt->bind_param(
                'ssssssssi',
                $employeeId,
                $firstName,
                $lastName,
                $contactNumber,
                $email,
                $department,
                $position,
                $username,
                $userId
            );

            if ($updateStmt->execute()) {

                $message = 'User information updated successfully.';
                $messageType = 'success';

            } else {

                $message = 'Failed to update user information.';
                $messageType = 'error';
            }
        }
    }
}


/* =========================================================
   GET USER FOR EDITING
========================================================= */

$editUser = null;

if (isset($_GET['edit_id'])) {

    $editId = (int)$_GET['edit_id'];

    $editStmt = $conn->prepare(
        'SELECT id,
                employee_id,
                first_name,
                last_name,
                contact_number,
                email,
                department,
                position,
                username
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $editStmt->bind_param('i', $editId);

    $editStmt->execute();

    $editResult = $editStmt->get_result();

    $editUser = $editResult->fetch_assoc();
}


/* =========================================================
   GET ALL USERS
========================================================= */

$usersStmt = $conn->prepare(
    'SELECT id,
            employee_id,
            first_name,
            last_name,
            contact_number,
            email,
            department,
            position,
            username
     FROM users
     ORDER BY first_name ASC'
);

$usersStmt->execute();

$usersResult = $usersStmt->get_result();

$users = $usersResult->fetch_all(MYSQLI_ASSOC);

$totalUsers = count($users);

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Manage Users | DocTrack</title>


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

        :root {

            --primary: #087a45;
            --primary-dark: #055c34;
            --primary-light: #e9f7ef;
            --primary-soft: #f4fbf6;

            --gold: #d8a62a;

            --text: #17231c;
            --text-light: #68756e;

            --white: #ffffff;
            --background: #f5f8f6;

            --border: #e1e9e4;

            --danger: #d94a4a;
            --danger-light: #fff1f1;

            --shadow-sm:
                0 4px 14px rgba(18, 44, 31, 0.05);

            --shadow-md:
                0 12px 35px rgba(18, 44, 31, 0.08);

            --sidebar-width: 68px;
            --sidebar-expanded-width: 250px;
        }


        /* =====================================================
           RESET
        ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {

            font-family: "Inter", Arial, sans-serif;

            background: var(--background);

            color: var(--text);

            min-height: 100vh;
        }

        button,
        input {
            font-family: inherit;
        }

        a {
            text-decoration: none;
            color: inherit;
        }


        /* =====================================================
           SIDEBAR
        ===================================================== */

        .dashboard-sidebar {

            position: fixed;

            left: 0;
            top: 0;

            width: var(--sidebar-width);
            height: 100vh;

            background:
                linear-gradient(
                    180deg,
                    #075f36 0%,
                    #064e2f 100%
                );

            color: white;

            z-index: 1000;

            box-shadow:
                8px 0 30px rgba(0, 0, 0, 0.08);

            overflow-x: hidden;

            transition:
                width 0.25s ease,
                transform 0.3s ease;
        }

        .dashboard-sidebar:hover,
        .dashboard-sidebar:focus-within,
        .dashboard-sidebar.is-open {
            width: var(--sidebar-expanded-width);
        }

        .sidebar-brand {

            min-height: 95px;

            padding: 22px 10px;

            display: flex;

            align-items: center;
            justify-content: center;

            gap: 13px;

            border-bottom:
                1px solid rgba(255,255,255,0.10);
        }

        .sidebar-brand > div:not(.mini-logo) {
            display: none;
        }

        .dashboard-sidebar:hover .sidebar-brand,
        .dashboard-sidebar:focus-within .sidebar-brand,
        .dashboard-sidebar.is-open .sidebar-brand {
            justify-content: flex-start;
            padding-left: 20px;
            padding-right: 20px;
        }

        .dashboard-sidebar:hover .sidebar-brand > div:not(.mini-logo),
        .dashboard-sidebar:focus-within .sidebar-brand > div:not(.mini-logo),
        .dashboard-sidebar.is-open .sidebar-brand > div:not(.mini-logo) {
            display: block;
        }


        .mini-logo {

            width: 48px;
            height: 48px;

            border-radius: 14px;

            background:
                rgba(255,255,255,0.14);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 15px;

            font-weight: 800;

            border:
                1px solid rgba(255,255,255,0.16);
        }


        .sidebar-brand strong {

            display: block;

            font-size: 15px;

            font-weight: 800;
        }


        .sidebar-brand small {

            display: block;

            margin-top: 4px;

            color:
                rgba(255,255,255,0.68);

            font-size: 11px;
        }


        .sidebar-nav {

            padding: 20px 8px;

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

            border: 0;

            border-radius: 12px;

            background: transparent;

            color:
                rgba(255,255,255,0.76);

            font-size: 0;

            font-weight: 600;

            cursor: pointer;

            transition:
                background 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .dashboard-sidebar:hover .sidebar-nav,
        .dashboard-sidebar:focus-within .sidebar-nav,
        .dashboard-sidebar.is-open .sidebar-nav {
            padding-left: 13px;
            padding-right: 13px;
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
                rgba(255,255,255,0.10);

            color: white;

            transform:
                translateX(2px);
        }


        .nav-item.active {

            background: white;

            color: var(--primary);

            box-shadow:
                0 6px 18px rgba(0,0,0,0.08);
        }

        .nav-subitem {
            display: none;
            min-height: 38px;
            padding: 9px 12px 9px 47px;
            border-radius: 9px;
            color: rgba(255,255,255,0.7);
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

        .documents-nav-group:hover .nav-subitem span,
        .documents-nav-group:focus-within .nav-subitem span {
            display: inline;
        }

        .nav-subitem:hover,
        .nav-subitem.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }


        .nav-logout {

            margin-top: 18px;

            color: #ffd1d1;
        }


        .nav-logout:hover {

            background:
                rgba(217,74,74,0.15);

            color: white;
        }


        /* =====================================================
           MAIN
        ===================================================== */

        .dashboard-page {

            min-height: 100vh;

            margin-left:
                var(--sidebar-width);

            padding: 28px;
        }


        .dashboard-container {

            max-width: 1450px;

            margin: auto;
        }


        /* =====================================================
           TOP HEADER
        ===================================================== */

        .top-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 24px;
        }


        .header-left {

            display: flex;

            align-items: center;

            gap: 15px;
        }


        .mobile-menu {

            display: none;

            width: 43px;
            height: 43px;

            border:
                1px solid var(--border);

            border-radius: 12px;

            background: white;

            color: var(--primary);

            cursor: pointer;

            font-size: 17px;
        }


        .page-heading h1 {

            font-size: 27px;

            font-weight: 800;

            letter-spacing:
                -0.6px;
        }


        .page-heading p {

            margin-top: 6px;

            color:
                var(--text-light);

            font-size: 13px;
        }


        .admin-chip {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 8px 12px 8px 8px;

            background: white;

            border:
                1px solid var(--border);

            border-radius: 15px;

            box-shadow:
                var(--shadow-sm);
        }


        .admin-avatar-small {

            width: 36px;
            height: 36px;

            border-radius: 11px;

            background:
                var(--primary-light);

            color:
                var(--primary);

            display: flex;

            align-items: center;
            justify-content: center;

            font-weight: 800;
        }


        .admin-chip strong {

            display: block;

            font-size: 12px;
        }


        .admin-chip span {

            display: block;

            margin-top: 2px;

            color:
                var(--text-light);

            font-size: 10px;
        }


        /* =====================================================
           MAIN CARD
        ===================================================== */

        .dashboard-card {

            background: white;

            border:
                1px solid var(--border);

            border-radius: 20px;

            box-shadow:
                var(--shadow-sm);

            overflow: hidden;
        }


        .card-top {

            padding: 22px 25px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            border-bottom:
                1px solid var(--border);

            position: relative;
        }


        .card-top::before {

            content: "";

            position: absolute;

            top: 0;
            left: 0;
            right: 0;

            height: 4px;

            background:
                linear-gradient(
                    90deg,
                    var(--primary),
                    var(--gold)
                );
        }


        .card-title {

            display: flex;

            align-items: center;

            gap: 13px;
        }


        .card-icon {

            width: 43px;
            height: 43px;

            border-radius: 12px;

            background:
                var(--primary-light);

            color:
                var(--primary);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 16px;
        }


        .card-title h2 {

            font-size: 17px;

            font-weight: 800;
        }


        .card-title p {

            margin-top: 4px;

            color:
                var(--text-light);

            font-size: 11px;
        }


        .user-count {

            padding: 9px 13px;

            border-radius: 10px;

            background:
                var(--primary-light);

            color:
                var(--primary);

            font-size: 11px;

            font-weight: 700;
        }


        /* =====================================================
           ALERT MESSAGE
        ===================================================== */

        .status-message {

            margin: 20px 25px 0;

            padding: 13px 15px;

            border-radius: 11px;

            font-size: 12px;

            font-weight: 600;

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .status-message.success {

            background:
                #eaf8ef;

            color:
                #147744;

            border:
                1px solid #cfeadb;
        }


        .status-message.success::before {

            content: "\f058";

            font-family:
                "Font Awesome 6 Free";

            font-weight: 900;
        }


        .status-message.error {

            background:
                var(--danger-light);

            color:
                #b73535;

            border:
                1px solid #f1d0d0;
        }


        .status-message.error::before {

            content: "\f06a";

            font-family:
                "Font Awesome 6 Free";

            font-weight: 900;
        }


        /* =====================================================
           TABLE TOOLBAR
        ===================================================== */

        .table-toolbar {

            padding: 20px 25px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;
        }


        .table-toolbar-text {

            color:
                var(--text-light);

            font-size: 11px;
        }


        .search-box {

            position: relative;
        }


        .search-box i {

            position: absolute;

            left: 13px;

            top: 50%;

            transform:
                translateY(-50%);

            color: #98a39d;

            font-size: 12px;
        }


        .search-box input {

            width: 245px;

            height: 40px;

            padding:
                0 13px 0 35px;

            border:
                1px solid var(--border);

            border-radius: 11px;

            background:
                #fbfcfb;

            outline: none;

            font-size: 11px;

            transition:
                border 0.2s ease,
                box-shadow 0.2s ease;
        }


        .search-box input:focus {

            border-color:
                var(--primary);

            box-shadow:
                0 0 0 3px
                rgba(8,122,69,0.08);
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .table-wrapper {

            width: 100%;

            overflow-x: auto;
        }


        .doc-table {

            width: 100%;

            border-collapse:
                collapse;

            min-width: 1050px;
        }


        .doc-table th {

            padding:
                13px 18px;

            background:
                #f8faf9;

            color:
                #64716a;

            text-align: left;

            font-size: 10px;

            font-weight: 800;

            text-transform:
                uppercase;

            letter-spacing:
                0.5px;

            border-bottom:
                1px solid var(--border);

            white-space: nowrap;
        }


        .doc-table td {

            padding:
                14px 18px;

            border-bottom:
                1px solid #edf1ee;

            color:
                #39443e;

            font-size: 11px;

            font-weight: 500;

            white-space: nowrap;
        }


        .doc-table tbody tr {

            transition:
                background 0.2s ease;
        }


        .doc-table tbody tr:hover {

            background:
                #f8fcf9;
        }


        .doc-table tbody tr:last-child td {

            border-bottom: 0;
        }


        .employee-id {

            color:
                var(--primary);

            font-weight: 800;
        }


        .user-name-cell {

            display: flex;

            align-items: center;

            gap: 10px;
        }


        .table-avatar {

            width: 34px;
            height: 34px;

            flex-shrink: 0;

            border-radius: 10px;

            background:
                var(--primary-light);

            color:
                var(--primary);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 11px;

            font-weight: 800;
        }


        .name-text strong {

            display: block;

            color:
                var(--text);

            font-size: 11px;
        }


        .name-text small {

            display: block;

            margin-top: 2px;

            color:
                var(--text-light);

            font-size: 9px;
        }


        .badge {

            display: inline-flex;

            align-items: center;

            padding:
                5px 9px;

            border-radius:
                20px;

            background:
                var(--primary-light);

            color:
                var(--primary);

            font-size: 9px;

            font-weight: 700;
        }


        .position-badge {

            background:
                #f1f4f2;

            color:
                #56625c;
        }


        /* =====================================================
           EDIT BUTTON
        ===================================================== */

        .edit-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 6px;

            padding:
                8px 12px;

            border-radius: 9px;

            background:
                var(--primary-light);

            color:
                var(--primary);

            font-size: 10px;

            font-weight: 700;

            transition:
                all 0.2s ease;
        }


        .edit-button:hover {

            background:
                var(--primary);

            color: white;

            transform:
                translateY(-1px);
        }


        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .empty-state {

            text-align: center !important;

            padding:
                65px 20px !important;

            color:
                var(--text-light) !important;
        }


        .empty-state i {

            display: block;

            margin-bottom: 12px;

            color:
                #adb8b1;

            font-size: 30px;
        }


        /* =====================================================
           EDIT FORM
        ===================================================== */

        .edit-container {

            padding:
                25px;
        }


        .back-button {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            margin-bottom: 22px;

            color:
                var(--primary);

            font-size: 11px;

            font-weight: 700;
        }


        .back-button:hover {

            color:
                var(--primary-dark);
        }


        .back-button i {

            width: 31px;
            height: 31px;

            border-radius: 9px;

            background:
                var(--primary-light);

            display: flex;

            align-items: center;
            justify-content: center;

            transition:
                transform 0.2s ease;
        }


        .back-button:hover i {

            transform:
                translateX(-3px);
        }


        .edit-heading {

            margin-bottom: 23px;

            padding-bottom: 18px;

            border-bottom:
                1px solid var(--border);
        }


        .edit-heading h2 {

            font-size: 20px;

            font-weight: 800;
        }


        .edit-heading p {

            margin-top: 5px;

            color:
                var(--text-light);

            font-size: 11px;
        }


        .auth-form {

            max-width: 900px;
        }


        .form-row {

            display: grid;

            grid-template-columns:
                1fr 1fr;

            gap: 18px;

            margin-bottom: 18px;
        }


        .field-group {

            display: flex;

            flex-direction: column;
        }


        .field-group label {

            margin-bottom: 7px;

            color:
                #4d5a53;

            font-size: 11px;

            font-weight: 700;
        }


        .field-group input {

            width: 100%;

            height: 45px;

            padding:
                0 13px;

            border:
                1px solid var(--border);

            border-radius: 11px;

            background:
                #fbfcfb;

            color:
                var(--text);

            font-size: 12px;

            outline: none;

            transition:
                border 0.2s ease,
                box-shadow 0.2s ease,
                background 0.2s ease;
        }


        .field-group input:focus {

            background: white;

            border-color:
                var(--primary);

            box-shadow:
                0 0 0 3px
                rgba(8,122,69,0.08);
        }


        .update-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            gap: 8px;

            min-width: 155px;

            height: 45px;

            margin-top: 8px;

            padding:
                0 18px;

            border: 0;

            border-radius: 11px;

            background:
                linear-gradient(
                    135deg,
                    var(--primary),
                    #0a9656
                );

            color: white;

            font-size: 12px;

            font-weight: 700;

            cursor: pointer;

            box-shadow:
                0 8px 18px
                rgba(8,122,69,0.16);

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }


        .update-button:hover {

            transform:
                translateY(-2px);

            box-shadow:
                0 11px 23px
                rgba(8,122,69,0.22);
        }


        .update-button:active {

            transform:
                translateY(0);
        }


        /* =====================================================
           MOBILE OVERLAY
        ===================================================== */

        .sidebar-overlay {

            display: none;

            position: fixed;

            inset: 0;

            background:
                rgba(0,0,0,0.35);

            z-index: 999;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 900px) {

            .dashboard-sidebar {

                transform:
                    translateX(-100%);

                width: var(--sidebar-expanded-width);
            }


            .dashboard-sidebar.is-open {

                transform:
                    translateX(0);
            }

            .dashboard-sidebar .sidebar-brand {
                justify-content: flex-start;
                padding-left: 20px;
                padding-right: 20px;
            }

            .dashboard-sidebar .sidebar-brand > div:not(.mini-logo) {
                display: block;
            }

            .dashboard-sidebar .sidebar-nav {
                padding-left: 13px;
                padding-right: 13px;
            }

            .dashboard-sidebar .nav-item {
                justify-content: flex-start;
                gap: 13px;
                padding: 13px 14px;
                font-size: 13px;
            }


            .sidebar-overlay.is-open {

                display: block;
            }


            .dashboard-page {

                margin-left: 0;

                padding: 20px;
            }


            .mobile-menu {

                display: flex;

                align-items: center;

                justify-content: center;
            }


            .admin-chip {

                display: none;
            }
        }


        @media (max-width: 700px) {

            .dashboard-page {

                padding: 15px;
            }


            .page-heading h1 {

                font-size: 23px;
            }


            .page-heading p {

                font-size: 11px;
            }


            .card-top {

                padding:
                    19px;
            }


            .table-toolbar {

                padding:
                    17px 19px;

                align-items:
                    flex-start;

                flex-direction:
                    column;
            }


            .search-box {

                width: 100%;
            }


            .search-box input {

                width: 100%;
            }


            .edit-container {

                padding:
                    20px;
            }


            .form-row {

                grid-template-columns:
                    1fr;

                gap: 16px;

                margin-bottom: 16px;
            }


            .update-button {

                width: 100%;
            }
        }


        @media (max-width: 450px) {

            .page-heading h1 {

                font-size: 21px;
            }


            .card-title h2 {

                font-size: 15px;
            }


            .card-title p {

                font-size: 10px;
            }


            .user-count {

                font-size: 10px;

                padding:
                    8px 10px;
            }


            .card-icon {

                width: 39px;
                height: 39px;

                font-size: 14px;
            }
        }


        /* =====================================================
           ACCESSIBILITY
        ===================================================== */

        button:focus-visible,
        a:focus-visible,
        input:focus-visible {

            outline:
                3px solid
                rgba(8,122,69,0.25);

            outline-offset: 2px;
        }


        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {

                transition: none !important;

                scroll-behavior: auto !important;
            }
        }

    </style>

</head>


<body>


    <!-- =====================================================
         SIDEBAR OVERLAY
    ====================================================== -->

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside
        class="dashboard-sidebar"
        id="sidebar"
        aria-label="Admin navigation"
    >

        <div class="sidebar-brand">

            <div class="mini-logo">
                AD
            </div>

            <div>

                <strong>
                    Administrator
                </strong>

                <small>
                    DocTrack Management
                </small>

            </div>

        </div>


        <nav class="sidebar-nav">

            <a
                href="admin.php"
                class="nav-item"
            >

                <i class="fa-solid fa-chart-pie"></i>

                <span>
                    Admin Dashboard
                </span>

            </a>


            <a
                href="manage_users.php"
                class="nav-item active"
            >

                <i class="fa-solid fa-users"></i>

                <span>
                    Manage Users
                </span>

            </a>


            <div class="documents-nav-group">
                <a
                    href="my_documents.php"
                    class="nav-item"
                >

                    <i class="fa-solid fa-file-lines"></i>

                    <span>
                        Documents
                    </span>

                </a>

                <a href="received_documents.php" class="nav-subitem">
                    <i class="fa-solid fa-inbox"></i>
                    <span>Received Documents</span>
                </a>
            </div>

            <a
                href="track_document.php"
                class="nav-item"
            >

                <i class="fa-solid fa-location-dot"></i>

                <span>
                    Track Document
                </span>

            </a>


            <a
                href="#"
                class="nav-item"
            >

                <i class="fa-solid fa-chart-column"></i>

                <span>
                    Reports
                </span>

            </a>


            <a
                href="#"
                class="nav-item"
            >

                <i class="fa-solid fa-gear"></i>

                <span>
                    Settings
                </span>

            </a>


            <form
                method="post"
                action="logout.php"
                id="logoutForm"
            >

                <button
                    type="button"
                    class="nav-item nav-logout"
                    id="logoutButton"
                >

                    <i
                        class="fa-solid fa-right-from-bracket"
                    ></i>

                    <span>
                        Log Out
                    </span>

                </button>

            </form>

        </nav>

    </aside>


    <!-- =====================================================
         MAIN PAGE
    ====================================================== -->

    <div class="dashboard-page">

        <div class="dashboard-container">


            <!-- =================================================
                 HEADER
            ================================================== -->

            <header class="top-header">

                <div class="header-left">

                    <button
                        class="mobile-menu"
                        id="mobileMenu"
                        type="button"
                        aria-label="Open navigation"
                    >

                        <i class="fa-solid fa-bars"></i>

                    </button>


                    <div class="page-heading">

                        <h1>
                            Manage Users
                        </h1>

                        <p>
                            View, search, and update registered user accounts.
                        </p>

                    </div>

                </div>


                <div class="admin-chip">

                    <div class="admin-avatar-small">

                        <?php
                        echo strtoupper(
                            substr($userName, 0, 1)
                        );
                        ?>

                    </div>


                    <div>

                        <strong>

                            <?php
                            echo htmlspecialchars(
                                $userName,
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>

                        </strong>

                        <span>
                            Administrator
                        </span>

                    </div>

                </div>

            </header>


            <!-- =================================================
                 MAIN CARD
            ================================================== -->

            <section class="dashboard-card">


                <!-- CARD HEADER -->

                <div class="card-top">

                    <div class="card-title">

                        <div class="card-icon">

                            <i class="fa-solid fa-users-gear"></i>

                        </div>


                        <div>

                            <h2>
                                User Management
                            </h2>

                            <p>
                                Manage registered DocTrack accounts.
                            </p>

                        </div>

                    </div>


                    <div class="user-count">

                        <?php echo $totalUsers; ?>

                        <?php
                        echo $totalUsers === 1
                            ? ' User'
                            : ' Users';
                        ?>

                    </div>

                </div>


                <!-- STATUS MESSAGE -->

                <?php if ($message !== ''): ?>

                    <div
                        class="status-message <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"
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


                <?php if ($editUser): ?>


                    <!-- =================================================
                         EDIT USER
                    ================================================== -->

                    <div class="edit-container">


                        <a
                            href="manage_users.php"
                            class="back-button"
                        >

                            <i class="fa-solid fa-arrow-left"></i>

                            <span>
                                Back to User List
                            </span>

                        </a>


                        <div class="edit-heading">

                            <h2>
                                Edit User Information
                            </h2>

                            <p>
                                Update the user's account and personal information below.
                            </p>

                        </div>


                        <form
                            method="post"
                            action="manage_users.php"
                            class="auth-form"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="update_user"
                            >


                            <input
                                type="hidden"
                                name="user_id"
                                value="<?php echo (int)$editUser['id']; ?>"
                            >


                            <!-- ROW 1 -->

                            <div class="form-row">

                                <div class="field-group">

                                    <label for="employee-id">
                                        Employee ID
                                    </label>

                                    <input
                                        id="employee-id"
                                        type="text"
                                        name="employee_id"
                                        value="<?php echo htmlspecialchars($editUser['employee_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>


                                <div class="field-group">

                                    <label for="username">
                                        Username
                                    </label>

                                    <input
                                        id="username"
                                        type="text"
                                        name="username"
                                        value="<?php echo htmlspecialchars($editUser['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- ROW 2 -->

                            <div class="form-row">

                                <div class="field-group">

                                    <label for="first-name">
                                        First Name
                                    </label>

                                    <input
                                        id="first-name"
                                        type="text"
                                        name="first_name"
                                        value="<?php echo htmlspecialchars($editUser['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>


                                <div class="field-group">

                                    <label for="last-name">
                                        Last Name
                                    </label>

                                    <input
                                        id="last-name"
                                        type="text"
                                        name="last_name"
                                        value="<?php echo htmlspecialchars($editUser['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- ROW 3 -->

                            <div class="form-row">

                                <div class="field-group">

                                    <label for="contact-number">
                                        Contact Number
                                    </label>

                                    <input
                                        id="contact-number"
                                        type="tel"
                                        name="contact_number"
                                        value="<?php echo htmlspecialchars($editUser['contact_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>


                                <div class="field-group">

                                    <label for="email">
                                        Email Address
                                    </label>

                                    <input
                                        id="email"
                                        type="email"
                                        name="email"
                                        value="<?php echo htmlspecialchars($editUser['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- ROW 4 -->

                            <div class="form-row">

                                <div class="field-group">

                                    <label for="department">
                                        Department / Office
                                    </label>

                                    <input
                                        id="department"
                                        type="text"
                                        name="department"
                                        value="<?php echo htmlspecialchars($editUser['department'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>


                                <div class="field-group">

                                    <label for="position">
                                        Position
                                    </label>

                                    <input
                                        id="position"
                                        type="text"
                                        name="position"
                                        value="<?php echo htmlspecialchars($editUser['position'], ENT_QUOTES, 'UTF-8'); ?>"
                                        required
                                    >

                                </div>

                            </div>


                            <button
                                type="submit"
                                class="update-button"
                            >

                                <i class="fa-solid fa-floppy-disk"></i>

                                Update User

                            </button>

                        </form>

                    </div>


                <?php else: ?>


                    <!-- =================================================
                         USER LIST
                    ================================================== -->

                    <div class="table-toolbar">

                        <div class="table-toolbar-text">

                            Showing
                            <strong>
                                <?php echo $totalUsers; ?>
                            </strong>
                            registered
                            <?php echo $totalUsers === 1 ? 'user' : 'users'; ?>.

                        </div>


                        <div class="search-box">

                            <i class="fa-solid fa-magnifying-glass"></i>

                            <input
                                type="text"
                                id="userSearch"
                                placeholder="Search users..."
                                autocomplete="off"
                            >

                        </div>

                    </div>


                    <div class="table-wrapper">

                        <table class="doc-table">

                            <thead>

                                <tr>

                                    <th>
                                        Employee ID
                                    </th>

                                    <th>
                                        Name
                                    </th>

                                    <th>
                                        Contact
                                    </th>

                                    <th>
                                        Email
                                    </th>

                                    <th>
                                        Department
                                    </th>

                                    <th>
                                        Position
                                    </th>

                                    <th>
                                        Username
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody id="userTableBody">


                                <?php if (empty($users)): ?>

                                    <tr>

                                        <td
                                            colspan="8"
                                            class="empty-state"
                                        >

                                            <i
                                                class="fa-solid fa-users-slash"
                                            ></i>

                                            No users registered yet.

                                        </td>

                                    </tr>


                                <?php else: ?>


                                    <?php foreach ($users as $user): ?>

                                        <?php

                                        $fullName =
                                            $user['first_name'] .
                                            ' ' .
                                            $user['last_name'];

                                        $initial =
                                            strtoupper(
                                                substr(
                                                    $user['first_name'],
                                                    0,
                                                    1
                                                )
                                            );

                                        ?>


                                        <tr>


                                            <!-- EMPLOYEE ID -->

                                            <td>

                                                <span class="employee-id">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $user['employee_id'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                    ?>

                                                </span>

                                            </td>


                                            <!-- NAME -->

                                            <td>

                                                <div class="user-name-cell">

                                                    <div class="table-avatar">

                                                        <?php
                                                        echo $initial;
                                                        ?>

                                                    </div>


                                                    <div class="name-text">

                                                        <strong>

                                                            <?php
                                                            echo htmlspecialchars(
                                                                $fullName,
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            );
                                                            ?>

                                                        </strong>


                                                        <small>

                                                            <?php
                                                            echo htmlspecialchars(
                                                                $user['username'],
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            );
                                                            ?>

                                                        </small>

                                                    </div>

                                                </div>

                                            </td>


                                            <!-- CONTACT -->

                                            <td>

                                                <?php
                                                echo htmlspecialchars(
                                                    $user['contact_number'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>

                                            </td>


                                            <!-- EMAIL -->

                                            <td>

                                                <?php
                                                echo htmlspecialchars(
                                                    $user['email'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>

                                            </td>


                                            <!-- DEPARTMENT -->

                                            <td>

                                                <span class="badge">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $user['department'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                    ?>

                                                </span>

                                            </td>


                                            <!-- POSITION -->

                                            <td>

                                                <span class="badge position-badge">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $user['position'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                    ?>

                                                </span>

                                            </td>


                                            <!-- USERNAME -->

                                            <td>

                                                <?php
                                                echo htmlspecialchars(
                                                    $user['username'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>

                                            </td>


                                            <!-- ACTION -->

                                            <td>

                                                <a
                                                    href="manage_users.php?edit_id=<?php echo (int)$user['id']; ?>"
                                                    class="edit-button"
                                                >

                                                    <i
                                                        class="fa-solid fa-pen"
                                                    ></i>

                                                    Edit

                                                </a>

                                            </td>


                                        </tr>


                                    <?php endforeach; ?>


                                <?php endif; ?>


                            </tbody>

                        </table>

                    </div>


                <?php endif; ?>


            </section>

        </div>

    </div>


    <script>

        /* =====================================================
           MOBILE SIDEBAR
        ===================================================== */

        const sidebar =
            document.getElementById("sidebar");

        const mobileMenu =
            document.getElementById("mobileMenu");

        const sidebarOverlay =
            document.getElementById("sidebarOverlay");


        function openSidebar() {

            sidebar.classList.add("is-open");

            sidebarOverlay.classList.add("is-open");

        }


        function closeSidebar() {

            sidebar.classList.remove("is-open");

            sidebarOverlay.classList.remove("is-open");

        }


        if (mobileMenu) {

            mobileMenu.addEventListener(
                "click",
                openSidebar
            );

        }


        if (sidebarOverlay) {

            sidebarOverlay.addEventListener(
                "click",
                closeSidebar
            );

        }


        /* =====================================================
           SEARCH USERS
        ===================================================== */

        const searchInput =
            document.getElementById("userSearch");

        const userTableBody =
            document.getElementById("userTableBody");


        if (searchInput && userTableBody) {

            searchInput.addEventListener(
                "input",
                function () {

                    const searchValue =
                        this.value
                            .toLowerCase()
                            .trim();


                    const rows =
                        userTableBody.querySelectorAll(
                            "tr"
                        );


                    rows.forEach(function (row) {

                        const rowText =
                            row.textContent
                                .toLowerCase();


                        if (
                            rowText.includes(
                                searchValue
                            )
                        ) {

                            row.style.display = "";

                        } else {

                            row.style.display =
                                "none";

                        }

                    });

                }
            );

        }


        /* =====================================================
           LOGOUT CONFIRMATION
        ===================================================== */

        const logoutButton =
            document.getElementById(
                "logoutButton"
            );

        const logoutForm =
            document.getElementById(
                "logoutForm"
            );


        if (logoutButton && logoutForm) {

            logoutButton.addEventListener(
                "click",
                function () {

                    const confirmed =
                        confirm(
                            "Are you sure you want to log out?"
                        );


                    if (confirmed) {

                        logoutForm.submit();

                    }

                }
            );

        }


        /* =====================================================
           ESC KEY
        ===================================================== */

        document.addEventListener(
            "keydown",
            function (event) {

                if (event.key === "Escape") {

                    closeSidebar();

                }

            }
        );

    </script>

</body>
</html>