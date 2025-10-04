<?php
session_start();

// Check if user is connected
if (!isset($_SESSION['mikrotik_host'])) {
    header('Location: index.php');
    exit;
}

$host = $_SESSION['mikrotik_host'];
$user = $_SESSION['mikrotik_user'];

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$metrics = [
    'total_profiles' => 0,
    'total_vouchers' => 0,
    'walled_garden_allowed' => 0,
    'walled_garden_denied' => 0,
    'error' => null
];

// Connect to MikroTik
try {
    $client = new \RouterOS\Client([
        'host' => $_SESSION['mikrotik_host'],
        'user' => $_SESSION['mikrotik_user'],
        'pass' => $_SESSION['mikrotik_pass'],
        'port' => $_SESSION['mikrotik_port'],
        'timeout' => 5,
    ]);

    // 1. Profiles
    $profilesQuery = new \RouterOS\Query('/ip/hotspot/user/profile/print');
    $profiles = $client->query($profilesQuery)->read();
    $metrics['total_profiles'] = count($profiles);

    // 2. Vouchers (Hotspot Users)
    $voucherQuery = new \RouterOS\Query('/ip/hotspot/user/print');
    $vouchers = $client->query($voucherQuery)->read();
    $metrics['total_vouchers'] = count($vouchers);

    // 3. Walled Garden (CORRECTED QUERY)
    // The Walled Garden is managed under /ip/hotspot/walled-garden, not firewall address-list
    $wgQuery = new \RouterOS\Query('/ip/hotspot/walled-garden/print');
    $walledGardenEntries = $client->query($wgQuery)->read();
    
    foreach ($walledGardenEntries as $entry) {
        if (($entry['action'] ?? '') === 'allow') {
            $metrics['walled_garden_allowed']++;
        } elseif (($entry['action'] ?? '') === 'deny') {
            $metrics['walled_garden_denied']++;
        }
    }
    
} catch (Exception $e) {
    $metrics['error'] = "API Error: " . $e->getMessage();
}

// Calculate total Walled Garden rules for the combined metric
$totalWalledRules = $metrics['walled_garden_allowed'] + $metrics['walled_garden_denied'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MikroTik Dashboard</title>
<style>
/* Define footer height once for easy calculation (Footer has 10px vertical padding, total height is approx 40px) */
:root {
    --footer-height: 40px; 
}

body {
    margin:0;
    font-family: Arial, sans-serif;
    display:flex;
    height:100vh;
}
.sidebar {
    width: 220px;
    background: #2b75d9;
    color: #fff;
    display: flex;
    flex-direction: column;
    padding-top: 20px;
    
    /* FIX: Calculate height to stop exactly above the fixed footer */
    height: calc(98vh - var(--footer-height)); 
    overflow-y: auto; /* Allows scrolling if menu is too long */
    
    /* REMOVED: padding-bottom: 40px; - This is no longer needed */
}
.sidebar a {
    color:#fff;
    text-decoration:none;
    padding:12px 20px;
    display:block;
    transition: background 0.3s;
}
.sidebar a:hover {
    background:#1a4fa0;
}
.sidebar a.active {
    background:#1a4fa0;
    font-weight:bold;
}

.main {
    flex: 1; 
    padding: 20px;
    background: #f4f4f4;
    overflow: auto;
    
    /* Ensure bottom padding accounts for the fixed footer height */
    padding-bottom: 45px; /* Keep this to ensure scrollable content clears the footer */
}
.header {
    font-size:1.5em;
    margin-bottom:20px;
}
.card {
    background:#fff;
    padding:15px 20px;
    border-radius:8px;
    box-shadow:0 2px 5px rgba(0,0,0,0.1);
    margin-bottom:15px;
}
.card ul {
    list-style:none;
    padding-left:0;
}
.card ul li {
    margin-bottom:8px;
}
.card ul li a {
    color:#2b75d9;
    text-decoration:none;
    font-weight:bold;
}
.card ul li a:hover {
    text-decoration:underline;
}
.metrics {
    display:flex;
    flex-wrap: wrap; /* Added for responsiveness */
    gap:15px; /* Adjusted gap */
    margin-bottom: 15px;
}
/* Updated metric card styling for a cleaner look */
.metric-card {
    flex:1 1 200px; /* Allows 3-4 cards per row */
    background:#fff; /* Changed to white background */
    border: 1px solid #ddd;
    padding:15px; /* Reduced padding slightly */
    border-radius:8px;
    text-align:center;
    box-shadow:0 2px 5px rgba(0,0,0,0.1);
}
.metric-card h2 {
    font-size:2.2em;
    margin:0;
    color: #2b75d9; /* Kept main color for primary metrics */
}
.metric-card p {
    margin:5px 0 0 0;
    font-size:0.9em;
    color: #6c757d;
}
.metric-card.allowed h2 { color: #28a745; } /* Green for allowed */
.metric-card.denied h2 { color: #dc3545; } /* Red for denied */
.error-message {
    padding: 10px;
    background: #f8d7da;
    color: #721c24;
    border-radius: 6px;
    margin-bottom: 15px;
}
/* --- Footer Styling --- */
.footer {
    width: 100%;
    /* Update height for calculation based on the padding in the footer CSS: 
       If the footer is 10px padding top/bottom, it's about 40px total height. 
       Setting explicit height and line-height handles vertical centering cleanly. */
    height: var(--footer-height); 
    padding: 0; /* Remove vertical padding since height is explicit */
    
    background: #1a4fa0; 
    color: #fff;
    text-align: center;
    font-size: 0.85em;
    position: fixed; 
    bottom: 0;
    left: 0;
    box-shadow: 0 -2px 5px rgba(0, 0, 0, 0.1);
    z-index: 100;
    display: flex;
    justify-content: space-around;
    align-items: center;
}

.footer-content, .footer-note {
    /* Use line-height to center text, or set specific padding as before */
    line-height: var(--footer-height); 
    /* Padding was 0 15px before, keeping horizontal padding */
    padding: 0 15px; 
}
.footer-note {
    color: #cccccc;
    font-size: 0.8em;
    line-height: var(--footer-height);
}

/* Responsive adjustment for footer */
@media (max-width: 768px) {
    /* Adjust footer height for mobile if you changed padding: 8px 0; */
    :root {
        --mobile-footer-height: 50px; /* Estimated height for stacked content */
    }
    
    .footer {
        flex-direction: column;
        padding: 8px 0;
        height: auto; /* Allow height to adjust for stacked content */
    }
    .footer-content, .footer-note {
        padding: 2px 10px;
        line-height: normal; /* Reset line-height */
    }
    .main {
        /* Increase padding on mobile to clear the taller footer */
        padding-bottom: 70px; 
    }
    
    .sidebar {
        /* Sidebar on mobile should take full width and stack horizontally */
        height: auto;
    }
}
</style>
</head>
<body>

<div class="sidebar">
    <a href="dashboard.php">Dashboard</a>
    <a href="voucher.php">Voucher</a>
    <a href="profile.php">Profile</a>
    <a href="walledgarden.php">Walled Garden</a>
    
    <a href="logout.php" style="margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.2);">
        Logout 🚪
    </a>
</div>

<div class="main">
    <div class="header">Welcome to MikroTik Management Dashboard</div>

    <?php if ($metrics['error']): ?>
        <div class="error-message">
            ⚠️ Error fetching data: <?= htmlspecialchars($metrics['error']) ?>
        </div>
    <?php endif; ?>

    <div class="metrics">
        <div class="metric-card">
            <h2><?= $metrics['total_profiles'] ?></h2>
            <p>Profiles Created</p>
        </div>
        <div class="metric-card">
            <h2><?= $metrics['total_vouchers'] ?></h2>
            <p>Vouchers Created</p>
        </div>
        <div class="metric-card">
            <h2><?= $totalWalledRules ?></h2>
            <p>Total Walled Garden Rules</p>
        </div>
        <div class="metric-card allowed">
            <h2><?= $metrics['walled_garden_allowed'] ?></h2>
            <p>WG Rules (Allowed)</p>
        </div>
        <div class="metric-card denied">
            <h2><?= $metrics['walled_garden_denied'] ?></h2>
            <p>WG Rules (Denied)</p>
        </div>
    </div>

    <div class="card">
        <strong>Connected Router:</strong> <?= htmlspecialchars($host) ?><br>
        <strong>Username:</strong> <?= htmlspecialchars($user) ?><br>
        <small>You can manage vouchers, profiles, and walled garden access from the menu on the left.</small>
    </div>

    <div class="card">
        <h3>Quick Links</h3>
        <ul>
            <li><a href="voucher.php">🎟️ Manage Vouchers</a></li>
            <li><a href="profile.php">👤 Edit User Profiles</a></li>
            <li><a href="walledgarden.php">🌐 Manage Walled Garden</a></li>
        </ul>
    </div>
</div>
<div class="footer">
    <div class="footer-content">
        &copy; <?= date('Y') ?> African Child Projects | Connected to: <?= htmlspecialchars($_SESSION['mikrotik_host'] ?? 'N/A') ?>
    </div>
    <div class="footer-note">
        Version 1.0. | Technical Team
    </div>
</div>
</body>
</html>