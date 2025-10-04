<?php
session_start();

if (!isset($_SESSION['mikrotik_host'])) {
    header('Location: index.php');
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ==================== CONNECT TO MIKROTIK ====================
try {
    $client = new \RouterOS\Client([
        'host' => $_SESSION['mikrotik_host'],
        'user' => $_SESSION['mikrotik_user'],
        'pass' => $_SESSION['mikrotik_pass'],
        'port' => $_SESSION['mikrotik_port'],
        'timeout' => 5,
    ]);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

// ==================== FETCH FUNCTIONS ====================
function fetchHotspotServers($client)
{
    try {
        $q = new \RouterOS\Query('/ip/hotspot/print');
        $results = $client->query($q)->read();
        $servers = [];
        foreach ($results as $res) {
            if (isset($res['name'])) {
                $servers[] = $res['name'];
            }
        }
        return $servers;
    } catch (Exception $e) {
        return [];
    }
}

function fetchWalledGarden($client)
{
    try {
        $q = new \RouterOS\Query('/ip/hotspot/walled-garden/print');
        // Include proplist for better compatibility/speed if your client supports it
        $q->equal('.proplist', '.id,action,server,dst-host,comment'); 
        return $client->query($q)->read();
    } catch (Exception $e) {
        return [];
    }
}

$hotspotServers = fetchHotspotServers($client);
$entries = fetchWalledGarden($client);
$message = '';
$type = 'success'; // success, error, warning

// ==================== HANDLE BULK DELETE ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete']) && isset($_POST['selected'])) {
    $selected = $_POST['selected'];
    try {
        // RouterOS API allows removing multiple entries using 'numbers'
        $idList = implode(',', array_map('htmlspecialchars', $selected));
        $remove = new \RouterOS\Query('/ip/hotspot/walled-garden/remove');
        $remove->equal('numbers', $idList);
        $client->query($remove)->read();
        $message = count($selected) . " selected rules deleted successfully.";
    } catch (Exception $e) {
        $message = "Error deleting selected rules: " . $e->getMessage();
        $type = 'error';
    }
    header('Location: walledgarden.php');
    exit;
}

// ==================== HANDLE SINGLE DELETE ====================
if (isset($_GET['delete_id'])) {
    $id = $_GET['delete_id'];
    try {
        $remove = new \RouterOS\Query('/ip/hotspot/walled-garden/remove');
        $remove->equal('numbers', $id);
        $client->query($remove)->read();
        // Redirect to clear GET variables and reload the fresh list
        header('Location: walledgarden.php');
        exit;
    } catch (Exception $e) {
        // Fall through to display error message
        $message = "Error deleting entry: " . $e->getMessage();
        $type = 'error';
    }
}

// ==================== HANDLE ADD / EDIT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rule'])) {
    $action = trim($_POST['action_type']);
    $server = trim($_POST['server']);
    $dst = trim($_POST['dst_host']);
    $id = $_POST['edit_id'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if ($dst === '') {
        $message = "Destination Host is required.";
        $type = 'warning';
    } else {
        try {
            if ($id === '') {
                $q = new \RouterOS\Query('/ip/hotspot/walled-garden/add');
                $q->equal('action', strtolower($action))
                  ->equal('server', $server)
                  ->equal('dst-host', $dst)
                  ->equal('comment', $comment);
                $client->query($q)->read();
                $message = "New Walled Garden rule added.";
            } else {
                $set = new \RouterOS\Query('/ip/hotspot/walled-garden/set');
                $set->equal('.id', $id)
                    ->equal('action', strtolower($action))
                    ->equal('server', $server)
                    ->equal('dst-host', $dst)
                    ->equal('comment', $comment);
                $client->query($set)->read();
                $message = "Walled Garden rule updated.";
            }
            // Redirect after successful save/update
            header('Location: walledgarden.php');
            exit;

        } catch (Exception $e) {
            $message = "Error saving: " . $e->getMessage();
            $type = 'error';
        }
    }
}

// ==================== HANDLE EDIT LOAD (after post attempt or from GET) ====================
$editData = null;
if (isset($_GET['edit_id'])) {
    $editId = $_GET['edit_id'];
    // Re-fetch entries if a post was made and failed, otherwise use existing list
    if (empty($entries) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $entries = fetchWalledGarden($client); 
    }
    
    foreach ($entries as $item) {
        if (($item['.id'] ?? '') === $editId) {
            $editData = $item;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Walled Garden Management</title>
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
    padding:15px 20px; /* Adjusted padding to match dashboard */
    border-radius:8px; 
    box-shadow:0 2px 5px rgba(0,0,0,0.1); 
    margin-bottom:15px; /* Adjusted margin to match dashboard */
}
/* --- Dashboard Styling End --- */

/* --- Walled Garden Specific Styling --- */
input[type=text], select { 
    width:100%; 
    padding:8px; 
    margin-bottom:10px; 
    box-sizing:border-box; 
    border: 1px solid #ccc; /* Added border for clarity */
    border-radius: 4px;
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
.success { background:#d4edda; color:#155724; }
.error { background:#f8d7da; color:#721c24; }
.warning { background:#fff3cd; color:#856404; }
.table { width:100%; border-collapse:collapse; margin-top:10px; }
.table th, .table td { padding:8px; border:1px solid #ccc; text-align:left; }
.table th { background:#2b75d9; color:#fff; }
.actions a { margin-right:8px; color:#2b75d9; text-decoration:none; font-weight:bold; }
.actions a:last-child { color: #dc3545; } /* Red for delete */
.actions a:hover { text-decoration: underline; }
.bulk-actions { margin-top:10px; }

.allow-row { background-color: #f8fcf8; }
.deny-row { background-color: #fff8f8; }


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
<script>
function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('input[name="selected[]"]');
    checkboxes.forEach(cb => cb.checked = source.checked);
}
</script>
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
    <div class="header">MikroTik Walled Garden Management</div>

    <?php if ($message): ?>
        <div class="message <?= $type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3><?= $editData ? 'Edit Rule' : 'Add New Rule' ?></h3>
        <form method="post">
            <?php if ($editData): ?>
                <input type="hidden" name="edit_id" value="<?= htmlspecialchars($editData['.id']) ?>">
            <?php endif; ?>

            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <label>Action:</label>
                    <select name="action_type" required>
                        <option value="allow" <?= ($editData && strtolower($editData['action'] ?? '')==='allow')?'selected':'' ?>>Allow</option>
                        <option value="deny" <?= ($editData && strtolower($editData['action'] ?? '')==='deny')?'selected':'' ?>>Deny</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label>Server (Optional):</label>
                    <select name="server">
                        <option value="">-- All Hotspots --</option>
                        <?php foreach ($hotspotServers as $server): ?>
                            <option value="<?= htmlspecialchars($server) ?>"
                                <?= ($editData && ($editData['server'] ?? '') === $server) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($server) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label>Dst. Host (Host/Domain/IP):</label>
            <input type="text" name="dst_host" value="<?= htmlspecialchars($editData['dst-host'] ?? '') ?>" placeholder="e.g. example.com or 1.1.1.1" required>
            
            <label>Comment (Optional):</label>
            <input type="text" name="comment" value="<?= htmlspecialchars($editData['comment'] ?? '') ?>" placeholder="e.g. Free access for Facebook">

            <button type="submit" name="save_rule"><?= $editData ? 'Update Rule' : 'Save Rule' ?></button>
            <?php if ($editData): ?>
                <a href="walledgarden.php" style="margin-left:12px;color:#c00; text-decoration:none;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h3>Existing Walled Garden Rules</h3>
        <form method="post">
            <table class="table">
                <thead>
                    <tr>
                        <th><input type="checkbox" onclick="toggleSelectAll(this)"></th>
                        <th>#</th>
                        <th>Action</th>
                        <th>Server</th>
                        <th>Dst. Host</th>
                        <th>Comment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 0;
                    foreach ($entries as $entry):
                        $i++;
                        $action_class = strtolower($entry['action'] ?? '') === 'allow' ? 'allow-row' : 'deny-row';
                    ?>
                    <tr class="<?= $action_class ?>">
                        <td><input type="checkbox" name="selected[]" value="<?= htmlspecialchars($entry['.id']) ?>"></td>
                        <td><?= $i ?></td>
                        <td><?= htmlspecialchars($entry['action'] ?? '') ?></td>
                        <td><?= htmlspecialchars($entry['server'] ?? 'all') ?></td>
                        <td><?= htmlspecialchars($entry['dst-host'] ?? '') ?></td>
                        <td><?= htmlspecialchars($entry['comment'] ?? '') ?></td>
                        <td class="actions">
                            <a href="?edit_id=<?= urlencode($entry['.id']) ?>">Edit</a>
                            <a href="?delete_id=<?= urlencode($entry['.id']) ?>" onclick="return confirm('Delete this rule?');">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($i === 0): ?>
                        <tr><td colspan="7">No rules found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($i > 0): ?>
            <div class="bulk-actions">
                <button type="submit" name="bulk_delete" class="delete-button" onclick="return confirm('Delete all selected rules?');">Delete Selected</button>
            </div>
            <?php endif; ?>
        </form>
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