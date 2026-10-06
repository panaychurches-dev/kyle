<?php

require_once __DIR__ . '/session_config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['user_email']) || !isset($_SESSION['user_department'])) {
    require 'db.php';

    $profileStmt = $conn->prepare(
        'SELECT email, department FROM users WHERE id = ? LIMIT 1'
    );

    $profileStmt->bind_param('i', $_SESSION['user_id']);
    $profileStmt->execute();

    $profileResult = $profileStmt->get_result();
    $profile = $profileResult->fetch_assoc();

    if ($profile) {
        $_SESSION['user_email'] = $profile['email'];
        $_SESSION['user_department'] = $profile['department'];
    }
}

$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? 'No email available';
$userDepartment = $_SESSION['user_department'] ?? 'Department not set';

$userInitial = strtoupper(substr(trim($userName), 0, 1));

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | DocTrack</title>

    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <!-- Icons -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>

        /* ==============================
           GENERAL
        ============================== */

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

        button,
        a {
            font-family: inherit;
        }

        a {
            text-decoration: none;
        }


        /* ==============================
           LAYOUT
        ============================== */

        .dashboard-shell {
            min-height: 100vh;
            display: flex;
        }


        /* ==============================
           SIDEBAR
        ============================== */

        .dashboard-sidebar {
            width: 68px;
            min-height: 100vh;
            background: #0b3d2e;
            color: white;
            padding: 24px 8px;
            position: fixed;
            left: 0;
            top: 0;
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
            border-bottom: 1px solid rgba(255,255,255,0.12);
            margin-bottom: 22px;
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
            color: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }

        .sidebar-brand strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
        }

        .sidebar-brand small {
            display: block;
            color: #a9c8b7;
            font-size: 12px;
            margin-top: 3px;
        }


        /* ==============================
           NAVIGATION
        ============================== */

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
            background: rgba(255,255,255,0.08);
            color: white;
            transform: translateX(2px);
        }

        .nav-item.active {
            background: #2e9d63;

            color: white;
            box-shadow: 0 5px 15px rgba(46,157,99,0.25);
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

        .nav-logout:hover {
            background: rgba(255, 90, 90, 0.1);
            color: #ffdddd;
        }


        /* ==============================
           MAIN CONTENT
        ============================== */

        .dashboard-page {
            width: calc(100% - 68px);
            margin-left: 68px;
            min-height: 100vh;
            padding: 32px;
        }

        .dashboard-container {
            max-width: 1400px;
            margin: auto;
        }


        /* ==============================
           HEADER
        ============================== */

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

        .header-title h1 {
            font-size: 27px;
            font-weight: 800;
            color: #123d2c;
        }

        .header-title p {
            color: #718078;
            font-size: 14px;
            margin-top: 6px;
        }

        .header-profile {
            display: flex;
            align-items: center;
            gap: 11px;
            background: white;
            padding: 8px 13px 8px 8px;
            border-radius: 50px;
            box-shadow: 0 5px 20px rgba(20,60,40,0.07);
        }

        .header-avatar {
            width: 38px;
            height: 38px;
            background: #dff3e7;
            color: #167447;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .header-profile span {
            font-size: 13px;
            font-weight: 600;
        }


        /* ==============================
           PROFILE CARD
        ============================== */

        .profile-card {
            background: linear-gradient(
                135deg,
                #0b3d2e,
                #167447
            );
            color: white;
            border-radius: 18px;
            padding: 25px;
            position: relative;
            overflow: hidden;
            margin-bottom: 22px;
            box-shadow: 0 10px 30px rgba(11,61,46,0.15);
        }

        .profile-card::after {
            content: "";
            width: 230px;
            height: 230px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
            position: absolute;
            right: -80px;
            top: -100px;
        }

        .profile-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 2;
        }

        .profile-row {
            display: flex;
            align-items: center;
            gap: 17px;
        }

        .avatar-badge {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            background: white;
            color: #167447;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }

        .profile-row h2 {
            font-size: 20px;
            margin-bottom: 5px;
        }

        .profile-row p {
            color: #cce6d8;
            font-size: 13px;
        }

        .profile-details {
            display: flex;
            gap: 35px;
        }

        .profile-detail span {
            display: block;
            font-size: 11px;
            color: #b8d8c8;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            margin-bottom: 5px;
        }

        .profile-detail strong {
            font-size: 13px;
        }

        .active-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .active-dot {
            width: 7px;
            height: 7px;
            background: #72e09e;
            border-radius: 50%;
        }


        /* ==============================
           STATS
        ============================== */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 22px;
        }

        .stat-box {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #e5eee8;
            box-shadow: 0 5px 20px rgba(20,60,40,0.05);
            transition: 0.2s ease;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(20,60,40,0.09);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #e8f6ed;
            color: #16804b;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }

        .stat-box span {
            color: #7b8981;
            display: block;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .stat-box strong {
            font-size: 26px;
            color: #153d2c;
        }


        /* ==============================
           DOCUMENT TABLE
        ============================== */

        .dashboard-card {
            background: white;
            border-radius: 18px;
            border: 1px solid #e5eee8;
            box-shadow: 0 5px 20px rgba(20,60,40,0.05);
        }

        .wide-card {
            overflow: hidden;
        }

        .card-header {
            padding: 21px 23px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #edf2ee;
        }

        .card-header h3 {
            font-size: 16px;
            color: #173e2d;
        }

        .card-header p {
            font-size: 12px;
            color: #8a968f;
            margin-top: 4px;
        }

        .view-all {
            color: #16804b;
            font-size: 12px;
            font-weight: 700;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .doc-table {
            width: 100%;
            border-collapse: collapse;
        }

        .doc-table th {
            background: #f8faf8;
            padding: 13px 22px;
            text-align: left;
            font-size: 11px;
            color: #7a8980;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .doc-table td {
            padding: 17px 22px;
            border-top: 1px solid #edf2ee;
            font-size: 13px;
            color: #526159;
        }

        .doc-table td:first-child {
            font-weight: 700;
            color: #1d3d2d;
        }

        .doc-table tbody tr {
            transition: 0.2s ease;
        }

        .doc-table tbody tr:hover {
            background: #f8fcf9;
        }


        /* ==============================
           STATUS
        ============================== */

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-pill::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .in-transit {
            background: #fff5dc;
            color: #9a6a00;
        }

        .in-transit::before {
            background: #d99a00;
        }

        .approved {
            background: #e4f6eb;
            color: #167447;
        }

        .approved::before {
            background: #24a35f;
        }

        .completed {
            background: #e8f0ff;
            color: #4169a1;
        }

        .completed::before {
            background: #4169a1;
        }


        /* ==============================
           HAMBURGER
        ============================== */

        .sidebar-hamburger {
            display: none;
            border: none;
            background: #0b3d2e;
            color: white;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
        }


        /* ==============================
           LOGOUT MODAL
        ============================== */

        .logout-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
        }

        .logout-modal.is-open {
            display: flex;
        }

        .logout-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(5,25,17,0.55);
            backdrop-filter: blur(5px);
        }

        .logout-modal-dialog {
            position: relative;
            background: white;
            width: min(400px, calc(100% - 30px));
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 25px 70px rgba(0,0,0,0.2);
            animation: modalIn 0.2s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .logout-icon {
            width: 50px;
            height: 50px;
            background: #eaf7ee;
            color: #16804b;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            font-size: 20px;
        }

        .logout-modal-dialog h3 {
            font-size: 20px;
            color: #173e2d;
            margin-bottom: 8px;
        }

        .logout-modal-dialog p {
            color: #78857e;
            font-size: 13px;
            margin-bottom: 25px;
        }

        .logout-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .logout-cancel,
        .dashboard-logout {
            padding: 10px 18px;
            border-radius: 9px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
        }

        .logout-cancel {
            background: #f1f4f2;
            border: none;
            color: #56645c;
        }

        .dashboard-logout {
            background: #0b3d2e;
            border: none;
            color: white;
        }

        .dashboard-logout:hover {
            background: #167447;
        }


        /* ==============================
           RESPONSIVE
        ============================== */

        @media (max-width: 1000px) {

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .profile-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

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

            .dashboard-page {
                width: 100%;
                margin-left: 0;
                padding: 20px;
            }

            .sidebar-hamburger {
                display: flex;
                align-items: center;
                justify-content: center;
                position: fixed;
                top: 18px;
                left: 18px;
                z-index: 2000;
            }

            .dashboard-header {
                padding-left: 55px;
            }

            .header-profile {
                display: none;
            }

            .profile-details {
                flex-direction: column;
                gap: 15px;
            }

        }


        @media (max-width: 550px) {

            .dashboard-page {
                padding: 15px;
            }

            .dashboard-header {
                margin-bottom: 20px;
            }

            .header-title h1 {
                font-size: 22px;
            }

            .header-title p {
                font-size: 12px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-box {
                padding: 15px;
            }

            .stat-box strong {
                font-size: 22px;
            }

            .profile-card {
                padding: 20px;
            }

            .profile-row {
                align-items: flex-start;
            }

            .avatar-badge {
                width: 58px;
                height: 58px;
                font-size: 22px;
            }

            .profile-row h2 {
                font-size: 17px;
            }

            .doc-table th,
            .doc-table td {
                padding: 13px 15px;
                white-space: nowrap;
            }

        }

    </style>
</head>

<body>

<div class="dashboard-shell">

    <!-- SIDEBAR -->
    <aside class="dashboard-sidebar" id="sidebar">

        <div class="sidebar-brand">

            <div class="mini-logo">
                CSJ
            </div>

            <div>
                <strong>Colegio de San Jose</strong>
                <small>Document Tracking System</small>
            </div>

        </div>


        <nav class="sidebar-nav">

            <a href="#" class="nav-item active">
                <i class="fa-solid fa-chart-pie"></i>
                Dashboard
            </a>

            <a href="create_document.php" class="nav-item">
                <i class="fa-solid fa-file-circle-plus"></i>
                Create Document
            </a>

            <a href="track_document.php" class="nav-item">
                <i class="fa-solid fa-location-dot"></i>
                Track Document
            </a>

            <div class="documents-nav-group">
                <a href="my_documents.php" class="nav-item">
                    <i class="fa-solid fa-folder-open"></i>
                    My Documents
                </a>
                <a href="received_documents.php" class="nav-subitem">
                    <i class="fa-solid fa-inbox"></i>
                    Received Documents
                </a>
            </div>

            <a href="#" class="nav-item">
                <i class="fa-solid fa-inbox"></i>
                Incoming Requests
            </a>

            <a href="#" class="nav-item">
                <i class="fa-solid fa-chart-column"></i>
                Reports
            </a>

            <a href="#" class="nav-item">
                <i class="fa-solid fa-gear"></i>
                Settings
            </a>

            <button
                type="button"
                class="nav-item nav-logout logout-trigger"
            >
                <i class="fa-solid fa-right-from-bracket"></i>
                Log Out
            </button>

        </nav>

    </aside>


    <!-- MAIN PAGE -->
    <div class="dashboard-page">

        <div class="dashboard-container">

            <!-- MOBILE MENU -->
            <button
                class="sidebar-hamburger"
                id="sidebarToggle"
                type="button"
                aria-label="Open navigation"
            >
                <i class="fa-solid fa-bars"></i>
            </button>


            <!-- HEADER -->
            <header class="dashboard-header">

                <div class="header-title">

                    <h1>Welcome back, <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>!</h1>

                    <p>
                        Here's what's happening with your documents today.
                    </p>

                </div>


                <div class="header-profile">

                    <div class="header-avatar">
                        <?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <span>
                        <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>
                    </span>

                </div>

            </header>


            <!-- PROFILE -->
            <section class="profile-card">

                <div class="profile-content">

                    <div class="profile-row">

                        <div class="avatar-badge">
                            <?php echo htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8'); ?>
                        </div>

                        <div>

                            <h2>
                                <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>
                            </h2>

                            <p>
                                <?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?>
                            </p>

                        </div>

                    </div>


                    <div class="profile-details">

                        <div class="profile-detail">

                            <span>Department</span>

                            <strong>
                                <?php echo htmlspecialchars($userDepartment, ENT_QUOTES, 'UTF-8'); ?>
                            </strong>

                        </div>


                        <div class="profile-detail">

                            <span>Status</span>

                            <strong class="active-status">
                                <span class="active-dot"></span>
                                Active
                            </strong>

                        </div>

                    </div>

                </div>

            </section>


            <!-- STATISTICS -->
            <section class="stats-grid">

                <div class="stat-box">

                    <div class="stat-icon">
                        <i class="fa-solid fa-file-lines"></i>
                    </div>

                    <span>Tracked Documents</span>

                    <strong>24</strong>

                </div>


                <div class="stat-box">

                    <div class="stat-icon">
                        <i class="fa-solid fa-clock"></i>
                    </div>

                    <span>Pending</span>

                    <strong>8</strong>

                </div>


                <div class="stat-box">

                    <div class="stat-icon">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>

                    <span>Approved</span>

                    <strong>12</strong>

                </div>


                <div class="stat-box">

                    <div class="stat-icon">
                        <i class="fa-solid fa-box-open"></i>
                    </div>

                    <span>Received</span>

                    <strong>4</strong>

                </div>

            </section>


            <!-- RECENT DOCUMENTS -->
            <section class="dashboard-card wide-card">

                <div class="card-header">

                    <div>

                        <h3>Recent Documents</h3>

                        <p>
                            Recently updated documents in the system
                        </p>

                    </div>

                    <a href="my_documents.php" class="view-all">
                        View All
                    </a>

                </div>


                <div class="table-wrapper">

                    <table class="doc-table">

                        <thead>

                            <tr>

                                <th>Document</th>

                                <th>Reference No.</th>

                                <th>Status</th>

                                <th>Updated</th>

                            </tr>

                        </thead>


                        <tbody>

                            <tr>

                                <td>
                                    Purchase Order
                                </td>

                                <td>
                                    REF-2024-001
                                </td>

                                <td>
                                    <span class="status-pill in-transit">
                                        In Transit
                                    </span>
                                </td>

                                <td>
                                    September 2, 2026
                                </td>

                            </tr>


                            <tr>

                                <td>
                                    Contract Approval
                                </td>

                                <td>
                                    REF-2024-025
                                </td>

                                <td>
                                    <span class="status-pill approved">
                                        Approved
                                    </span>
                                </td>

                                <td>
                                    September 1, 2026
                                </td>

                            </tr>


                            <tr>

                                <td>
                                    Inspection Report
                                </td>

                                <td>
                                    QR-043
                                </td>

                                <td>
                                    <span class="status-pill completed">
                                        Completed
                                    </span>
                                </td>

                                <td>
                                    August 30, 2026
                                </td>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </section>

        </div>

    </div>

</div>


<!-- LOGOUT MODAL -->

<div
    class="logout-modal"
    id="logoutModal"
    aria-hidden="true"
>

    <div
        class="logout-modal-backdrop"
        data-close-logout
    ></div>


    <div
        class="logout-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="logoutTitle"
    >

        <div class="logout-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>

        <h3 id="logoutTitle">
            Confirm Logout
        </h3>

        <p>
            Are you sure you want to log out of your DocTrack account?
        </p>


        <div class="logout-modal-actions">

            <button
                type="button"
                class="logout-cancel"
                data-close-logout
            >
                Cancel
            </button>


            <form
                method="post"
                action="logout.php"
            >

                <button
                    type="submit"
                    class="dashboard-logout"
                >
                    Log Out
                </button>

            </form>

        </div>

    </div>

</div>


<script>

    /* ==============================
       SIDEBAR
    ============================== */

    const sidebar = document.getElementById('sidebar');

    const sidebarToggle =
        document.getElementById('sidebarToggle');

    sidebarToggle.addEventListener('click', function () {

        sidebar.classList.toggle('is-open');

    });


    /* ==============================
       LOGOUT MODAL
    ============================== */

    const logoutModal =
        document.getElementById('logoutModal');

    const logoutTrigger =
        document.querySelector('.logout-trigger');

    const closeLogoutButtons =
        document.querySelectorAll('[data-close-logout]');


    logoutTrigger.addEventListener('click', function () {

        logoutModal.classList.add('is-open');

        logoutModal.setAttribute(
            'aria-hidden',
            'false'
        );

    });


    closeLogoutButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            logoutModal.classList.remove('is-open');

            logoutModal.setAttribute(
                'aria-hidden',
                'true'
            );

        });

    });


    /* ==============================
       ESC KEY
    ============================== */

    document.addEventListener('keydown', function (event) {

        if (
            event.key === 'Escape' &&
            logoutModal.classList.contains('is-open')
        ) {

            logoutModal.classList.remove('is-open');

            logoutModal.setAttribute(
                'aria-hidden',
                'true'
            );

        }

    });

</script>

</body>

</html>
```
