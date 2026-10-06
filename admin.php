<?php
require_once __DIR__ . '/session_config.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require 'db.php';

$userName = $_SESSION['user_name'] ?? 'Admin';
$userEmail = $_SESSION['user_email'] ?? 'admin@doctrack.local';
$userDepartment = $_SESSION['user_department'] ?? 'Administration';

$usersStmt = $conn->prepare('
    SELECT id, employee_id, first_name, last_name, contact_number,
           email, department, position, username
    FROM users
    ORDER BY first_name ASC
');

$usersStmt->execute();
$usersResult = $usersStmt->get_result();
$users = $usersResult->fetch_all(MYSQLI_ASSOC);

/*
|--------------------------------------------------------------------------
| Dashboard Counts
|--------------------------------------------------------------------------
*/

$totalUsers = count($users);

/*
| These are currently sample values.
| You can connect them to your documents table later.
*/
$pending = 10;
$approved = 22;
$rejected = 4;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | DocTrack</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        :root {
            --primary: #087a45;
            --primary-dark: #055c34;
            --primary-light: #e9f7ef;
            --primary-soft: #f3fbf6;

            --gold: #d8a62a;

            --text: #17231c;
            --text-light: #66736c;

            --white: #ffffff;
            --background: #f5f8f6;
            --border: #e3ebe6;

            --danger: #d94a4a;
            --danger-light: #fff1f1;

            --shadow-sm: 0 4px 14px rgba(18, 44, 31, 0.05);
            --shadow-md: 0 12px 35px rgba(18, 44, 31, 0.08);

            --sidebar-width: 68px;
            --sidebar-expanded-width: 250px;
        }

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

        /* =========================================================
           SIDEBAR
        ========================================================= */

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

            display: flex;
            flex-direction: column;

            box-shadow: 8px 0 30px rgba(0, 0, 0, 0.08);

            overflow-x: hidden;
            transition: width 0.25s ease, transform 0.3s ease;
        }

        .dashboard-sidebar:hover,
        .dashboard-sidebar:focus-within,
        .dashboard-sidebar.is-open {
            width: var(--sidebar-expanded-width);
        }

        .dashboard-sidebar .sidebar-brand {
            justify-content: center;
            padding: 22px 10px;
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

        .dashboard-sidebar .sidebar-nav {
            padding-left: 8px;
            padding-right: 8px;
        }

        .dashboard-sidebar .nav-item {
            justify-content: center;
            gap: 0;
            padding-left: 0;
            padding-right: 0;
        }

        .dashboard-sidebar .nav-item span {
            display: none;
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
            padding-left: 14px;
            padding-right: 14px;
        }

        .dashboard-sidebar:hover .nav-item span,
        .dashboard-sidebar:focus-within .nav-item span,
        .dashboard-sidebar.is-open .nav-item span {
            display: inline;
        }

        .sidebar-brand {
            min-height: 95px;
            padding: 22px 20px;

            display: flex;
            align-items: center;
            gap: 13px;

            border-bottom: 1px solid rgba(255,255,255,0.10);
        }

        .mini-logo {
            width: 48px;
            height: 48px;

            border-radius: 14px;

            background: rgba(255,255,255,0.14);

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 16px;
            font-weight: 800;

            border: 1px solid rgba(255,255,255,0.16);
        }

        .sidebar-brand strong {
            display: block;
            font-size: 15px;
            font-weight: 800;
        }

        .sidebar-brand small {
            display: block;
            margin-top: 4px;

            color: rgba(255,255,255,0.68);

            font-size: 11px;
            font-weight: 500;
        }

        .sidebar-nav {
            padding: 20px 13px;

            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .nav-item {
            width: 100%;

            display: flex;
            align-items: center;
            gap: 13px;

            padding: 13px 14px;

            border: 0;
            border-radius: 12px;

            background: transparent;

            color: rgba(255,255,255,0.76);

            font-size: 13px;
            font-weight: 600;

            cursor: pointer;

            transition:
                background 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 15px;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.10);
            color: white;
            transform: translateX(2px);
        }

        .nav-item.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 6px 18px rgba(0,0,0,0.08);
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
            background: rgba(217,74,74,0.15);
            color: #fff;
        }

        .logout-trigger {
            text-align: left;
        }

        /* =========================================================
           MAIN PAGE
        ========================================================= */

        .dashboard-page {
            min-height: 100vh;
            margin-left: var(--sidebar-width);
            padding: 28px;
        }

        .dashboard-container {
            max-width: 1450px;
            margin: 0 auto;
        }

        /* =========================================================
           TOP HEADER
        ========================================================= */

        .top-header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            margin-bottom: 26px;
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

            border: 1px solid var(--border);
            border-radius: 12px;

            background: white;
            color: var(--primary);

            cursor: pointer;

            font-size: 17px;
        }

        .page-heading h1 {
            font-size: 27px;
            line-height: 1.2;
            font-weight: 800;
            letter-spacing: -0.6px;
        }

        .page-heading p {
            margin-top: 6px;

            color: var(--text-light);

            font-size: 13px;
        }

        .admin-chip {
            display: flex;
            align-items: center;
            gap: 10px;

            background: white;

            border: 1px solid var(--border);
            border-radius: 15px;

            padding: 8px 12px 8px 8px;

            box-shadow: var(--shadow-sm);
        }

        .admin-avatar-small {
            width: 36px;
            height: 36px;

            border-radius: 11px;

            background: var(--primary-light);
            color: var(--primary);

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

            color: var(--text-light);

            font-size: 10px;
        }

        /* =========================================================
           PROFILE + QUICK STATS
        ========================================================= */

        .overview-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;

            margin-bottom: 22px;
        }

        .dashboard-card {
            background: white;

            border: 1px solid var(--border);
            border-radius: 20px;

            box-shadow: var(--shadow-sm);

            overflow: hidden;
        }

        /* Profile */

        .profile-card {
            position: relative;
            padding: 24px;
        }

        .profile-card::before {
            content: "";

            position: absolute;
            top: 0;
            left: 0;
            right: 0;

            height: 4px;

            background: linear-gradient(
                90deg,
                var(--primary),
                var(--gold)
            );
        }

        .card-label {
            color: var(--primary);

            font-size: 11px;
            font-weight: 800;

            text-transform: uppercase;
            letter-spacing: 1px;

            margin-bottom: 20px;
        }

        .profile-row {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .avatar-badge {
            width: 58px;
            height: 58px;

            flex-shrink: 0;

            border-radius: 17px;

            background: linear-gradient(
                135deg,
                var(--primary),
                #0a9a59
            );

            color: white;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 21px;
            font-weight: 800;

            box-shadow: 0 8px 18px rgba(8,122,69,0.20);
        }

        .profile-row h2 {
            font-size: 18px;
            font-weight: 800;
        }

        .profile-row p {
            margin-top: 4px;

            color: var(--text-light);

            font-size: 12px;

            word-break: break-word;
        }

        .profile-details {
            display: grid;
            grid-template-columns: 1fr 1fr;

            gap: 12px;

            margin-top: 24px;
        }

        .profile-detail {
            background: var(--primary-soft);

            border: 1px solid #e0eee5;
            border-radius: 13px;

            padding: 13px;
        }

        .profile-detail span {
            display: block;

            color: var(--text-light);

            font-size: 10px;
            font-weight: 600;

            margin-bottom: 5px;
        }

        .profile-detail strong {
            font-size: 12px;
            font-weight: 700;

            word-break: break-word;
        }

        /* Stats */

        .stats-card {
            padding: 24px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);

            gap: 12px;
        }

        .stat-box {
            position: relative;

            min-height: 125px;

            padding: 19px;

            border: 1px solid var(--border);
            border-radius: 15px;

            background: #fbfcfb;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }

        .stat-icon {
            width: 34px;
            height: 34px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 10px;

            background: var(--primary-light);
            color: var(--primary);

            font-size: 14px;

            margin-bottom: 13px;
        }

        .stat-box.pending .stat-icon {
            background: #fff7df;
            color: #b27b00;
        }

        .stat-box.approved .stat-icon {
            background: #eaf8ef;
            color: #158a4e;
        }

        .stat-box.rejected .stat-icon {
            background: var(--danger-light);
            color: var(--danger);
        }

        .stat-box span {
            display: block;

            color: var(--text-light);

            font-size: 11px;
            font-weight: 600;
        }

        .stat-box strong {
            display: block;

            margin-top: 6px;

            font-size: 26px;
            font-weight: 800;
        }

        /* =========================================================
           USER MANAGEMENT
        ========================================================= */

        .users-card {
            padding: 0;
        }

        .users-header {
            padding: 22px 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;

            border-bottom: 1px solid var(--border);
        }

        .users-title h2 {
            font-size: 17px;
            font-weight: 800;
        }

        .users-title p {
            margin-top: 5px;

            color: var(--text-light);

            font-size: 11px;
        }

        .user-tools {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-count {
            padding: 9px 12px;

            border-radius: 10px;

            background: var(--primary-light);
            color: var(--primary);

            font-size: 11px;
            font-weight: 700;
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;

            left: 13px;
            top: 50%;

            transform: translateY(-50%);

            color: #93a099;

            font-size: 12px;
        }

        .search-box input {
            width: 230px;

            height: 38px;

            padding: 0 13px 0 34px;

            border: 1px solid var(--border);
            border-radius: 10px;

            outline: none;

            background: #fbfcfb;

            font-size: 11px;

            transition:
                border 0.2s ease,
                box-shadow 0.2s ease;
        }

        .search-box input:focus {
            border-color: var(--primary);

            box-shadow: 0 0 0 3px rgba(8,122,69,0.08);
        }

        /* =========================================================
           TABLE
        ========================================================= */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .doc-table {
            width: 100%;
            border-collapse: collapse;

            min-width: 1000px;
        }

        .doc-table th {
            padding: 13px 18px;

            background: #f8faf9;

            color: #64716a;

            text-align: left;

            font-size: 10px;
            font-weight: 800;

            text-transform: uppercase;
            letter-spacing: 0.5px;

            border-bottom: 1px solid var(--border);

            white-space: nowrap;
        }

        .doc-table td {
            padding: 15px 18px;

            border-bottom: 1px solid #edf1ee;

            color: #39443e;

            font-size: 11px;
            font-weight: 500;

            white-space: nowrap;
        }

        .doc-table tbody tr {
            transition: background 0.2s ease;
        }

        .doc-table tbody tr:hover {
            background: #f8fcf9;
        }

        .doc-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .employee-id {
            color: var(--primary);
            font-weight: 800;
        }

        .user-name-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-avatar {
            width: 32px;
            height: 32px;

            border-radius: 9px;

            background: var(--primary-light);
            color: var(--primary);

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 11px;
            font-weight: 800;

            flex-shrink: 0;
        }

        .name-text strong {
            display: block;

            color: var(--text);

            font-size: 11px;
        }

        .name-text small {
            display: block;

            margin-top: 2px;

            color: var(--text-light);

            font-size: 9px;
        }

        .badge {
            display: inline-flex;
            align-items: center;

            padding: 5px 9px;

            border-radius: 20px;

            background: var(--primary-light);
            color: var(--primary);

            font-size: 9px;
            font-weight: 700;
        }

        .position-badge {
            background: #f2f4f3;
            color: #56625c;
        }

        .empty-state {
            text-align: center !important;

            padding: 60px 20px !important;

            color: var(--text-light) !important;
        }

        .empty-state i {
            display: block;

            margin-bottom: 10px;

            color: #aeb9b2;

            font-size: 28px;
        }

        /* =========================================================
           MOBILE OVERLAY
        ========================================================= */

        .sidebar-overlay {
            display: none;

            position: fixed;
            inset: 0;

            background: rgba(0,0,0,0.35);

            z-index: 999;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 1100px) {

            .overview-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 850px) {

            :root {
                --sidebar-width: 245px;
                --sidebar-expanded-width: 245px;
            }

            .dashboard-sidebar {
                transform: translateX(-100%);
            }

            .dashboard-sidebar.is-open {
                transform: translateX(0);
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
                padding-left: 14px;
                padding-right: 14px;
            }

            .dashboard-sidebar .nav-item span {
                display: inline;
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

            .top-header {
                align-items: flex-start;
            }

            .admin-chip {
                display: none;
            }
        }

        @media (max-width: 650px) {

            .dashboard-page {
                padding: 15px;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .page-heading p {
                font-size: 11px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .profile-details {
                grid-template-columns: 1fr;
            }

            .users-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .user-tools {
                width: 100%;
            }

            .search-box {
                flex: 1;
            }

            .search-box input {
                width: 100%;
            }

            .user-count {
                white-space: nowrap;
            }
        }

        @media (max-width: 420px) {

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-box {
                min-height: auto;
            }

            .profile-card,
            .stats-card {
                padding: 18px;
            }

            .users-header {
                padding: 18px;
            }

            .top-header {
                margin-bottom: 18px;
            }
        }

        /* =========================================================
           ACCESSIBILITY
        ========================================================= */

        button:focus-visible,
        a:focus-visible,
        input:focus-visible {
            outline: 3px solid rgba(8,122,69,0.25);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition: none !important;
            }
        }
    </style>
</head>

<body>

    <!-- SIDEBAR OVERLAY -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR -->
    <aside class="dashboard-sidebar" id="sidebar" aria-label="Admin navigation">

        <div class="sidebar-brand">
            <div class="mini-logo">
                AD
            </div>

            <div>
                <strong>Administrator</strong>
                <small>DocTrack Management</small>
            </div>
        </div>

        <nav class="sidebar-nav">

            <a href="admin.php" class="nav-item active">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Admin Dashboard</span>
            </a>

            <a href="manage_users.php" class="nav-item">
                <i class="fa-solid fa-users"></i>
                <span>Manage Users</span>
            </a>

            <div class="documents-nav-group">
                <a href="my_documents.php" class="nav-item">
                    <i class="fa-solid fa-file-lines"></i>
                    <span>Documents</span>
                </a>
                <a href="received_documents.php" class="nav-subitem">
                    <i class="fa-solid fa-inbox"></i>
                    <span>Received Documents</span>
                </a>
            </div>

            <a href="track_document.php" class="nav-item">
                <i class="fa-solid fa-location-dot"></i>
                <span>Track Document</span>
            </a>

            <a href="#" class="nav-item">
                <i class="fa-solid fa-chart-column"></i>
                <span>Reports</span>
            </a>

            <a href="#" class="nav-item">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
            </a>

            <form
                method="post"
                action="logout.php"
                id="logoutForm"
            >
                <button
                    type="button"
                    class="nav-item logout-trigger nav-logout"
                    id="logoutButton"
                >
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Log Out</span>
                </button>
            </form>

        </nav>

    </aside>


    <!-- MAIN PAGE -->
    <div class="dashboard-page">

        <div class="dashboard-container">

            <!-- HEADER -->
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

                        <h1>Admin Dashboard</h1>

                        <p>
                            Manage users and monitor your DocTrack system.
                        </p>

                    </div>

                </div>


                <div class="admin-chip">

                    <div class="admin-avatar-small">
                        <?php echo strtoupper(substr($userName, 0, 1)); ?>
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

                        <span>Administrator</span>
                    </div>

                </div>

            </header>


            <!-- OVERVIEW -->
            <div class="overview-grid">

                <!-- PROFILE -->
                <section class="dashboard-card profile-card">

                    <div class="card-label">
                        Admin Profile
                    </div>

                    <div class="profile-row">

                        <div class="avatar-badge">
                            <?php
                            echo strtoupper(
                                substr($userName, 0, 1)
                            );
                            ?>
                        </div>

                        <div>

                            <h2>
                                <?php
                                echo htmlspecialchars(
                                    $userName,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </h2>

                            <p>
                                <?php
                                echo htmlspecialchars(
                                    $userEmail,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </p>

                        </div>

                    </div>


                    <div class="profile-details">

                        <div class="profile-detail">

                            <span>Department</span>

                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    $userDepartment,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </strong>

                        </div>


                        <div class="profile-detail">

                            <span>Role</span>

                            <strong>Administrator</strong>

                        </div>

                    </div>

                </section>


                <!-- STATISTICS -->
                <section class="dashboard-card stats-card">

                    <div class="card-label">
                        System Overview
                    </div>

                    <div class="stats-grid">

                        <div class="stat-box">

                            <div class="stat-icon">
                                <i class="fa-solid fa-users"></i>
                            </div>

                            <span>Total Users</span>

                            <strong>
                                <?php echo $totalUsers; ?>
                            </strong>

                        </div>


                        <div class="stat-box pending">

                            <div class="stat-icon">
                                <i class="fa-solid fa-clock"></i>
                            </div>

                            <span>Pending</span>

                            <strong>
                                <?php echo $pending; ?>
                            </strong>

                        </div>


                        <div class="stat-box approved">

                            <div class="stat-icon">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>

                            <span>Approved</span>

                            <strong>
                                <?php echo $approved; ?>
                            </strong>

                        </div>


                        <div class="stat-box rejected">

                            <div class="stat-icon">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </div>

                            <span>Rejected</span>

                            <strong>
                                <?php echo $rejected; ?>
                            </strong>

                        </div>

                    </div>

                </section>

            </div>


            <!-- USER MANAGEMENT -->
            <section
                class="dashboard-card users-card"
                id="manage-users"
            >

                <div class="users-header">

                    <div class="users-title">

                        <h2>
                            Manage Users
                        </h2>

                        <p>
                            View registered users and their account information.
                        </p>

                    </div>


                    <div class="user-tools">

                        <div class="user-count">
                            <?php echo $totalUsers; ?> Users
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

                </div>


                <div class="table-wrapper">

                    <table class="doc-table">

                        <thead>

                            <tr>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Username</th>
                            </tr>

                        </thead>


                        <tbody id="userTableBody">

                        <?php if (empty($users)): ?>

                            <tr>

                                <td
                                    colspan="7"
                                    class="empty-state"
                                >

                                    <i class="fa-solid fa-users-slash"></i>

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


                                    <td>

                                        <div class="user-name-cell">

                                            <div class="table-avatar">
                                                <?php echo $initial; ?>
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


                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $user['contact_number'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                        ?>
                                    </td>


                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $user['email'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                        ?>
                                    </td>


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


                                    <td>
                                        <?php
                                        echo htmlspecialchars(
                                            $user['username'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                        ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        </div>

    </div>


    <script>

        /* =========================================================
           MOBILE SIDEBAR
        ========================================================= */

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


        mobileMenu.addEventListener(
            "click",
            openSidebar
        );


        sidebarOverlay.addEventListener(
            "click",
            closeSidebar
        );


        /* =========================================================
           USER SEARCH
        ========================================================= */

        const searchInput =
            document.getElementById("userSearch");

        const userTableBody =
            document.getElementById("userTableBody");


        searchInput.addEventListener(
            "input",
            function () {

                const searchValue =
                    this.value.toLowerCase().trim();

                const rows =
                    userTableBody.querySelectorAll("tr");


                rows.forEach(function (row) {

                    const rowText =
                        row.textContent.toLowerCase();

                    if (rowText.includes(searchValue)) {

                        row.style.display = "";

                    } else {

                        row.style.display = "none";

                    }

                });

            }
        );


        /* =========================================================
           LOGOUT CONFIRMATION
        ========================================================= */

        const logoutButton =
            document.getElementById("logoutButton");

        const logoutForm =
            document.getElementById("logoutForm");


        logoutButton.addEventListener(
            "click",
            function () {

                const confirmLogout =
                    confirm(
                        "Are you sure you want to log out?"
                    );

                if (confirmLogout) {

                    logoutForm.submit();

                }

            }
        );


        /* =========================================================
           CLOSE SIDEBAR AFTER CLICKING A LINK ON MOBILE
        ========================================================= */

        document
            .querySelectorAll(".sidebar-nav a")
            .forEach(function (link) {

                link.addEventListener(
                    "click",
                    function () {

                        if (
                            window.innerWidth <= 850
                        ) {

                            closeSidebar();

                        }

                    }
                );

            });


        /* =========================================================
           ESC KEY
        ========================================================= */

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