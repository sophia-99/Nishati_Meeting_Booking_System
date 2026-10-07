<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/audit.php';

check_login();
check_admin('projectors.view');
require_permission('projectors.view');

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_projector'])) {
    csrf_verify('PROJECTOR_ADD');
    require_permission('projectors.manage', 'projectors.php');

    $name = trim($_POST['name'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $name = mb_substr($name, 0, 100);
    $model = mb_substr($model, 0, 100);
    $location = mb_substr($location, 0, 150);

    if ($name === '') {
        $error = 'Projector name is required.';
    } else {
        $check = $conn->prepare('SELECT id FROM projectors WHERE name = ?');
        $check->bind_param('s', $name);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $error = 'A projector with this name already exists.';
        } else {
            $stmt = $conn->prepare('INSERT INTO projectors (name, model, location) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $name, $model, $location);
            $stmt->execute();
            audit_log('PROJECTOR_ADDED', 'projector', $conn->insert_id, "$name | $model | $location");
            flash_set('success', 'Projector added successfully.');
            header('Location: projectors.php');
            exit();
        }
        $check->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_projector'])) {
    csrf_verify('PROJECTOR_EDIT');
    require_permission('projectors.manage', 'projectors.php');

    $projector_id = (int)($_POST['projector_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $name = mb_substr($name, 0, 100);
    $model = mb_substr($model, 0, 100);
    $location = mb_substr($location, 0, 150);

    if ($name === '' || $projector_id <= 0) {
        $error = 'Projector name is required.';
    } else {
        $check = $conn->prepare('SELECT id FROM projectors WHERE name = ? AND id != ?');
        $check->bind_param('si', $name, $projector_id);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $error = 'A projector with this name already exists.';
        } else {
            $stmt = $conn->prepare('UPDATE projectors SET name = ?, model = ?, location = ? WHERE id = ?');
            $stmt->bind_param('sssi', $name, $model, $location, $projector_id);
            $stmt->execute();
            audit_log('PROJECTOR_EDITED', 'projector', $projector_id, "$name | $model | $location");
            flash_set('success', 'Projector updated successfully.');
            header('Location: projectors.php');
            exit();
        }
        $check->close();
    }
}

if (isset($_GET['toggle_status'])) {
    csrf_verify('PROJECTOR_STATUS_CHANGE');
    require_permission('projectors.manage', 'projectors.php');
    $projector_id = (int)$_GET['toggle_status'];
    $stmt = $conn->prepare("UPDATE projectors SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
    $stmt->bind_param('i', $projector_id);
    $stmt->execute();
    audit_log('PROJECTOR_STATUS_CHANGED', 'projector', $projector_id, 'Toggled active/inactive');
    flash_set('success', 'Projector status updated successfully.');
    header('Location: projectors.php');
    exit();
}

if (isset($_GET['delete_projector'])) {
    csrf_verify('PROJECTOR_DELETE');
    require_permission('projectors.manage', 'projectors.php');
    $projector_id = (int)$_GET['delete_projector'];

    $check = $conn->prepare('SELECT COUNT(*) AS total FROM bookings WHERE projector_id = ?');
    $check->bind_param('i', $projector_id);
    $check->execute();
    $count = (int)$check->get_result()->fetch_assoc()['total'];
    $check->close();

    if ($count > 0) {
        flash_set('error', "Cannot delete this projector: it has $count booking(s) on record. Set it to inactive instead.");
    } else {
        $stmt = $conn->prepare('DELETE FROM projectors WHERE id = ?');
        $stmt->bind_param('i', $projector_id);
        $stmt->execute();
        audit_log('PROJECTOR_DELETED', 'projector', $projector_id, 'Deleted (no bookings on record)');
        flash_set('success', 'Projector deleted successfully.');
    }
    header('Location: projectors.php');
    exit();
}

$projectors = $conn->query("SELECT p.*,
    (SELECT COUNT(*) FROM bookings b
     WHERE b.projector_id = p.id AND b.status IN ('confirmed', 'postponed')) AS active_bookings
    FROM projectors p ORDER BY p.name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projector Inventory - Admin Panel</title>
    <script>(function(){try{var t=localStorage.getItem('mrs_theme');if(t!=='light'&&t!=='dark'){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="../assets/css/style.css?v=49">
</head>
<body>
    <?php require __DIR__ . '/../includes/navbar.php'; ?>
    <script src="../assets/js/theme.js?v=43"></script>
    <script src="../assets/js/nav.js?v=43"></script>
    <script src="../assets/js/ui.js?v=50"></script>
    <script src="../assets/js/icons.js?v=43"></script>

    <main class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (has_permission($_SESSION['role'] ?? '', 'projectors.manage')): ?>
            <section class="card">
                <h3>Add New Projector</h3>
                <form method="POST" data-confirm="Add this projector to the inventory?" data-confirm-title="Add New Projector" data-confirm-text="Add Projector">
                    <?php echo csrf_field(); ?>
                    <label for="projector-name">Projector Name</label>
                    <input id="projector-name" type="text" name="name" placeholder="e.g. Projector 04" maxlength="100" required>
                    <label for="projector-model">Model (optional)</label>
                    <input id="projector-model" type="text" name="model" placeholder="e.g. Epson EB-X06" maxlength="100">
                    <label for="projector-location">Location / Store (optional)</label>
                    <input id="projector-location" type="text" name="location" placeholder="e.g. Main Store" maxlength="150">
                    <button type="submit" name="add_projector" class="btn">Add Projector</button>
                </form>
            </section>
        <?php endif; ?>

        <h2>Projector Inventory</h2>
        <p style="margin:-8px 0 15px; color:var(--text); font-size:14px;">Ministry-wide projectors available for booking with meeting rooms.</p>
        <section class="card">
            <div class="table-scroll">
                <table>
                    <thead><tr>
                        <th>Name</th><th>Model</th><th>Location</th><th>Status</th><th>Active Bookings</th>
                        <?php if (has_permission($_SESSION['role'] ?? '', 'projectors.manage')): ?><th>Action</th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php if ($projectors->num_rows === 0): ?>
                        <tr><td colspan="6">No projectors have been added yet.</td></tr>
                    <?php endif; ?>
                    <?php while ($projector = $projectors->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($projector['name']); ?></td>
                            <td><?php echo htmlspecialchars($projector['model'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($projector['location'] ?: '—'); ?></td>
                            <td><span class="status-tag <?php echo $projector['status'] === 'active' ? 'status-available' : 'status-maintenance'; ?>">
                                <?php echo $projector['status'] === 'active' ? 'Active' : 'Inactive'; ?>
                            </span></td>
                            <td><?php echo (int)$projector['active_bookings']; ?></td>
                            <?php if (has_permission($_SESSION['role'] ?? '', 'projectors.manage')): ?>
                                <td><div class="action-buttons"><div class="mrs-kebab">
                                    <button type="button" class="mrs-kebab-toggle" aria-label="Open projector actions">&#8942;</button>
                                    <div class="mrs-kebab-menu">
                                        <a href="<?php echo htmlspecialchars(csrf_url('projectors.php?toggle_status=' . $projector['id']), ENT_QUOTES); ?>"
                                           data-confirm="<?php echo $projector['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> this projector?"
                                           data-confirm-title="<?php echo $projector['status'] === 'active' ? 'Deactivate Projector?' : 'Activate Projector?'; ?>"
                                           data-confirm-text="<?php echo $projector['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>">
                                            <?php echo $projector['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                        </a>
                                        <button type="button" class="mrs-edit-projector"
                                                data-id="<?php echo (int)$projector['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($projector['name'], ENT_QUOTES); ?>"
                                                data-model="<?php echo htmlspecialchars($projector['model'] ?? '', ENT_QUOTES); ?>"
                                                data-location="<?php echo htmlspecialchars($projector['location'] ?? '', ENT_QUOTES); ?>">Edit Projector</button>
                                        <a class="danger-item" href="<?php echo htmlspecialchars(csrf_url('projectors.php?delete_projector=' . $projector['id']), ENT_QUOTES); ?>"
                                           data-confirm="Delete this projector permanently? This only works if it has no bookings on record."
                                           data-confirm-title="Delete Projector?" data-confirm-text="Delete" data-confirm-danger="1">Delete Projector</a>
                                    </div>
                                </div></div></td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
