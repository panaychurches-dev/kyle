<?php
require_once __DIR__ . '/session_config.php';
require 'db.php';

$message = '';
$messageType = 'success';

$returnTo = $_POST['return_to'] ?? '';
$returnTo = in_array($returnTo, ['index.php', 'sign-in.php'], true) ? $returnTo : '';

function redirectLoginError(string $message, string $loginIdentifier, string $returnTo): void
{
  if ($returnTo !== '') {
    $_SESSION['login_error'] = $message;
    $_SESSION['login_identifier'] = $loginIdentifier;
    header('Location: ' . $returnTo . '#loginModal');
    exit;
  }
}

if (isset($_SESSION['registration_success'])) {
    $message = $_SESSION['registration_success'];
    $messageType = 'success';
    unset($_SESSION['registration_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginIdentifier = trim($_POST['login_identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($loginIdentifier === '' || $password === '') {
        $message = 'Please enter your username/email and password.';
        $messageType = 'error';
      redirectLoginError($message, $loginIdentifier, $returnTo);
    } else {
        if (strtolower($loginIdentifier) === 'admin' && $password === 'admin123') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = 0;
            $_SESSION['user_name'] = 'Admin';
            $_SESSION['user_email'] = 'admin@doctrack.local';
            $_SESSION['user_department'] = 'Administration';
            $_SESSION['user_role'] = 'admin';
            header('Location: admin.php');
            exit;
        }

        $stmt = $conn->prepare('SELECT id, first_name, last_name, username, email, department, password FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $loginIdentifier, $loginIdentifier);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = preg_replace('/^#+\s*/', '', $user['first_name'] . ' ' . $user['last_name']);
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_department'] = $user['department'] ?? 'Department not set';
            unset($_SESSION['user_role']);
            header('Location: dashboard.php');
            exit;
        }

        $message = 'Invalid username/email or password.';
        $messageType = 'error';
        redirectLoginError($message, $loginIdentifier, $returnTo);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Log In | DocTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
  </head>
  <body>
    <div class="auth-page">
      <div class="auth-card">
        <a href="index.php" class="back-button" data-tooltip="Back to Home" aria-label="Back to Home">
          <img src="turn-back.png" alt="" />
        </a>
        <div class="auth-header">
          <h1>Log In</h1>
          <p>Welcome back! Please enter your login details.</p>
        </div>

        <?php if ($message !== ''): ?>
          <div class="status-message <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="login.php">
          <div class="field-group">
            <label for="login-identifier">Username / Email</label>
            <input id="login-identifier" type="text" name="login_identifier" placeholder="Enter username or email" required />
          </div>

          <div class="field-group">
            <label for="login-password">Password</label>
            <input id="login-password" type="password" name="password" placeholder="Enter your password" required />
          </div>

          <button class="auth-btn" type="submit">Log In</button>
        </form>

        <div class="auth-links">
          Don’t have an account? <a href="sign-in.php">Sign In</a>
        </div>
      </div>
    </div>
  </body>
</html>
