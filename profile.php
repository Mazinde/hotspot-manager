<?php
session_start();

if (!isset($_SESSION['mikrotik_host'])) {
    header('Location: index.php');
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

try {
    $client = new \RouterOS\Client([
        'host' => $_SESSION['mikrotik_host'],
        'user' => $_SESSION['mikrotik_user'],
        'pass' => $_SESSION['mikrotik_pass'],
        'port' => $_SESSION['mikrotik_port'],
        'timeout' => 3,
    ]);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

$message = '';
$error = '';
$editProfile = null;

// ======================== DELETE ===========================
if (isset($_GET['delete'])) {
    $delName = $_GET['delete'];
    try {
        // Step 1: find profile by name
        $query = new \RouterOS\Query('/ip/hotspot/user/profile/print');
        $query->where('name', $delName);
        $existing = $client->query($query)->read();

        if (!empty($existing)) {
            $profileId = $existing[0]['.id'] ?? null;

            if ($profileId) {
                // Step 2: remove using .id
                $remove = new \RouterOS\Query('/ip/hotspot/user/profile/remove');
                $remove->equal('numbers', $profileId);
                $client->query($remove)->read();

                $message = "Profile '{$delName}' deleted successfully!";
            } else {
                $error = "Could not find valid ID for profile '{$delName}'.";
            }
        } else {
            $error = "Profile '{$delName}' not found.";
        }
    } catch (Exception $e) {
        $error = "Error deleting profile: " . $e->getMessage();
    }
    // Redirect to clear GET variables and see results
    header('Location: profile.php');
    exit;
}

// ======================== EDIT (LOAD EXISTING DATA) ===========================
if (isset($_GET['edit'])) {
    $editName = $_GET['edit'];
    try {
        $query = new \RouterOS\Query('/ip/hotspot/user/profile/print')->where('name', $editName);
        $existing = $client->query($query)->read();
        if ($existing) {
            $editProfile = $existing[0];
        } else {
            $error = "Profile '{$editName}' not found.";
        }
    } catch (Exception $e) {
        $error = "Error fetching profile: " . $e->getMessage();
    }
}

// ======================== CREATE / UPDATE ===========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $rxLimit = trim($_POST['rx_limit'] ?? '');
    $txLimit = trim($_POST['tx_limit'] ?? '');
    $sharedUsers = (int) ($_POST['shared_users'] ?? 1);
    $sessionTimeout = trim($_POST['session_timeout'] ?? '');
    $idleTimeout = trim($_POST['idle_timeout'] ?? '5m');
    $keepaliveTimeout = trim($_POST['keepalive_timeout'] ?? '');
    $editing = $_POST['editing'] ?? ''; // hidden field for edit mode

    if (!$name) {
        $error = "Profile Name is required";
    } else {
        try {
            $query = new \RouterOS\Query('/ip/hotspot/user/profile/print')->where('name', $name);
            $existing = $client->query($query)->read();

            if ($editing && $existing) {
                $update = new \RouterOS\Query('/ip/hotspot/user/profile/set');
                $update->equal('.id', $existing[0]['.id']);
            } else {
                $update = new \RouterOS\Query('/ip/hotspot/user/profile/add');
            }

            // Only add rate-limit if both are specified (optional field)
            if ($rxLimit || $txLimit) {
                $rate = ($rxLimit ?? '0') . "/" . ($txLimit ?? '0');
                $update->equal('rate-limit', $rate);
            }
            
            $update->equal('shared-users', $sharedUsers);
            if ($sessionTimeout) $update->equal('session-timeout', $sessionTimeout);
            if ($idleTimeout) $update->equal('idle-timeout', $idleTimeout);
            if ($keepaliveTimeout) $update->equal('keepalive-timeout', $keepaliveTimeout);
            $update->equal('name', $name);

            $client->query($update)->read();

            $message = $editing
                ? "Profile '{$name}' updated successfully!"
                : "Profile '{$name}' created successfully!";

            header("Location: profile.php");
            exit;
        } catch (Exception $e) {
            $error = "Error saving profile: " . $e->getMessage();
        }
    }
}

// ======================== FETCH ALL PROFILES ===========================
$profiles = [];
try {
    $profiles = $client->query('/ip/hotspot/user/profile/print')->read();
} catch (Exception $e) {
    $error = "Error fetching profiles: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MikroTik Dashboard - Profiles</title>
<style>
    /* Define footer height once for easy calculation (Footer has 10px vertical padding, total height is approx 40px) */
:root {
    --footer-height: 40px; 
}
/* --- Dashboard Styling Start --- */
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
/* --- Dashboard Styling End --- */

/* --- Profile Specific Styling --- */
input[type=text], input[type=number] { 
    width:100%; 
    padding:8px; 
    margin-bottom:8px; 
    box-sizing:border-box; 
}
button { 
    padding:10px 16px; 
    border:none; 
    border-radius:6px; 
    background:#2b75d9; 
    color:#fff; 
    cursor:pointer; 
}
button:hover { background:#1a4fa0; }

.message { padding:10px; border-radius:6px; margin-bottom:15px; }
.success-msg { background:#d4edda; color:#155724; }
.error-msg { background:#f8d7da; color:#721c24; }

table { 
    width:100%; 
    border-collapse:collapse; 
    margin-top:12px; 
}
th, td { 
    border:1px solid #ddd; 
    padding:8px; 
    text-align:left; 
}
th { 
    background:#2b75d9; 
    color:#fff; 
}
a.action { 
    margin-right:6px; 
    color:#2b75d9; 
    text-decoration:none; 
    font-weight:bold; 
}
a.action.delete {
    color: #dc3545;
}
a.action:hover { text-decoration:underline; }
.rate-limit-inputs { display:flex; gap:10px; }
.rate-limit-inputs input { width:50%; }

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
    <div class="header">Manage Hotspot User Profiles</div>

    <?php if($message): ?><div class="message success-msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if($error): ?><div class="message error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
        <h2><?= $editProfile ? 'Edit Profile' : 'Create New Profile' ?></h2>
        <form method="post">
            <input type="hidden" name="editing" value="<?= $editProfile ? '1' : '' ?>">
            
            <label>Profile Name</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($editProfile['name'] ?? '') ?>" <?= $editProfile ? 'readonly' : '' ?>>

            <label>Rate Limit (rx/tx - e.g., 512k/2M)</label>
            <?php
                $rate = $editProfile['rate-limit'] ?? '';
                // Split the rate-limit string (e.g., "512k/2M") into two parts
                // array_pad ensures we have at least two elements, even if only one is present or if the string is empty
                [$rx, $tx] = array_pad(explode('/', $rate), 2, '');
            ?>
            <div class="rate-limit-inputs">
                <input type="text" name="rx_limit" placeholder="Rx limit (Download)" value="<?= htmlspecialchars($rx) ?>">
                <input type="text" name="tx_limit" placeholder="Tx limit (Upload)" value="<?= htmlspecialchars($tx) ?>">
            </div>

            <label>Shared Users</label>
            <input type="number" name="shared_users" min="1" value="<?= htmlspecialchars($editProfile['shared-users'] ?? 1) ?>">

            <label>Session Timeout (e.g., 1h, 30m, 1d)</label>
            <input type="text" name="session_timeout" value="<?= htmlspecialchars($editProfile['session-timeout'] ?? '') ?>">

            <label>Idle Timeout (e.g., 5m, 10m)</label>
            <input type="text" name="idle_timeout" value="<?= htmlspecialchars($editProfile['idle-timeout'] ?? '5m') ?>">

            <label>Keepalive Timeout</label>
            <input type="text" name="keepalive_timeout" value="<?= htmlspecialchars($editProfile['keepalive-timeout'] ?? '') ?>">

            <button type="submit"><?= $editProfile ? 'Update Profile' : 'Save Profile' ?></button>
            <?php if($editProfile): ?>
                <a href="profile.php" style="margin-left:10px; color:#c00; text-decoration:none;">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Existing Profiles</h2>
        <table>
            <tr>
                <th>Name</th>
                <th>Rate Limit</th>
                <th>Shared Users</th>
                <th>Session Timeout</th>
                <th>Idle Timeout</th>
                <th>Keepalive</th>
                <th>Actions</th>
            </tr>
            <?php foreach($profiles as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['rate-limit'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['shared-users'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['session-timeout'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['idle-timeout'] ?? '') ?></td>
                <td><?= htmlspecialchars($p['keepalive-timeout'] ?? '') ?></td>
                <td>
                    <a class="action" href="?edit=<?= urlencode($p['name']) ?>">Edit</a>
                    <?php if (($p['name'] ?? '') !== 'default'): ?>
                        <a class="action delete" href="?delete=<?= urlencode($p['name']) ?>" onclick="return confirm('WARNING: Deleting profile \'<?= htmlspecialchars($p['name']) ?>\' will affect all users currently assigned to it. Are you sure?');">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
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