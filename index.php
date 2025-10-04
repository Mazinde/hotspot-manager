<?php
// index.php - MikroTik Quick Connect + Gateway Detection

session_start(); // start session to store credentials

// Include Composer autoload if exists
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$message = '';
$error = '';
$connected = false;
$response = null;

// Detect server gateway immediately for pre-filling the host field
$serverGateway = null;
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $output = shell_exec('ipconfig');
    if (preg_match('/Default Gateway[ .]*: ([0-9\.]+)/', $output, $matches)) {
        $serverGateway = $matches[1];
    }
} else {
    $output = shell_exec("ip route | grep default");
    if (preg_match('/default via ([0-9\.]+)/', $output, $matches)) {
        $serverGateway = $matches[1];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? '');
    $port = (int) ($_POST['port'] ?? 8728);
    $user = trim($_POST['user'] ?? '');
    $pass = trim($_POST['pass'] ?? '');

    if ($host === '' || $user === '' || $pass === '') {
        $error = 'Host, user and password are required.';
    } else {
        if (!class_exists('\RouterOS\Client')) {
            $error = "RouterOS PHP client not found. Install it with Composer:\ncomposer require evilfreelancer/routeros-api-php";
        } else {
            try {
                $client = new \RouterOS\Client([
                    'host' => $host,
                    'user' => $user,
                    'pass' => $pass,
                    'port' => $port,
                    'timeout' => 3,
                ]);

                $query = new \RouterOS\Query('/system/resource/print');
                $response = $client->query($query)->read();

                $connected = true;
                $message = "Connected successfully to MikroTik at {$host}:{$port}.";

                // store credentials in session for dashboard.php
                $_SESSION['mikrotik_host'] = $host;
                $_SESSION['mikrotik_port'] = $port;
                $_SESSION['mikrotik_user'] = $user;
                $_SESSION['mikrotik_pass'] = $pass;

                // redirect to dashboard page
                header('Location: dashboard.php');
                exit;

            } catch (Exception $e) {
                $error = "Connection failed: " . $e->getMessage();
            }
        }
    }
}

// Handle AJAX gateway fetch (optional for JS)
if (isset($_GET['fetch']) && $_GET['fetch'] === 'gateway') {
    header('Content-Type: application/json');
    echo json_encode(['gateway' => $serverGateway]);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>MikroTik Quick Connect + Gateway Info</title>
<style>
  body { font-family: Arial, sans-serif; padding: 20px; max-width: 820px; margin: auto; }
  .card { border: 1px solid #ddd; padding: 18px; border-radius: 8px; margin-bottom: 12px; }
  label { display:block; margin: 8px 0 4px; font-weight:600; }
  input[type=text], input[type=password], input[type=number] { width:100%; padding:8px; box-sizing:border-box; }
  .row { display:flex; gap:12px; }
  .row > div { flex:1; }
  .btn { background:#2b75d9;color:#fff;padding:10px 14px;border:none;border-radius:6px;cursor:pointer;margin-top:12px; }
  .success { color: #0b6; }
  .danger { color: #c00; white-space:pre-wrap; }
  small { color:#666; display:block; margin-top:6px; }
  .hint { margin-top:8px; color:#444; font-size:0.95em; }
  /* --- Footer Styling --- */
.footer {
    width: 100%;
    padding: 10px 0;
    background: #1a4fa0; /* Darker blue, similar to hover/active sidebar state */
    color: #fff;
    text-align: center;
    font-size: 0.85em;
    position: fixed; /* Keep it at the bottom of the viewport */
    bottom: 0;
    left: 0;
    box-shadow: 0 -2px 5px rgba(0, 0, 0, 0.1);
    z-index: 100; /* Ensure it stays above other content */
    display: flex;
    justify-content: space-around;
    align-items: center;
}

.footer-content, .footer-note {
    padding: 0 15px;
}

.footer-note {
    color: #cccccc;
    font-size: 0.8em;
}

/* Ensure main content doesn't get covered by the fixed footer */
.main {
    /* Existing styles, plus enough padding-bottom to clear the footer height (e.g., 40px) */
    padding-bottom: 45px; 
}

/* Responsive adjustment for footer */
@media (max-width: 768px) {
    .footer {
        flex-direction: column;
        padding: 8px 0;
    }
    .footer-content, .footer-note {
        padding: 2px 10px;
    }
    .main {
        /* Increase padding on mobile since the sidebar is no longer on the side */
        padding-bottom: 70px; 
    }
}
</style>
</head>
<body>

<h1>MikroTik Quick Connect</h1>

<div class="card">
  <h3>Detected Network Gateway</h3>
  <p id="gateway"><?= $serverGateway ? "Gateway IP: {$serverGateway}" : "Could not detect gateway."; ?></p>
</div>

<div class="card">

  <form method="post" id="connectForm">
    <label for="host">Host Gateway</label>
    <input type="text" id="host" name="host" placeholder="Auto-detected gateway/router IP..." 
           value="<?= htmlspecialchars($_POST['host'] ?? $serverGateway ?? '') ?>" required>

    <div class="row">
      <div>
        <label for="port">Port</label>
        <input type="number" id="port" name="port" value="<?= htmlspecialchars($_POST['port'] ?? '8728') ?>" min="1" max="65535" required>
      </div>
      <div>
        <label for="user">Username</label>
        <input type="text" id="user" name="user" value="<?= htmlspecialchars($_POST['user'] ?? '') ?>" required>
      </div>
    </div>

    <label for="pass">Password</label>
    <input type="password" id="pass" name="pass" value="<?= htmlspecialchars($_POST['pass'] ?? '') ?>" required>

    <button class="btn" type="submit">Connect</button>
    <div id="detectStatus" class="hint"></div>
  </form>

  <?php if ($error): ?>
    <p class="danger card"><?= nl2br(htmlspecialchars($error)) ?></p>
  <?php endif; ?>
</div>

<script>
(async function() {
  const hostInput = document.getElementById('host');
  const statusEl = document.getElementById('detectStatus');

  // disable submit while connecting
  document.getElementById('connectForm').addEventListener('submit', () => {
    const btn = document.querySelector('button[type=submit]');
    btn.disabled=true; btn.textContent='Connecting…';
  });

})();
</script>

</body>
<div class="footer">
    <div class="footer-content">
        &copy; <?= date('Y') ?> African Child Projects | Connected to: <?= htmlspecialchars($_SESSION['mikrotik_host'] ?? 'N/A') ?>
    </div>
    <div class="footer-note">
        Version 1.0. | Technical Team
    </div>
</div>
</html>
