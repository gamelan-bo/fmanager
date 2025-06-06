<?php
// /var/www/html/fm-new/admin_users.php

// BLOCCO 1: Inclusioni e Setup
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_admin.php'; 
if (!function_exists('format_file_size')) { 
    require_once __DIR__ . '/includes/functions_utils.php';
}

if (session_status() == PHP_SESSION_NONE) { session_start(); }
require_admin(); 

$current_admin_id = $_SESSION['user_id']; 
// Correzione: La regola .htaccess passa 'user_id', non 'id'
$action_get = $_GET['action'] ?? 'list'; 
$user_id_get_param = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null; 

// BLOCCO 2: Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore CSRF."; $_SESSION['flash_type'] = 'danger';
    } else {
        $user_id_action_target = (int)($_POST['user_id_to_manage'] ?? $_POST['user_id_to_delete'] ?? 0);
        $redirect_route_after_post = 'admin_users_list'; $redirect_params_after_post = [];

        if (isset($_POST['update_user_details']) && $user_id_action_target > 0) {
            $new_username = trim($_POST['username']); $new_email = trim($_POST['email']);
            $new_role = $_POST['role']; $is_active = isset($_POST['is_active']);
            $quota_mb = isset($_POST['quota_mb']) ? trim($_POST['quota_mb']) : '';
            $new_quota_bytes = ($quota_mb === '' || (float)$quota_mb <= 0) ? 0 : (float)$quota_mb * 1024 * 1024;
            $force_password_change = isset($_POST['requires_password_change']);
            $result = admin_update_user_details($user_id_action_target, $new_username, $new_email, $new_role, $is_active, $new_quota_bytes, $force_password_change);
            $_SESSION['flash_message'] = $result['message']; $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            $redirect_route_after_post = 'admin_user_edit';
            $redirect_params_after_post = ['id' => $user_id_action_target];
        } elseif (isset($_POST['set_user_password']) && $user_id_action_target > 0) {
            $new_password = $_POST['new_password'] ?? ''; $confirm_new_password = $_POST['confirm_new_password'] ?? '';
            if ($new_password !== $confirm_new_password) { $_SESSION['flash_message'] = "Le password non coincidono."; $_SESSION['flash_type'] = 'danger';}
            elseif (empty($new_password) || strlen($new_password) < 8) { $_SESSION['flash_message'] = "La password deve essere di almeno 8 caratteri."; $_SESSION['flash_type'] = 'danger';}
            else { $result = admin_set_user_password($user_id_action_target, $new_password); $_SESSION['flash_message'] = $result['message']; $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';}
            $redirect_route_after_post = 'admin_user_edit';
            $redirect_params_after_post = ['id' => $user_id_action_target];
        } elseif (isset($_POST['delete_user_submit']) && $user_id_action_target > 0) {
            $result = admin_delete_user($user_id_action_target, $current_admin_id);
            $_SESSION['flash_message'] = $result['message']; $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        }
    }
    header("Location: " . generate_url($redirect_route_after_post, $redirect_params_after_post)); 
    exit;
}

// BLOCCO 3: Recupero Dati per Visualizzazione
$user_to_edit_data = null;
if ($action_get === 'edit' && $user_id_get_param) {
    $user_to_edit_data = get_user_details_for_admin($user_id_get_param);
    if (!$user_to_edit_data) { 
        $_SESSION['flash_message'] = "Utente non trovato per la modifica (ID: ".htmlspecialchars($user_id_get_param).")."; 
        $_SESSION['flash_type'] = 'danger'; 
        header("Location: " . generate_url('admin_users_list')); exit; 
    }
}
$all_users_list = null;
if ($action_get === 'list') {
    $all_users_list = get_all_users_with_details(); 
}

// BLOCCO 4: Inizio HTML
$page_title = "Gestione Utenti";
if ($action_get === 'edit' && $user_to_edit_data) {
    $page_title = "Modifica Utente: " . htmlspecialchars($user_to_edit_data['username']);
}
require_once __DIR__ . '/includes/header.php'; 
?>

<?php if ($action_get === 'edit' && $user_to_edit_data): // Vista Modifica Utente ?>
    <h2><i class="fas fa-user-edit"></i> Modifica Utente: <?php echo htmlspecialchars($user_to_edit_data['username']); ?></h2>
    <form action="<?php echo generate_url('admin_user_edit', ['id' => $user_to_edit_data['id']]); ?>" method="POST" class="needs-validation mb-4" novalidate>
        <?php echo csrf_input_field(); ?>
        <input type="hidden" name="user_id_to_manage" value="<?php echo $user_to_edit_data['id']; ?>">
        <div class="card"><div class="card-header">Dettagli Account</div><div class="card-body">
        <div class="form-row"><div class="form-group col-md-6"><label for="username">Username</label><input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user_to_edit_data['username']); ?>" required minlength="3" maxlength="50"></div><div class="form-group col-md-6"><label for="email">Email</label><input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_to_edit_data['email']); ?>" required></div></div>
        <div class="form-row">
            <div class="form-group col-md-4"><label for="role">Ruolo</label><select id="role" name="role" class="form-control" required><option value="User" <?php if($user_to_edit_data['role'] === 'User') echo 'selected';?>>User</option><option value="Admin" <?php if($user_to_edit_data['role'] === 'Admin') echo 'selected';?>>Admin</option></select></div>
            <div class="form-group col-md-4">
                <label for="quota_mb">Quota (MB)</label>
                <input type="number" class="form-control" id="quota_mb" name="quota_mb" 
                       value="<?php echo ($user_to_edit_data['quota_bytes'] === null) ? '' : round((float)$user_to_edit_data['quota_bytes'] / (1024*1024), 2); ?>" 
                       min="0" step="any" placeholder="Illimitata">
                <small class="form-text text-muted">Lascia vuoto o inserisci 0 per quota illimitata.</small>
            </div>
            <div class="form-group col-md-4 align-self-center pt-3">
                <div class="custom-control custom-switch mb-2"><input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php if($user_to_edit_data['is_active']) echo 'checked';?>><label class="custom-control-label" for="is_active">Attivo</label></div>
                <div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="requires_password_change" name="requires_password_change" value="1" <?php if($user_to_edit_data['requires_password_change']) echo 'checked';?>><label class="custom-control-label" for="requires_password_change">Forza Cambio Pwd</label></div>
            </div>
        </div>
        </div><div class="card-footer"><button type="submit" name="update_user_details" class="btn btn-primary"><i class="fas fa-save"></i> Salva Dettagli</button></div></div>
    </form>
    
    <form action="<?php echo generate_url('admin_user_edit', ['id' => $user_to_edit_data['id']]); ?>" method="POST" class="needs-validation mt-3" novalidate>
        <?php echo csrf_input_field(); ?><input type="hidden" name="user_id_to_manage" value="<?php echo $user_to_edit_data['id']; ?>">
        <div class="card"><div class="card-header">Imposta Nuova Password</div><div class="card-body">
        <div class="form-row"><div class="form-group col-md-6"><label for="new_password">Nuova Password (min 8)</label><input type="password" class="form-control" id="new_password" name="new_password" minlength="8"></div><div class="form-group col-md-6"><label for="confirm_new_password">Conferma Nuova Password</label><input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" minlength="8"></div></div>
        <small class="form-text text-muted">Compila solo se vuoi cambiare la password dell'utente. Verrà forzato a cambiarla al suo primo login.</small>
        </div><div class="card-footer"><button type="submit" name="set_user_password" class="btn btn-warning"><i class="fas fa-key"></i> Imposta Password</button></div></div>
    </form>
    <p class="mt-4"><a href="<?php echo generate_url('admin_users_list'); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Lista Utenti</a></p>

<?php else: // Vista Lista Utenti ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-users"></i> Gestione Utenti Registrati</h2>
        <a href="<?php echo generate_url('admin_pending_users'); ?>" class="btn btn-info"><i class="fas fa-user-clock"></i> Utenti da Validare</a>
    </div>
    <?php if ($all_users_list === null) $all_users_list = get_all_users_with_details(); ?>
    <?php if (empty($all_users_list)): ?> <div class="alert alert-info">Nessun utente registrato.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-striped table-hover table-bordered table-sm">
        <thead class="thead-light"><tr>
            <th>ID</th><th>Username</th><th>Email</th><th>Ruolo</th><th>Stato</th>
            <th>Quota Usata/Totale (MB)</th><th>Registrato</th><th>Ultimo Login</th><th>Azioni</th>
        </tr></thead><tbody>
        <?php foreach ($all_users_list as $user): ?>
        <tr>
            <td><?php echo $user['id']; ?></td><td><?php echo htmlspecialchars($user['username']); ?></td>
            <td><?php echo htmlspecialchars($user['email']); ?></td>
            <td><span class="badge badge-<?php echo ($user['role']==='Admin'?'danger':'secondary');?> p-2"><?php echo htmlspecialchars($user['role']);?></span></td>
            <td>
                <?php if($user['is_active']):?><span class="badge badge-success">Attivo</span><?php else:?><span class="badge badge-danger">Non Attivo</span><?php endif;?>
                <?php if($user['requires_admin_validation']):?><span class="badge badge-warning ml-1">Da Validare</span><?php endif;?>
                <?php if(!($user['is_email_validated']??false)):?><span class="badge badge-secondary ml-1">Email Non Validata</span><?php endif;?>
                <?php if($user['requires_password_change']):?><span class="badge badge-info ml-1">Cambio Pwd</span><?php endif;?>
            </td>
            <td><?php 
                if ($user['quota_bytes'] === null) {
                    echo (function_exists('format_file_size') ? format_file_size($user['used_space_bytes']) : round($user['used_space_bytes']/(1024*1024),2).' MB') . ' / Illimitata';
                } else {
                    echo round($user['used_space_bytes']/(1024*1024),2) . ' / ' . round($user['quota_bytes']/(1024*1024),2);
                }
            ?></td>
            <td><?php echo date('d/m/y H:i', strtotime($user['created_at']));?></td>
            <td><?php echo $user['last_login_at']?date('d/m/y H:i',strtotime($user['last_login_at'])):'Mai';?></td>
            <td>
                <a href="<?php echo generate_url('admin_user_edit', ['id' => $user['id']]);?>" class="btn btn-outline-info btn-sm m-1" title="Modifica"><i class="fas fa-user-edit"></i></a>
                <?php if ($user['id']!=$current_admin_id):?>
                <form action="<?php echo generate_url('admin_users_list'); ?>" method="POST" class="d-inline" onsubmit="return confirm('ATTENZIONE: Eliminare utente \'<?php echo htmlspecialchars(addslashes($user['username']));?>\'? Azione IRREVERSIBILE (elimina record dal DB).');">
                    <?php echo csrf_input_field();?><input type="hidden" name="user_id_to_delete" value="<?php echo $user['id'];?>">
                    <button type="submit" name="delete_user_submit" class="btn btn-outline-danger btn-sm m-1" title="Elimina Utente"><i class="fas fa-user-times"></i></button>
                </form>
                <?php endif;?>
            </td>
        </tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <p class="mt-3"><a href="<?php echo generate_url('admin_dashboard'); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna Dashboard Admin</a></p>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>