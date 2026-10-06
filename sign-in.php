<?php
require_once __DIR__ . '/session_config.php';
require 'db.php';

$departmentOptions = require __DIR__ . '/department_options.php';

$message = '';
$messageType = 'success';
$showLoginPrompt = false;

$loginError = $_SESSION['login_error'] ?? '';
$loginIdentifier = $_SESSION['login_identifier'] ?? '';

unset(
    $_SESSION['login_error'],
    $_SESSION['login_identifier']
);


/* =========================================================
   REGISTRATION
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $employeeId = trim($_POST['employee_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    /* Validate required fields */

    if (
        $employeeId === '' ||
        $firstName === '' ||
        $lastName === '' ||
        $contactNumber === '' ||
        $email === '' ||
        !in_array($department, $departmentOptions, true) ||
        $position === '' ||
        $username === '' ||
        $password === ''
    ) {

        $message = 'Please fill in all required fields.';
        $messageType = 'error';

    } else {

        /* Check if account already exists */

        $stmt = $conn->prepare(
            'SELECT id
             FROM users
             WHERE employee_id = ?
             OR email = ?
             OR username = ?'
        );

        $stmt->bind_param(
            'sss',
            $employeeId,
            $email,
            $username
        );

        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {

            $message =
                'An account with this employee ID, email, or username already exists.';

            $messageType = 'error';

        } else {

            /* Hash password */

            $hashedPassword =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            /* Insert account */

            $insert = $conn->prepare(
                'INSERT INTO users
                (
                    employee_id,
                    first_name,
                    last_name,
                    contact_number,
                    email,
                    department,
                    position,
                    username,
                    password
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $insert->bind_param(
                'sssssssss',
                $employeeId,
                $firstName,
                $lastName,
                $contactNumber,
                $email,
                $department,
                $position,
                $username,
                $hashedPassword
            );

            if ($insert->execute()) {

                $_SESSION['registration_success'] =
                    'Account created successfully.';

                unset(
                    $_SESSION['user_id'],
                    $_SESSION['user_name'],
                    $_SESSION['user_email'],
                    $_SESSION['user_department'],
                    $_SESSION['user_role']
                );

                $message =
                    'Account created successfully. Do you want to log in now?';

                $messageType = 'success';
                $showLoginPrompt = true;

            } else {

                $message =
                    'Registration failed. Please try again.';

                $messageType = 'error';
            }

            $insert->close();
        }

        $stmt->close();
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

    <title>Create Account | DocTrack</title>


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

        /* =====================================================
           RESET
        ===================================================== */

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        /* =====================================================
           BODY
        ===================================================== */

        body {
            min-height: 100vh;

            font-family: 'Inter', sans-serif;

            color: #17231b;

            background:
                radial-gradient(
                    circle at top left,
                    rgba(22, 101, 52, 0.08),
                    transparent 35%
                ),
                radial-gradient(
                    circle at bottom right,
                    rgba(34, 197, 94, 0.07),
                    transparent 35%
                ),
                #f5f8f6;
        }


        /* =====================================================
           PAGE
        ===================================================== */

        .auth-page {
            min-height: 100vh;

            display: flex;

            align-items: center;
            justify-content: center;

            padding: 35px 20px;
        }


        /* =====================================================
           MAIN CARD
        ===================================================== */

        .auth-card {
            position: relative;

            width: 100%;
            max-width: 850px;

            background: #ffffff;

            border: 1px solid #e5ebe7;

            border-radius: 24px;

            overflow: hidden;

            box-shadow:
                0 20px 60px rgba(16, 61, 35, 0.10);
        }


        /* =====================================================
           CONTENT
        ===================================================== */

        .auth-content {
            width: 100%;

            padding: 48px 55px;
        }


        /* =====================================================
           BACK BUTTON
        ===================================================== */

        .back-button {
            position: absolute;

            top: 20px;
            left: 23px;

            display: inline-flex;

            align-items: center;
            gap: 7px;

            padding: 8px 12px;

            color: #66736b;

            text-decoration: none;

            font-size: 12px;
            font-weight: 600;

            border-radius: 8px;

            transition:
                background 0.2s ease,
                color 0.2s ease;
        }

        .back-button:hover {
            color: #166534;

            background: #f0fdf4;
        }

        .back-button i {
            font-size: 11px;
        }


        /* =====================================================
           HEADER
        ===================================================== */

        .auth-header {
            margin-top: 25px;

            margin-bottom: 30px;

            text-align: center;
        }

        .auth-header .eyebrow {
            display: inline-block;

            margin-bottom: 8px;

            color: #15803d;

            font-size: 11px;
            font-weight: 800;

            letter-spacing: 1px;

            text-transform: uppercase;
        }

        .auth-header h1 {
            margin-bottom: 8px;

            color: #17231b;

            font-size: 30px;
            font-weight: 800;

            line-height: 1.2;
        }

        .auth-header p {
            max-width: 520px;

            margin: 0 auto;

            color: #748078;

            font-size: 13px;

            line-height: 1.6;
        }


        /* =====================================================
           STATUS MESSAGE
        ===================================================== */

        .status-message {
            padding: 12px 14px;

            margin-bottom: 20px;

            border-radius: 11px;

            font-size: 13px;

            line-height: 1.5;

            font-weight: 600;
        }

        .status-message.success {
            color: #166534;

            background: #f0fdf4;

            border: 1px solid #bbf7d0;
        }

        .status-message.error {
            color: #b91c1c;

            background: #fef2f2;

            border: 1px solid #fecaca;
        }


        /* =====================================================
           FORM
        ===================================================== */

        .auth-form {
            display: flex;

            flex-direction: column;

            gap: 17px;
        }

        .form-row {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 16px;
        }

        .field-group {
            display: flex;

            flex-direction: column;

            gap: 7px;
        }

        .field-group label {
            color: #37433c;

            font-size: 12px;

            font-weight: 700;
        }

        .field-group input,
        .field-group select {
            width: 100%;

            height: 46px;

            padding: 0 14px;

            border: 1px solid #dce5df;

            border-radius: 10px;

            background: #fafcfb;

            color: #17231b;

            outline: none;

            font-family: inherit;

            font-size: 13px;

            transition:
                border 0.2s ease,
                box-shadow 0.2s ease,
                background 0.2s ease;
        }

        .field-group input::placeholder {
            color: #a2aca6;
        }

        .field-group input:hover,
        .field-group select:hover {
            border-color: #b9c9be;
        }

        .field-group input:focus,
        .field-group select:focus {
            background: #ffffff;

            border-color: #22a060;

            box-shadow:
                0 0 0 4px rgba(34, 160, 96, 0.08);
        }

        .field-group select {
            cursor: pointer;
        }


        /* =====================================================
           CREATE ACCOUNT BUTTON
        ===================================================== */

        .auth-btn {
            width: 100%;

            height: 48px;

            margin-top: 5px;

            display: flex;

            align-items: center;
            justify-content: center;

            border: none;

            border-radius: 11px;

            background:
                linear-gradient(
                    135deg,
                    #166534,
                    #15803d
                );

            color: #ffffff;

            font-family: inherit;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

            box-shadow:
                0 8px 20px rgba(22, 101, 52, 0.18);

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease,
                background 0.2s ease;
        }

        .auth-btn:hover {
            background:
                linear-gradient(
                    135deg,
                    #14532d,
                    #166534
                );

            transform: translateY(-1px);

            box-shadow:
                0 11px 25px rgba(22, 101, 52, 0.24);
        }

        .auth-btn:active {
            transform: translateY(0);
        }


        /* =====================================================
           LOGIN LINK
        ===================================================== */

        .auth-links {
            margin-top: 22px;

            text-align: center;

            color: #7b867f;

            font-size: 12px;
        }

        .auth-links a {
            color: #166534;

            font-weight: 700;

            text-decoration: none;
        }

        .auth-links a:hover {
            text-decoration: underline;
        }


        /* =====================================================
           LOGIN / SUCCESS MODAL
        ===================================================== */

        .login-modal {
            position: fixed;

            inset: 0;

            z-index: 3000;

            display: none;

            align-items: center;

            justify-content: center;

            padding: 20px;
        }

        .login-modal:target {
            display: flex;
        }

        .login-modal-backdrop {
            position: absolute;

            inset: 0;

            background: rgba(15, 30, 21, 0.55);

            backdrop-filter: blur(5px);
        }

        .login-modal-dialog {
            position: relative;

            z-index: 2;

            width: 100%;

            max-width: 430px;

            padding: 34px;

            background: #ffffff;

            border-radius: 22px;

            box-shadow:
                0 30px 80px rgba(0, 0, 0, 0.22);

            animation: modalIn 0.22s ease;
        }

        @keyframes modalIn {

            from {
                opacity: 0;

                transform:
                    translateY(15px)
                    scale(0.98);
            }

            to {
                opacity: 1;

                transform:
                    translateY(0)
                    scale(1);
            }

        }


        /* =====================================================
           MODAL CLOSE
        ===================================================== */

        .login-modal-close {
            position: absolute;

            top: 16px;
            right: 18px;

            width: 32px;
            height: 32px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background: #f2f5f3;

            color: #66726b;

            text-decoration: none;

            font-size: 21px;

            transition:
                background 0.2s ease,
                color 0.2s ease;
        }

        .login-modal-close:hover {
            background: #dcfce7;

            color: #166534;
        }


        /* =====================================================
           MODAL HEADER
        ===================================================== */

        .modal-header {
            margin-top: 0;

            margin-bottom: 22px;

            text-align: left;
        }

        .modal-header p {
            margin: 0;
        }


        /* =====================================================
           MODAL ACTION BUTTONS
        ===================================================== */

        .logout-modal-actions {
            display: flex;

            align-items: center;

            justify-content: flex-end;

            gap: 10px;
        }

        .logout-cancel,
        .dashboard-logout {
            min-height: 42px;

            padding: 0 17px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            border-radius: 10px;

            font-size: 12px;

            font-weight: 700;

            text-decoration: none;
        }

        .logout-cancel {
            color: #4b5563;

            background: #f1f5f2;
        }

        .logout-cancel:hover {
            background: #e5ebe7;
        }

        .dashboard-logout {
            color: #ffffff;

            background: #166534;
        }

        .dashboard-logout:hover {
            background: #14532d;
        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 650px) {

            .auth-page {
                padding: 15px;
            }

            .auth-card {
                border-radius: 18px;
            }

            .auth-content {
                padding: 38px 22px 28px;
            }

            .auth-header {
                margin-top: 25px;

                margin-bottom: 25px;
            }

            .auth-header h1 {
                font-size: 25px;
            }

            .auth-header p {
                font-size: 12px;
            }

            .form-row {
                grid-template-columns: 1fr;

                gap: 17px;
            }

            .field-group input,
            .field-group select {
                height: 48px;
            }

            .back-button {
                top: 14px;

                left: 14px;
            }

            .login-modal {
                padding: 15px;
            }

            .login-modal-dialog {
                padding: 28px 21px;
            }

            .modal-header h1 {
                font-size: 24px;
            }

            .logout-modal-actions {
                justify-content: stretch;
            }

            .logout-cancel,
            .dashboard-logout {
                flex: 1;
            }
        }


        /* =====================================================
           VERY SMALL SCREENS
        ===================================================== */

        @media (max-width: 400px) {

            .auth-content {
                padding-left: 17px;
                padding-right: 17px;
            }

            .auth-header h1 {
                font-size: 23px;
            }

            .field-group label {
                font-size: 11px;
            }

            .field-group input,
            .field-group select {
                font-size: 12px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     REGISTRATION PAGE
========================================================= -->

<div class="auth-page">

    <div class="auth-card auth-signup-card">


        <!-- Back to Home -->

        <a
            href="index.php"
            class="back-button"
            aria-label="Back to Home"
        >

            <i class="fa-solid fa-arrow-left"></i>

            Back to Home

        </a>


        <!-- Registration Content -->

        <div class="auth-content">


            <!-- Header -->

            <div class="auth-header">

                <span class="eyebrow">
                    Account Registration
                </span>

                <h1>
                    Create your account
                </h1>

                <p>
                    Register your account to access the
                    document tracking portal.
                </p>

            </div>


            <!-- Status Message -->

            <?php if ($message !== ''): ?>

                <div
                    class="status-message
                    <?php
                    echo $messageType === 'error'
                        ? 'error'
                        : 'success';
                    ?>"
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


            <!-- Registration Form -->

            <form
                class="auth-form"
                method="post"
                action="sign-in.php"
            >


                <!-- Employee ID -->

                <div class="form-row">

                    <div class="field-group">

                        <label for="employee-id">
                            Employee ID
                        </label>

                        <input
                            id="employee-id"
                            type="text"
                            name="employee_id"
                            placeholder="Enter employee ID"
                            autocomplete="off"
                            required
                        >

                    </div>

                </div>


                <!-- First / Last Name -->

                <div class="form-row">

                    <div class="field-group">

                        <label for="first-name">
                            First Name
                        </label>

                        <input
                            id="first-name"
                            type="text"
                            name="first_name"
                            placeholder="Enter first name"
                            autocomplete="given-name"
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
                            placeholder="Enter last name"
                            autocomplete="family-name"
                            required
                        >

                    </div>

                </div>


                <!-- Contact / Email -->

                <div class="form-row">

                    <div class="field-group">

                        <label for="contact-number">
                            Contact Number
                        </label>

                        <input
                            id="contact-number"
                            type="tel"
                            name="contact_number"
                            placeholder="Enter contact number"
                            autocomplete="tel"
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
                            placeholder="Enter email address"
                            autocomplete="email"
                            required
                        >

                    </div>

                </div>


                <!-- Department / Position -->

                <div class="form-row">

                    <div class="field-group">

                        <label for="department">
                            Department / Office
                        </label>

                        <select
                            id="department"
                            name="department"
                            required
                        >

                            <option value="">
                                Select department / office
                            </option>

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

                    </div>


                    <div class="field-group">

                        <label for="position">
                            Position
                        </label>

                        <select
                            id="position"
                            name="position"
                            required
                        >

                            <option value="">
                                Select position
                            </option>

                            <option value="MG ASSIGNED">
                                MG ASSIGNED
                            </option>

                            <option value="STAFF">
                                STAFF
                            </option>

                        </select>

                    </div>

                </div>


                <!-- Username / Password -->

                <div class="form-row">

                    <div class="field-group">

                        <label for="username">
                            Username
                        </label>

                        <input
                            id="username"
                            type="text"
                            name="username"
                            placeholder="Create username"
                            autocomplete="username"
                            required
                        >

                    </div>


                    <div class="field-group">

                        <label for="sign-password">
                            Password
                        </label>

                        <input
                            id="sign-password"
                            type="password"
                            name="password"
                            placeholder="Create password"
                            autocomplete="new-password"
                            required
                        >

                    </div>

                </div>


                <!-- Submit -->

                <button
                    class="auth-btn"
                    type="submit"
                >

                    <i class="fa-solid fa-user-plus"></i>

                    Create Account

                </button>

            </form>


            <!-- Login Link -->

            <div class="auth-links">

                Already have an account?

                <a href="#loginModal">
                    Log In
                </a>

            </div>

        </div>

    </div>

</div>



<!-- =========================================================
     ACCOUNT CREATED MODAL
========================================================= -->

<?php if ($showLoginPrompt): ?>

<div
    class="login-modal"
    id="successModal"
    aria-hidden="false"
    style="display: flex;"
>

    <div
        class="login-modal-backdrop"
        aria-hidden="true"
    ></div>


    <div
        class="login-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="successTitle"
    >


        <!-- Close -->

        <a
            href="sign-in.php"
            class="login-modal-close"
            aria-label="Close success popup"
        >
            &times;
        </a>


        <div class="auth-header modal-header">

            <span class="eyebrow">
                Registration Complete
            </span>

            <h1 id="successTitle">
                Account Created
            </h1>

            <p>
                Your account was created successfully.
            </p>

        </div>


        <div class="status-message success">

            <?php
            echo htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            );
            ?>

        </div>


        <div class="logout-modal-actions">

            <a
                href="index.php"
                class="logout-cancel"
            >
                Back
            </a>

            <a
                href="#loginModal"
                class="dashboard-logout"
            >
                Log In
            </a>

        </div>

    </div>

</div>

<?php endif; ?>



<!-- =========================================================
     LOGIN MODAL
========================================================= -->

<div
    class="login-modal"
    id="loginModal"
    aria-hidden="true"
>

    <div
        class="login-modal-backdrop"
        aria-hidden="true"
    ></div>


    <div
        class="login-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="loginTitle"
    >


        <!-- Close -->

        <a
            href="#"
            class="login-modal-close"
            aria-label="Close login form"
        >
            &times;
        </a>


        <!-- Login Header -->

        <div class="auth-header modal-header">

            <span class="eyebrow">
                Welcome Back
            </span>

            <h1 id="loginTitle">
                Log In
            </h1>

            <p>
                Enter your credentials to access the document portal.
            </p>

        </div>


        <!-- Login Error -->

        <?php if ($loginError !== ''): ?>

            <div class="status-message error">

                <?php
                echo htmlspecialchars(
                    $loginError,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>

            </div>

        <?php endif; ?>


        <!-- Login Form -->

        <form
            class="auth-form"
            method="post"
            action="login.php"
        >

            <input
                type="hidden"
                name="return_to"
                value="sign-in.php"
            >


            <div class="field-group">

                <label for="login-identifier">
                    Username / Email
                </label>

                <input
                    id="login-identifier"
                    type="text"
                    name="login_identifier"
                    value="<?php
                        echo htmlspecialchars(
                            $loginIdentifier,
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>"
                    placeholder="Enter username or email"
                    autocomplete="username"
                    required
                >

            </div>


            <div class="field-group">

                <label for="login-password">
                    Password
                </label>

                <input
                    id="login-password"
                    type="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >

            </div>


            <button
                class="auth-btn"
                type="submit"
            >

                <i class="fa-solid fa-right-to-bracket"></i>

                Log In

            </button>

        </form>

    </div>

</div>

</body>

</html>