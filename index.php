<?php
require_once __DIR__ . '/session_config.php';

$selectedMethod = $_POST['tracking-method'] ?? 'reference';
$trackingValue = trim((string)($_POST['tracking_value'] ?? ''));
$errorMessage = '';
$documentResult = null;
$isAuthenticated = (int) ($_SESSION['user_id'] ?? 0) > 0 || ($_SESSION['user_role'] ?? '') === 'admin';
$userOffice = trim((string) ($_SESSION['user_department'] ?? ''));
$trackingCsrfToken = '';
if ($isAuthenticated) {
    if (empty($_SESSION['tracking_csrf_token'])) {
        $_SESSION['tracking_csrf_token'] = bin2hex(random_bytes(32));
    }
    $trackingCsrfToken = $_SESSION['tracking_csrf_token'];
}
$loginError = $_SESSION['login_error'] ?? '';
$loginIdentifier = $_SESSION['login_identifier'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_identifier']);
$isDatabaseDocument = false;

$documentStore = [
    'reference' => [
        'REF-2024-001' => [
            'title' => 'Purchase Order',
            'status' => 'In Transit',
            'department' => 'Finance Office',
            'location' => 'Procurement Room',
            'updated' => '2026-09-02 09:15 AM'
        ],
        'REF-2024-025' => [
            'title' => 'Contract Approval',
            'status' => 'Approved',
            'department' => 'Legal Office',
            'location' => 'Management Office',
            'updated' => '2026-09-02 10:40 AM'
        ],
        'REF-2024-112' => [
            'title' => 'Payroll Request',
            'status' => 'Received',
            'department' => 'HR Department',
            'location' => 'HR Records',
            'updated' => '2026-09-02 11:10 AM'
        ]
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($trackingValue === '') {
        $errorMessage = 'Please enter your reference number or scan a QR code.';
    } elseif ($selectedMethod === 'qr') {
        require_once __DIR__ . '/document_tracking.php';
        require_once __DIR__ . '/db.php';

        if (!ensureDocumentQrCodeColumn($conn) || !ensureDocumentTrackingEventsTable($conn)) {
            error_log('Could not initialize QR lookup: ' . $conn->error);
            $errorMessage = 'Document lookup is temporarily unavailable. Please try again later.';
        } else {
            $lookupStmt = $conn->prepare(
                'SELECT d.qr_code, d.title, d.document_type, d.department, d.priority,
                        d.description, d.created_at,
                        COALESCE(
                            (
                                SELECT event.office
                                FROM document_tracking_events AS event
                                WHERE event.document_id = d.id
                                ORDER BY event.id DESC
                                LIMIT 1
                            ),
                            u.department,
                            "Office not recorded"
                        ) AS current_office,
                        COALESCE(
                            (
                                SELECT event.event_type
                                FROM document_tracking_events AS event
                                WHERE event.document_id = d.id
                                  AND event.event_type IN ("approved", "rejected")
                                ORDER BY event.id DESC
                                LIMIT 1
                            ),
                            "Pending review"
                        ) AS decision_status,
                        COALESCE(
                            (
                                SELECT event.created_at
                                FROM document_tracking_events AS event
                                WHERE event.document_id = d.id
                                ORDER BY event.id DESC
                                LIMIT 1
                            ),
                            d.created_at
                        ) AS updated_at
                 FROM documents AS d
                 LEFT JOIN users AS u ON u.id = d.user_id
                 WHERE d.qr_code = ?
                 LIMIT 1'
            );

            if (!$lookupStmt) {
                error_log('Could not prepare public QR lookup: ' . $conn->error);
                $errorMessage = 'The document could not be looked up. Please try again.';
            } else {
                $lookupStmt->bind_param('s', $trackingValue);

                if (!$lookupStmt->execute()) {
                    error_log('Could not execute public QR lookup: ' . $lookupStmt->error);
                    $errorMessage = 'The document could not be looked up. Please try again.';
                } else {
                    $documentResult = $lookupStmt->get_result()->fetch_assoc() ?: null;

                    if ($documentResult === null) {
                        $errorMessage = 'No document found for the scanned QR code.';
                    } else {
                        $isDatabaseDocument = true;
                        $documentResult['status'] = $documentResult['decision_status'];
                        $documentResult['location'] = $documentResult['current_office'];
                        $documentResult['updated'] = date('M j, Y g:i A', strtotime($documentResult['updated_at']));
                    }
                }

                $lookupStmt->close();
            }
        }
    } else {
        $documentResult = $documentStore[$selectedMethod][$trackingValue] ?? null;

        if ($documentResult === null) {
            $label = $selectedMethod === 'reference' ? 'reference number' : 'QR code';
            $errorMessage = 'No document found for the entered ' . $label . '.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>DocTrack | Document Tracking System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
  </head>
  <body>
    <div class="page-shell">
      <header class="topbar">
        <div class="brand-wrap">
          <img src="csj-transparent.png" alt="Colegio de San Jose Logo" class="brand-logo" />
          <div class="brand-text">
            <span class="brand-name">Colegio de San Jose</span>
            <small>Document Tracking System</small>
          </div>
        </div>

        <div class="header-actions">
          <nav class="nav-links">
            <a href="#">Home</a>
            <a href="#">Track</a>
            <a href="#">About</a>
            <a href="#">Contact</a>
            <a href="#downloadModal">Download</a>
          </nav>

          <div class="menu-wrap">
            <button class="hamburger-menu" aria-label="Open menu" type="button" aria-expanded="false">
              <span></span>
              <span></span>
              <span></span>
            </button>

            <div class="menu-dropdown" aria-label="Account menu" hidden>
              <?php if ($isAuthenticated): ?>
                <a href="<?php echo ($_SESSION['user_role'] ?? '') === 'admin' ? 'admin.php' : 'dashboard.php'; ?>">Dashboard</a>
                <?php if (($_SESSION['user_role'] ?? '') !== 'admin'): ?>
                  <a href="my_documents.php">My Documents</a>
                <?php endif; ?>
                <a href="logout.php">Log Out</a>
              <?php else: ?>
                <a href="sign-in.php">Sign In</a>
                <a href="#loginModal" class="menu-login-trigger">Log In</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </header>

      <div class="login-modal" id="loginModal" aria-hidden="true">
        <div class="login-modal-backdrop" data-close-login></div>
        <div class="login-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
          <a href="#" class="login-modal-close" aria-label="Close login form">&times;</a>
          <div class="auth-header modal-header">
            <h1 id="loginTitle">Log In</h1>
            <p>Welcome back! Please enter your credentials.</p>
          </div>

          <?php if ($loginError !== ''): ?>
            <div class="status-message error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>

          <form class="auth-form" method="post" action="login.php">
            <input type="hidden" name="return_to" value="index.php" />
            <div class="field-group">
              <label for="login-identifier">Username or Email</label>
              <input id="login-identifier" type="text" name="login_identifier" value="<?php echo htmlspecialchars($loginIdentifier, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter username or email" required />
            </div>

            <div class="field-group">
              <label for="login-password">Password</label>
              <input id="login-password" type="password" name="password" placeholder="Enter your password" required />
            </div>

            <button class="auth-btn" type="submit">Log In</button>
          </form>
        </div>
      </div>

      <div class="download-modal" id="downloadModal" aria-hidden="true">
        <div class="download-modal-backdrop"></div>
        <div class="download-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="downloadTitle">
        </div>
      </div>

      <main class="hero">
        <div class="hero-copy">
          <p class="eyebrow">Official Document Tracking Portal</p>
          <h1>Track and monitor documents with confidence.</h1>
          <p class="subtitle">
            The official document monitoring system of Colegio de San Jose, designed to
            quickly locate records using a reference number or QR code.
          </p>
        </div>

        <section class="tracker-card" aria-label="Document tracking form">
          <form method="post" action="" class="tracker-form">
            <div class="option-toggle" role="radiogroup" aria-label="Tracking method">
              <label class="option">
                <input type="radio" name="tracking-method" value="reference" <?php echo $selectedMethod === 'reference' ? 'checked' : ''; ?> />
                <span>Reference No.</span>
              </label>
              <label class="option">
                <input type="radio" name="tracking-method" value="qr" <?php echo $selectedMethod === 'qr' ? 'checked' : ''; ?> />
                <span>QR Code</span>
              </label>
            </div>

            <div class="input-group">
              <label for="tracking-input" class="sr-only">Enter tracking number</label>
              
              <!-- Reference Number Input -->
              <input
                id="tracking-input-reference"
                name="tracking_value"
                type="text"
                class="tracking-input tracking-input-reference"
                value="<?php echo $selectedMethod === 'reference' ? htmlspecialchars($trackingValue, ENT_QUOTES, 'UTF-8') : ''; ?>"
                placeholder="Enter reference number"
                aria-label="Enter reference number"
                <?php echo $selectedMethod === 'qr' ? 'disabled' : ''; ?>
              />

              <div class="tracking-input-qr-wrapper">
                <button type="button" id="qr-scan-btn" class="tracking-input-qr-label" aria-label="Scan QR code">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9V5a2 2 0 0 1 2-2h4M21 9V5a2 2 0 0 0-2-2h-4M3 15v4a2 2 0 0 0 2 2h4m8-6v4a2 2 0 0 1-2 2h-4"/>
                  </svg>
                  <span id="qr-scan-btn-text"><?php echo ($selectedMethod === 'qr' && $trackingValue !== '') ? 'Scanned: ' . htmlspecialchars($trackingValue, ENT_QUOTES, 'UTF-8') : 'Scan QR code'; ?></span>
                </button>
                <input type="hidden" name="tracking_value" id="tracking-value-qr" value="<?php echo $selectedMethod === 'qr' ? htmlspecialchars($trackingValue, ENT_QUOTES, 'UTF-8') : ''; ?>" <?php echo $selectedMethod === 'reference' ? 'disabled' : ''; ?> />
              </div>
            </div>

            <button type="submit" class="track-button">Track Document</button>

            <p class="helper-text">Need help? Use your assigned reference number or scan the QR code.</p>
          </form>

          <?php if ($errorMessage !== ''): ?>
            <div class="status-message error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php elseif ($documentResult !== null): ?>
            <div class="result-card">
              <div class="result-header">
                <span class="result-badge">Document Found</span>
                <span class="result-status"><?php echo htmlspecialchars($documentResult['status'], ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <h3><?php echo htmlspecialchars($documentResult['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
              <div class="meta-grid">
                <div>
                  <span class="meta-label"><?php echo $selectedMethod === 'qr' ? 'QR Code' : 'Reference'; ?></span>
                  <strong><?php echo htmlspecialchars($trackingValue, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>
                  <span class="meta-label">Destination Office</span>
                  <strong><?php echo htmlspecialchars($documentResult['department'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>
                  <span class="meta-label">Current Location</span>
                  <strong><?php echo htmlspecialchars($documentResult['location'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>
                  <span class="meta-label">Last Updated</span>
                  <strong><?php echo htmlspecialchars($documentResult['updated'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <?php if ($isDatabaseDocument): ?>
                  <div>
                    <span class="meta-label">Document Type</span>
                    <strong><?php echo htmlspecialchars($documentResult['document_type'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  </div>
                  <div>
                    <span class="meta-label">Priority</span>
                    <strong><?php echo htmlspecialchars($documentResult['priority'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  </div>
                  <div>
                    <span class="meta-label">Created</span>
                    <strong><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($documentResult['created_at'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($isDatabaseDocument && trim((string) ($documentResult['description'] ?? '')) !== ''): ?>
                <p class="document-description"><?php echo nl2br(htmlspecialchars($documentResult['description'], ENT_QUOTES, 'UTF-8')); ?></p>
              <?php endif; ?>
              <?php if ($isDatabaseDocument): ?>
                <?php if ($isAuthenticated && $userOffice !== '' && $userOffice !== 'Department not set'): ?>
                  <form class="document-decision-form" method="post" action="track_document.php">
                    <input type="hidden" name="qr_code" value="<?php echo htmlspecialchars($documentResult['qr_code'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($trackingCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <p>Choose a decision for this verified document. The decision will be saved with your account and registered office.</p>
                    <div class="document-decision-actions">
                      <button class="document-decision-button approve" type="submit" name="action" value="approve">
                        <span aria-hidden="true">&#10003;</span> Approve
                      </button>
                      <button class="document-decision-button reject" type="submit" name="action" value="reject">
                        <span aria-hidden="true">&#10005;</span> Reject
                      </button>
                    </div>
                  </form>
                <?php elseif (!$isAuthenticated): ?>
                  <p class="document-decision-login"> <a href="login.php">Log in</a> with a staff account to approve or reject this document.</p>
                <?php else: ?>
                  <p class="document-decision-login">A registered department is required to approve or reject this document.</p>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>
      </main>

    </div>

    <div id="qr-scanner-modal" class="qr-scanner-modal hidden" aria-hidden="true">
      <div class="qr-scanner-backdrop" data-close-qr-scan></div>
      <div class="qr-scanner-dialog" role="dialog" aria-modal="true" aria-labelledby="qrScannerTitle">
        <button type="button" class="qr-scanner-close" id="qr-scan-close" aria-label="Close scanner">&times;</button>
        <h2 id="qrScannerTitle">Scan QR code</h2>
        <p class="qr-scanner-hint">Allow camera access, then point it at the QR code.</p>
        <div id="qr-reader"></div>
        <p id="qr-scanner-status" class="qr-scanner-status" role="status"></p>
      </div>
    </div>

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="script.js"></script>
  </body>
</html>
