<?php
session_start();

if (!isset($_SESSION['mikrotik_host'])) {
    header('Location: index.php');
    exit;
}

// include MikroTik API client
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// connect to MikroTik API
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

// Load available profiles (for dropdown)
$profiles = [];
try {
    $profileQuery = new \RouterOS\Query('/ip/hotspot/user/profile/print');
    $profiles = $client->query($profileQuery)->read();
} catch (Exception $e) {
    $profiles = [];
}

// Helper: fetch all hotspot users (we use this to list, group by comment (batch), etc.)
function fetchAllHotspotUsers($client) {
    try {
        $q = new \RouterOS\Query('/ip/hotspot/user/print')->equal('.proplist','.id,name,password,profile,comment');
        $res = $client->query($q)->read();
        return $res;
    } catch (Exception $e) {
        return [];
    }
}

$allUsers = fetchAllHotspotUsers($client);

// Build list of distinct batches (comments)
$batches = [];
foreach ($allUsers as $u) {
    $c = trim($u['comment'] ?? '');
    if ($c !== '') $batches[$c] = $c;
}
ksort($batches);

// Messages and storage for preview
$message = '';
$message_type = 'success'; // success|error|warning
$createdVouchers = [];

// ====================================================================================
// ===== Handle Export (From Batch Filter) ============================================
// ====================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export_batch') {
    $batchToExport = trim($_POST['batch_name_to_export'] ?? '');

    if ($batchToExport !== '') {
        // 1. Find all users in the batch
        $vouchersToExport = [];
        foreach ($allUsers as $user) {
            if (trim($user['comment'] ?? '') === $batchToExport) {
                $vouchersToExport[] = [
                    'username' => $user['name'] ?? '',
                    'password' => $user['password'] ?? '',
                    'profile' => $user['profile'] ?? '',
                ];
            }
        }

        if (!empty($vouchersToExport)) {
            // Store the data in the session temporarily and redirect to the PDF generation script
            $_SESSION['vouchers_to_print'] = $vouchersToExport;
            $_SESSION['vouchers_batch_name'] = $batchToExport;
            header('Location: export_pdf.php');
            exit;
        } else {
            $message = "No vouchers found in batch '{$batchToExport}' to export.";
            $message_type = 'warning';
        }
    } else {
        $message = "Batch name not specified for export.";
        $message_type = 'warning';
    }
}


// ===== Handle Delete (Individual from MikroTik) =====
if (isset($_GET['delete_id'])) {
    $delId = $_GET['delete_id'];
    try {
        // Remove by numbers (RouterOS accepts numbers for /remove)
        $remove = new \RouterOS\Query('/ip/hotspot/user/remove');
        $remove->equal('numbers', $delId);
        $client->query($remove)->read();
        $message = "Voucher deleted successfully.";
        $message_type = 'success';
        // refresh list, preserving batch filter if present
        $redirectUrl = 'voucher.php';
        if (isset($_GET['batch'])) {
            $redirectUrl .= '?batch=' . urlencode($_GET['batch']);
        }
        header('Location: ' . $redirectUrl);
        exit;
    } catch (Exception $e) {
        $message = "Error deleting voucher: " . $e->getMessage();
        $message_type = 'error';
    }
}

// ===== Handle Delete (BATCH from MikroTik) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_batch') {
    $batchToDelete = trim($_POST['batch_name_to_delete'] ?? '');

    if ($batchToDelete !== '') {
        try {
            // 1. Find all user IDs in the batch
            $findQuery = new \RouterOS\Query('/ip/hotspot/user/print');
            $findQuery->where('comment', $batchToDelete);
            // Request only the .id property
            $findQuery->equal('.proplist', '.id'); 
            $usersInBatch = $client->query($findQuery)->read();
            
            if (!empty($usersInBatch)) {
                $ids = array_column($usersInBatch, '.id');
                $idList = implode(',', $ids); // RouterOS API can remove multiple using 'numbers'

                // 2. Remove all users by their IDs
                $remove = new \RouterOS\Query('/ip/hotspot/user/remove');
                $remove->equal('numbers', $idList);
                $client->query($remove)->read();

                $message = count($ids) . " vouchers from batch '{$batchToDelete}' deleted successfully.";
                $message_type = 'success';
            } else {
               $message = "No vouchers found in batch '{$batchToDelete}' to delete.";
               $message_type = 'warning';
            }

            // Redirect back to the voucher page (without the batch filter as it's now deleted)
            header('Location: voucher.php');
            exit;

        } catch (Exception $e) {
            $message = "Error deleting batch: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "Batch name not specified for deletion.";
        $message_type = 'warning';
    }
}

// ===== Handle Edit LOAD (GET) =====
$editingUser = null;
if (isset($_GET['edit_id'])) {
    $editId = $_GET['edit_id'];
    try {
        $q = new \RouterOS\Query('/ip/hotspot/user/print')->where('.id', $editId);
        $res = $client->query($q)->read();
        if (!empty($res)) {
            $editingUser = $res[0];
        } else {
            $message = "Voucher to edit not found.";
            $message_type = 'warning';
        }
    } catch (Exception $e) {
        $message = "Error loading voucher for edit: " . $e->getMessage();
        $message_type = 'error';
    }
}

// ===== Handle Edit SUBMIT (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_submit') {
    $editId = $_POST['edit_id'] ?? '';
    $newName = trim($_POST['edit_username'] ?? '');
    $newPass = trim($_POST['edit_password'] ?? '');
    $newProfile = trim($_POST['edit_profile'] ?? '');
    try {
        // Use /ip/hotspot/user/set with .id for updating fields
        $set = new \RouterOS\Query('/ip/hotspot/user/set');
        $set->equal('.id', $editId);
        if ($newName !== '') $set->equal('name', $newName);
        if ($newPass !== '') $set->equal('password', $newPass);
        if ($newProfile !== '') $set->equal('profile', $newProfile);
        $client->query($set)->read();
        $message = "Voucher updated successfully.";
        $message_type = 'success';
        header('Location: voucher.php');
        exit;
    } catch (Exception $e) {
        $message = "Error updating voucher: " . $e->getMessage();
        $message_type = 'error';
    }
}

// ===== Handle Batch Create =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || !in_array($_POST['action'], ['edit_submit', 'delete_batch', 'export_batch']))) {
    // Batch fields
    $batchName = trim($_POST['batch_name'] ?? '');
    $numVouchers = intval($_POST['num_vouchers'] ?? 1);
    $profile = trim($_POST['profile'] ?? 'default');
    $userPrefix = trim($_POST['user_prefix'] ?? '');
    $userSuffix = trim($_POST['user_suffix'] ?? '');
    $passPrefix = trim($_POST['pass_prefix'] ?? '');
    $passSuffix = trim($_POST['pass_suffix'] ?? '');

    if ($numVouchers < 1) $numVouchers = 1;
    if ($numVouchers > 500) $numVouchers = 500; // safety cap

    if ($batchName === '') {
        $message = "Batch name is required.";
        $message_type = 'warning';
    } else {
        try {
            for ($i = 1; $i <= $numVouchers; $i++) {
                // create deterministic-ish unique parts (short)
                $randUser = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 5));
                $randPass = substr(md5(uniqid((string)mt_rand(), true)), 0, 6);

                $username = $userPrefix . $randUser . $userSuffix;
                $password = $passPrefix . $randPass . $passSuffix;

                $q = new \RouterOS\Query('/ip/hotspot/user/add');
                $q->equal('name', $username)
                  ->equal('password', $password)
                  ->equal('profile', $profile)
                  ->equal('comment', $batchName);

                $client->query($q)->read();

                $createdVouchers[] = [
                    'username' => $username,
                    'password' => $password,
                    'profile' => $profile,
                    'batch' => $batchName, // Added for potential session storage
                ];
            }
            $message = "Created {$numVouchers} vouchers in batch '{$batchName}'.";
            $message_type = 'success';
            
            // Store the newly created vouchers in the session for PDF export
            $_SESSION['vouchers_to_print'] = $createdVouchers;
            $_SESSION['vouchers_batch_name'] = $batchName;
            
            // Redirect to PDF immediately after creation
            header('Location: export_pdf.php');
            exit;

        } catch (Exception $e) {
            $message = "Error creating vouchers: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// ===== Filtering / Viewing by batch or showing all =====
$filterBatch = $_GET['batch'] ?? '';
$displayUsers = [];
if ($filterBatch !== '') {
    foreach ($allUsers as $u) {
        if (trim($u['comment'] ?? '') === $filterBatch) $displayUsers[] = $u;
    }
} else {
    $displayUsers = $allUsers;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Voucher Management</title>
<style>
/* --- Dashboard Styling Start --- */
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
    flex:1;
    padding:20px;
    background:#f4f4f4;
    overflow:auto;
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

/* --- Voucher Specific Styling --- */
input[type=text], input[type=number], select { 
    width:100%; 
    padding:8px; 
    margin-bottom:10px; 
    box-sizing:border-box; 
    border: 1px solid #ccc;
    border-radius: 4px;
}
button { 
    padding:10px 16px; 
    border:none; 
    border-radius:6px; 
    background:#2b75d9; 
    color:#fff; 
    cursor:pointer; 
    transition: background 0.3s;
}
button:hover { 
    background:#1a4fa0; 
}
.delete-button { 
    background:#dc3545; 
}
.delete-button:hover { 
    background:#c82333; 
}
.export-button {
    background:#28a745;
}
.export-button:hover {
    background:#1e7e34;
}
.message { 
    padding:10px; 
    border-radius:6px; 
    margin-bottom:15px; 
}
.success { 
    background:#d4edda; 
    color:#155724; 
}
.error { 
    background:#f8d7da; 
    color:#721c24; 
}
.warning { 
    background:#fff3cd; 
    color:#856404; 
}
.table { 
    width:100%; 
    border-collapse:collapse; 
    margin-top:10px; 
}
.table th, .table td { 
    padding:8px; 
    border:1px solid #ccc; 
    text-align:left; 
}
.table th { 
    background:#2b75d9; 
    color:#fff; 
}
.actions a { 
    margin-right:8px; 
    color:#2b75d9; 
    text-decoration:none; 
    font-weight:bold; 
}
.actions a:last-child { color: #dc3545; }
.small-muted { 
    font-size:0.9em; 
    color:#666; 
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
    <div class="header">Voucher Management (MikroTik)</div>

    <?php if ($message): ?>
        <div class="message <?= $message_type === 'success' ? 'success' : ($message_type === 'error' ? 'error' : 'warning') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Create Voucher Batch</h3>
        <form method="post">
            <label>Batch Name</label>
            <input type="text" name="batch_name" required placeholder="e.g. ACA-September-2025" value="<?= htmlspecialchars($_POST['batch_name'] ?? '') ?>">

            <label>Number of Vouchers</label>
            <input type="number" name="num_vouchers" min="1" max="500" value="<?= htmlspecialchars($_POST['num_vouchers'] ?? 10) ?>" required>

            <label>Profile (auto-loaded)</label>
            <select name="profile" required>
                <?php if (empty($profiles)): ?>
                    <option value="default">default</option>
                <?php else: ?>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= htmlspecialchars($p['name']) ?>" <?= ( (($_POST['profile'] ?? '') === $p['name']) || (!isset($_POST['profile']) && $p['name'] === 'default') ) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <div style="display:flex; gap:12px;">
                <div style="flex:1;">
                    <label>Username Prefix</label>
                    <input type="text" name="user_prefix" placeholder="e.g. ACA-" value="<?= htmlspecialchars($_POST['user_prefix'] ?? '') ?>">
                </div>
                <div style="flex:1;">
                    <label>Username Suffix</label>
                    <input type="text" name="user_suffix" placeholder="e.g. -2025" value="<?= htmlspecialchars($_POST['user_suffix'] ?? '') ?>">
                </div>
            </div>

            <div style="display:flex; gap:12px;">
                <div style="flex:1;">
                    <label>Password Prefix</label>
                    <input type="text" name="pass_prefix" placeholder="e.g. P-" value="<?= htmlspecialchars($_POST['pass_prefix'] ?? '') ?>">
                </div>
                <div style="flex:1;">
                    <label>Password Suffix</label>
                    <input type="text" name="pass_suffix" placeholder="e.g. -X" value="<?= htmlspecialchars($_POST['pass_suffix'] ?? '') ?>">
                </div>
            </div>

            <button type="submit">Generate & Save Vouchers</button>
        </form>

        <?php 
        // Note: The logic above redirects to export_pdf.php immediately after creation, 
        // so this "Preview" block might only show if there was an error in creation.
        // It's mainly here to illustrate what happened if the redirect failed.
        if (!empty($createdVouchers)): ?>
            <hr>
            <h4>Preview of created vouchers (batch preview)</h4>
            <table class="table">
                <tr><th>#</th><th>Username</th><th>Password</th><th>Profile</th></tr>
                <?php foreach ($createdVouchers as $i => $v): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($v['username']) ?></td>
                    <td><?= htmlspecialchars($v['password']) ?></td>
                    <td><?= htmlspecialchars($v['profile']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p class="small-muted">These vouchers were saved to the MikroTik hotspot users with the comment set to your batch name. You were automatically redirected to the PDF page.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Saved Vouchers</h3>
        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
            
            <form method="get" style="margin:0;">
                <label style="display:block;">Filter by Batch</label>
                <select name="batch" onchange="this.form.submit()">
                    <option value="">— All batches / all vouchers —</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= htmlspecialchars($b) ?>" <?= ($filterBatch === $b) ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            
            <?php if ($filterBatch !== ''): ?>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="export_batch">
                    <input type="hidden" name="batch_name_to_export" value="<?= htmlspecialchars($filterBatch) ?>">
                    <button type="submit" class="export-button">
                        <span style="font-weight:normal;">Export Selected Batch (<?= count($displayUsers) ?>) to PDF 🖨️</span>
                    </button>
                </form>

                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="delete_batch">
                    <input type="hidden" name="batch_name_to_delete" value="<?= htmlspecialchars($filterBatch) ?>">
                    <button type="submit" class="delete-button" 
                            onclick="return confirm('WARNING: This will permanently delete ALL <?= count($displayUsers) ?> vouchers in the batch \'<?= htmlspecialchars($filterBatch) ?>\' from MikroTik. Are you sure?');">
                        Delete Selected Batch (<?= count($displayUsers) ?>)
                    </button>
                </form>
            <?php endif; ?>

            <form method="get" style="margin-left:auto;">
                <label style="display:block;">Search username</label>
                <?php if ($filterBatch !== ''): ?>
                    <input type="hidden" name="batch" value="<?= htmlspecialchars($filterBatch) ?>">
                <?php endif; ?>
                <div style="display:flex; gap:5px;">
                    <input type="text" name="q" placeholder="username..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="margin-bottom:0; width:150px;">
                    <button type="submit">Search</button>
                </div>
            </form>

        </div>

        <table class="table" style="margin-top:12px;">
            <tr>
                <th>#</th>
                <th>Username</th>
                <th>Password</th>
                <th>Profile</th>
                <th>Batch</th>
                <th>Actions</th>
            </tr>
            <?php
            // Optionally apply username search
            $searchQ = trim($_GET['q'] ?? '');
            $count = 0;
            foreach ($displayUsers as $u) {
                if ($searchQ !== '' && stripos($u['name'] ?? '', $searchQ) === false) continue;
                $count++;
                
                // Construct delete link to maintain batch filter context after deletion
                $delete_link = "?delete_id=" . urlencode($u['.id']);
                if ($filterBatch !== '') {
                    $delete_link .= "&batch=" . urlencode($filterBatch);
                }
                ?>
                <tr>
                    <td><?= $count ?></td>
                    <td><?= htmlspecialchars($u['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['password'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['profile'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['comment'] ?? '') ?></td>
                    <td class="actions">
                        <a class="action" href="?edit_id=<?= urlencode($u['.id']) ?>">View / Edit</a>
                        <a class="action" href="<?= htmlspecialchars($delete_link) ?>" onclick="return confirm('Delete this voucher (<?= htmlspecialchars($u['name'] ?? '') ?>) from MikroTik?');">Delete</a>
                    </td>
                </tr>
            <?php } ?>
            <?php if ($count === 0): ?>
                <tr><td colspan="6" class="small-muted">No vouchers found matching the filter/search criteria.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($editingUser): ?>
    <div class="card">
        <h3>View / Edit Voucher</h3>
        <form method="post">
            <input type="hidden" name="action" value="edit_submit">
            <input type="hidden" name="edit_id" value="<?= htmlspecialchars($editingUser['.id']) ?>">
            <label>Username</label>
            <input type="text" name="edit_username" value="<?= htmlspecialchars($editingUser['name'] ?? '') ?>" required>

            <label>Password</label>
            <input type="text" name="edit_password" value="<?= htmlspecialchars($editingUser['password'] ?? '') ?>">

            <label>Profile</label>
            <select name="edit_profile">
                <?php if (empty($profiles)): ?>
                    <option value="default">default</option>
                <?php else: ?>
                    <?php foreach ($profiles as $p): ?>
                        <option value="<?= htmlspecialchars($p['name']) ?>" <?= ( ($editingUser['profile'] ?? '') === ($p['name'] ?? '') ) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>

            <div style="margin-top:10px;">
                <button type="submit">Save Changes</button>
                <a href="voucher.php" style="margin-left:12px; color:#c00;">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

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