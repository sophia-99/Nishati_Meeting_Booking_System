<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mail_settings.php';

check_login();
check_admin('mail.view');
require_permission('mail.view');

function arc_mask_email($email) {
    $email = (string)$email;
    $at = strpos($email, '@');
    if ($at === false) {
        return '***';
    }
    return substr($email, 0, 2) . '***' . substr($email, $at);
}

function arc_chip_icon($name, $size = 13) {
    static $paths = [
        'lock' => '<rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'unlock' => '<rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 7.6-1.6"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'x' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'key' => '<circle cx="8" cy="15.5" r="3.5"/><path d="m10.6 13 8-8"/><path d="m16.5 7 2 2"/><path d="m14 9.5 2 2"/>',
    ];
    $path = $paths[$name] ?? $paths['check'];
    return '<svg class="chip-ico" width="' . (int)$size . '" height="' . (int)$size
         . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('mail.edit', 'mail_config.php');
    $admin_id = (int)($_SESSION['user_id'] ?? 0);

    if (isset($_POST['save_config'])) {
        csrf_verify('MAIL_CONFIG_SAVE');
        $result = mail_settings_save([
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => (int)($_POST['smtp_port'] ?? 0),
            'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
            'smtp_user' => trim($_POST['smtp_username'] ?? ''),
            'smtp_pausword' => ($_POST['smtp_pausword'] ?? '') !== '' ? $_POST['smtp_pausword'] : null,
            'from_email' => trim($_POST['from_email'] ?? ''),
            'from_name' => trim($_POST['from_name'] ?? ''),
            'admin_email' => trim($_POST['admin_email'] ?? ''),
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ], $admin_id);

        if ($result['ok']) {
            if (!empty($result['read_only'])) {
                audit_log(
                    'MAIL_CONFIG_UPDATED',
                    'mail',
                    null,
                    'App password changed (stored encrypted); SMTP settings unchanged (read-only mode).',
                    $admin_id
                );
                flash_set('success', 'App password saved and encrypted. SMTP settings were not changed because the panel is in read-only mode.');
            } else {
                $details = 'host=' . trim($_POST['smtp_host'] ?? '')
                         . ' port=' . (int)($_POST['smtp_port'] ?? 0)
                         . ' encryption=' . ($_POST['smtp_encryption'] ?? 'tls')
                         . ' user=' . arc_mask_email(trim($_POST['smtp_username'] ?? ''))
                         . ' from=' . arc_mask_email(trim($_POST['from_email'] ?? ''))
                         . ' enabled=' . (isset($_POST['enabled']) ? 'yes' : 'no')
                         . ' password=' . ($result['pausword_changed'] ? 'changed (stored encrypted)' : 'unchanged')
                         . ' | automatically locked to read-only after save';
                audit_log('MAIL_CONFIG_UPDATED', 'mail', null, $details, $admin_id);
                flash_set(
                    'success',
                    'Mail configuration saved. '
                    . ($result['pausword_changed'] ? 'The app password is stored encrypted. ' : '')
                    . 'The panel has returned to read-only mode.'
                );
            }
        } else {
            flash_set('error', $result['error']);
        }
    } elseif (isset($_POST['test_email'])) {
        csrf_verify('MAIL_CONFIG_TEST');
        $to = trim($_POST['test_to'] ?? '');
        $result = mail_send_test($to);
        audit_log(
            'MAIL_CONFIG_TESTED',
            'mail',
            null,
            'Test to ' . arc_mask_email($to) . ' - '
                . ($result['ok'] ? 'SUCCESS' : 'FAILED: ' . substr($result['error'], 0, 150)),
            $admin_id
        );
        if ($result['ok']) {
            flash_set('success', 'Test email sent to ' . arc_mask_email($to) . '. Check the recipient inbox.');
        } else {
            flash_set('error', 'Test failed: ' . $result['error']);
        }
    } elseif (isset($_POST['delete_password'])) {
        csrf_verify('MAIL_CONFIG_DELETE');
        mail_settings_delete_pausword($admin_id);
        audit_log('MAIL_CONFIG_PASSWORD_DELETED', 'mail', null, 'Stored SMTP password was deleted from the panel.', $admin_id);
        flash_set('success', 'Stored password deleted. Mail is disabled until a new password is saved or MRS_SMTP_PASSWORD is set.');
    } elseif (isset($_POST['unlock_config'])) {
        csrf_verify('MAIL_CONFIG_UNLOCK');
        mail_settings_set_locked(false, $admin_id);
        audit_log('MAIL_CONFIG_UNLOCKED', 'mail', null, 'Administrator switched the mail panel to edit mode.', $admin_id);
        flash_set('success', 'Edit mode enabled. SMTP settings can now be changed. Each save returns the panel to read-only mode.');
    } elseif (isset($_POST['lock_config'])) {
        csrf_verify('MAIL_CONFIG_LOCK');
        mail_settings_set_locked(true, $admin_id);
        audit_log('MAIL_CONFIG_LOCKED', 'mail', null, 'Administrator switched the mail panel to read-only mode.', $admin_id);
        flash_set('success', 'Read-only mode enabled. SMTP settings are locked.');
    }

    header('Location: mail_config.php');
    exit;
}

$flash = flash_pull();
$message = $flash['success'];
$error = $flash['error'];
$cfg = mail_settings_get();

$updated_by_name = null;
if (!empty($cfg['updated_by'])) {
    $stmt = $conn->prepare('SELECT full_name FROM users WHERE id = ?');
    $stmt->bind_param('i', $cfg['updated_by']);
    $stmt->execute();
    $updated_by_name = $stmt->get_result()->fetch_assoc()['full_name'] ?? null;
}

$source_labels = [
    'env' => 'Environment variable',
    'db' => 'Panel configuration',
    'default' => 'Default',
    'none' => 'Not set',
];
$source_chip = function ($key, $label) use ($cfg, $source_labels) {
    $source = $cfg['source'][$key] ?? 'default';
    $source_label = $source_labels[$source] ?? $source_labels['default'];
    return '<div style="display:flex; justify-content:space-between; align-items:center; gap:8px; padding:7px 0; border-bottom:1px dashed var(--border);">'
         . '<span style="font-size:13.5px; color:var(--text);">' . htmlspecialchars($label) . '</span>'
         . '<span class="status-tag" style="font-size:11.5px;">' . htmlspecialchars($source_label) . '</span>'
         . '</div>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mail Configuration - Admin Panel</title>
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
        <header style="margin-bottom:14px;">
            <h2>Mail Configuration</h2>
            <p style="color:var(--text-muted); font-size:14px; margin:0;">SMTP settings for verification codes, booking confirmations, and reminders. Manage settings here without editing code.</p>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <section class="card">
            <h3 style="margin-top:0;">Status</h3>
            <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
                <?php if ($cfg['enabled']): ?>
                    <span class="status-tag status-available chip"><?php echo arc_chip_icon('check'); ?>Mail is enabled; messages will be sent.</span>
                <?php else: ?>
                    <span class="status-tag status-maintenance chip"><?php echo arc_chip_icon('x'); ?>Mail is disabled; codes will be shown on screen instead.</span>
                <?php endif; ?>
                <span class="status-tag status-pending chip"><?php echo arc_chip_icon('key'); ?>App password: <?php echo $cfg['pausword_set'] ? 'Saved' : 'Not set'; ?></span>
                <?php if ($cfg['locked']): ?>
                    <span class="status-tag chip"><?php echo arc_chip_icon('lock'); ?>Read-only mode</span>
                <?php else: ?>
                    <span class="status-tag status-available chip"><?php echo arc_chip_icon('unlock'); ?>Edit mode</span>
                <?php endif; ?>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:6px;">
                <?php if ($cfg['locked']): ?>
                    <form method="POST" style="margin:0;"
                          data-confirm="Switch to edit mode? SMTP host, port, and encryption settings will become editable. The panel returns to read-only mode after each save."
                          data-confirm-title="Switch to edit mode" data-confirm-text="Unlock" data-confirm-danger="1">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="unlock_config" value="1" class="btn btn-danger">Switch to Edit Mode</button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="margin:0;"
                          data-confirm="Switch back to read-only mode? All SMTP settings will be locked again."
                          data-confirm-title="Switch to read-only mode" data-confirm-text="Lock">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="lock_config" value="1" class="btn btn-secondary">Switch to Read-Only</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($cfg['updated_at']): ?>
                <p style="font-size:13.5px; color:var(--text-muted); margin:0 0 10px;">
                    Last saved: <?php echo htmlspecialchars($cfg['updated_at']); ?>
                    <?php if ($updated_by_name): ?> by <strong><?php echo htmlspecialchars($updated_by_name); ?></strong><?php endif; ?>
                </p>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:0 24px;">
                <div>
                    <?php echo $source_chip('host', 'SMTP host / port / encryption'); ?>
                    <?php echo $source_chip('user', 'SMTP username'); ?>
                </div>
                <div>
                    <?php echo $source_chip('pausword', 'App password'); ?>
                    <?php echo $source_chip('from', 'From address'); ?>
                </div>
            </div>
            <p style="font-size:12.5px; color:var(--text-muted); margin:12px 0 0;">
                Priority: <strong>Environment variable &gt; Panel configuration &gt; Default</strong>.
                Encryption key file: <code><?php echo htmlspecialchars(mail_key_file()); ?></code>
                (<?php echo is_readable(mail_key_file()) ? 'present' : 'created automatically when needed'; ?>, outside the web root. The app password is encrypted, never stored as plain text.)
            </p>
        </section>

        <?php $read_only = !empty($cfg['locked']); $disabled_attr = $read_only ? ' disabled' : ''; ?>
        <section class="card">
            <h3 style="margin-top:0;">SMTP Settings</h3>
            <?php if ($read_only): ?>
                <div class="alert alert-success" style="background:var(--primary-lighter); color:var(--text); border:1px solid var(--border);">
                    <strong>Read-only mode.</strong> SMTP host, port, encryption, username, sender address, and admin email cannot be changed now.
                    Only the <strong>App Password</strong> below can be saved. To change other settings, switch to Edit Mode above.
                </div>
            <?php endif; ?>
            <form method="POST"
                  data-confirm="<?php echo $read_only
                      ? 'Save the new app password? Other SMTP settings will not change in read-only mode.'
                      : 'Save this mail configuration? The app password is encrypted and the panel returns to read-only mode.'; ?>"
                  data-confirm-title="<?php echo $read_only ? 'Save app password' : 'Save mail configuration'; ?>"
                  data-confirm-text="Save">
                <?php echo csrf_field(); ?>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px;">
                    <div>
                        <label for="smtp-host">SMTP Host</label>
                        <input id="smtp-host" type="text" name="smtp_host" value="<?php echo htmlspecialchars($cfg['smtp_host']); ?>" required maxlength="120" placeholder="smtp.gmail.com"<?php echo $disabled_attr; ?>>
                    </div>
                    <div>
                        <label for="smtp-port">Port</label>
                        <input id="smtp-port" type="number" name="smtp_port" value="<?php echo (int)$cfg['smtp_port']; ?>" required min="1" max="65535"<?php echo $disabled_attr; ?>>
                    </div>
                    <div>
                        <label for="smtp-encryption">Encryption</label>
                        <select id="smtp-encryption" name="smtp_encryption"<?php echo $disabled_attr; ?>>
                            <option value="tls" <?php echo $cfg['smtp_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS (port 587)</option>
                            <option value="ssl" <?php echo $cfg['smtp_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL (port 465)</option>
                            <option value="none" <?php echo $cfg['smtp_encryption'] === 'none' ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>
                    <div>
                        <label for="smtp-username">SMTP Username (email)</label>
                        <input id="smtp-username" type="email" name="smtp_username" value="<?php echo htmlspecialchars($cfg['smtp_username']); ?>" required maxlength="190" placeholder="name@gmail.com"<?php echo $disabled_attr; ?>>
                    </div>
                    <div>
                        <label for="smtp-password">App Password <?php echo $cfg['pausword_set'] ? '(saved - enter a new one to replace it)' : '(required to enable mail)'; ?></label>
                        <input id="smtp-password" type="password" name="smtp_pausword" maxlength="128" autocomplete="new-password"
                               placeholder="<?php echo $cfg['pausword_set'] ? 'Leave blank to keep the current password' : 'Enter your 16-character app password'; ?>">
                    </div>
                    <div>
                        <label for="from-email">From Email</label>
                        <input id="from-email" type="email" name="from_email" value="<?php echo htmlspecialchars($cfg['from_email']); ?>" maxlength="190" placeholder="Use the SMTP username"<?php echo $disabled_attr; ?>>
                    </div>
                    <div>
                        <label for="from-name">From Name</label>
                        <input id="from-name" type="text" name="from_name" value="<?php echo htmlspecialchars($cfg['from_name']); ?>" maxlength="150"<?php echo $disabled_attr; ?>>
                    </div>
                    <div>
                        <label for="admin-email">Admin Notification Email</label>
                        <input id="admin-email" type="email" name="admin_email" value="<?php echo htmlspecialchars($cfg['admin_email']); ?>" maxlength="190" placeholder="Address for room-issue reports"<?php echo $disabled_attr; ?>>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px; margin-top:14px;">
                    <input type="checkbox" name="enabled" id="mail-enabled" value="1" <?php echo $cfg['db_enabled'] ? 'checked' : ''; ?><?php echo $disabled_attr; ?> style="width:18px; height:18px;">
                    <label for="mail-enabled" style="margin:0; font-size:14px;">Enable outgoing mail (uncheck to disable mail even when a password is set)</label>
                </div>
                <p style="font-size:12.5px; color:var(--text-muted); margin:10px 0 0;">
                    For Gmail, create a 16-character <strong>App Password</strong>. The From Email should match the SMTP username or Gmail may reject the message.
                </p>
                <div class="action-buttons" style="margin-top:16px;">
                    <button type="submit" name="save_config" value="1" class="btn btn-primary"><?php echo $read_only ? 'Save App Password' : 'Save Configuration'; ?></button>
                </div>
            </form>

            <?php if ($cfg['pausword_set']): ?>
                <hr style="border:none; border-top:1px solid var(--border); margin:18px 0;">
                <form method="POST" style="margin:0;"
                      data-confirm="Delete the stored app password? Mail will be disabled until a new password is saved."
                      data-confirm-title="Delete stored password" data-confirm-text="Delete" data-confirm-danger="1">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="delete_password" value="1" class="btn btn-danger">Delete Stored Password</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <h3 style="margin-top:0;">Send Test Email</h3>
            <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:0;"
                  data-confirm="Send a real test email using the current configuration?"
                  data-confirm-title="Send test email" data-confirm-text="Send">
                <?php echo csrf_field(); ?>
                <div style="flex:1; min-width:230px;">
                    <label for="test-to">Send To</label>
                    <input id="test-to" type="email" name="test_to" required maxlength="190"
                           value="<?php echo htmlspecialchars($cfg['admin_email'] ?: $cfg['from_email']); ?>"
                           placeholder="recipient@example.com">
                </div>
                <div>
                    <button type="submit" name="test_email" value="1" class="btn btn-primary">Send Test Email</button>
                </div>
            </form>
            <p style="font-size:12.5px; color:var(--text-muted); margin:10px 0 0;">
                The result, including any SMTP error, appears above and is recorded in the audit log.
            </p>
        </section>
    </main>
</body>
</html>
