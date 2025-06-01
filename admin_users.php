<?php
// /var/www/html/fm/admin_users.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_admin.php'; // Contiene get_all_users_with_details(), admin_delete_user(), ecc.

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_admin(); // Proteggi la pagina

$current_admin_id = $_SESSION['user_id']; // ID dell'admin loggato
$action = $_GET['action'] ?? 'list';
// $user_id_to_manage sarà definito più avanti, prima di includere header.php, se action è 'edit'
// o preso da GET per la visualizzazione del form di modifica.
// Per le azioni POST, l'ID utente target viene preso da POST.

// BLOCCO 2: Gestione delle azioni POST (update, set password, delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $user_id_from_post_manage = isset($_POST['user_id_to_manage']) ? (int)$_POST['user_id_to_manage'] : null;
        $user_id_from_post_delete = isset($_POST['user_id_to_delete']) ? (int)$_POST['user_id_to_delete'] : null;
        
        // Determina su quale utente agire
        $user_id_action_target = $user_id_from_post_manage ?: $user_id_from_post_delete;
        // Se l'URL ha action=edit, e il POST non specifica user_id_to_manage, usa quello da GET per il redirect di ritorno
        $user_id_for_edit_redirect = isset($_GET['user_id']) && $action === 'edit' ? (int)$_GET['user_id'] : $user_id_action_target;


        if (isset($_POST['update_user_details']) && $user_id_action_target) {
            $new_username = trim($_POST['username']); $new_email = trim($_POST['email']);
            $new_role = $_POST['role']; $is_active = isset($_POST['is_active']);
            $quota_mb = isset($_POST['quota_mb']) ? (float)$_POST['quota_mb'] : 0;
            $new_quota_bytes = $quota_mb * 1024 * 1024;
            $force_password_change = isset($_POST['requires_password_change']);
            $result = admin_update_user_details($user_id_action_target, $new_username, $new_email, $new_role, $is_active, $new_quota_bytes, $force_password_change);
            $_SESSION['flash_message'] = $result['message']; $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';

        } elseif (isset($_POST['set_user_password']) && $user_id_action_target) {
            $new_password = $_POST['new_password'] ?? ''; $confirm_new_password = $_POST['confirm_new_password'] ?? '';
            if ($new_password !== $confirm_new_password) { $_SESSION['flash_message'] = "Le password non coincidono."; $_SESSION['flash_type'] = 'danger';}
            elseif (empty($new_password)) { $_SESSION['flash_message'] = "La nuova password non può essere vuota."; $_SESSION['flash_type'] = 'danger';}
            else { $result = admin_set_user_password($user_id_action_target, $new_password); $_SESSION['flash_message'] = $result['message']; $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';}
        
        } elseif (isset($_POST['delete_user_submit']) && $user_id_action_target) { // Questa è la condizione per l'eliminazione
            $result = admin_delete_user($user_id_action_target, $current_admin_id);
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            header("Location: admin_users.php"); // Dopo l'eliminazione, torna sempre alla lista
            exit;
        }
    }
    // Redirect per mostrare il flash message e aggiornare la vista per update/set_password
    // Se l'azione era di modifica, torna alla pagina di modifica, altrimenti alla lista
    $redirect_url_after_post = "admin_users.php";
    if ($action === 'edit' && $user_id_for_edit_redirect && !isset($_POST['delete_user_submit'])) {
        $redirect_url_after_post .= "?action=edit&user_id=" . $user_id_for_edit_redirect;
    }
    header("Location: " . $redirect_url_after_post);
    exit;
}


// BLOCCO 3: Recupero dati per la visualizzazione (se la richiesta è GET o il POST non ha fatto exit)
$user_id_to_display_edit = ($action === 'edit' && isset($_GET['user_id'])) ? (int)$_GET['user_id'] : null;
$user_to_edit_data = null;
if ($user_id_to_display_edit) {
    $user_to_edit_data = get_user_details_for_admin($user_id_to_display_edit);
    if (!$user_to_edit_data && $action === 'edit') { // Controllo se l'utente esiste per la modifica
        $_SESSION['flash_message'] = "Utente non trovato per la modifica (ID: ".htmlspecialchars($user_id_to_display_edit).").";
        $_SESSION['flash_type'] = 'danger';
        header("Location: admin_users.php"); exit; // Torna alla lista se l'utente non esiste
    }
}
$all_users_list = null;
if ($action === 'list') {
    $all_users_list = get_all_users_with_details();
}


// BLOCCO 4: Inizio output HTML
$page_title = "Gestione Utenti";
if ($action === 'edit' && $user_to_edit_data) {
    $page_title = "Modifica Utente: " . htmlspecialchars($user_to_edit_data['username']);
}
require_once __DIR__ . '/includes/header.php'; 
?>

<?php if ($action === 'edit' && $user_to_edit_data): ?>
    <h2><i class="fas fa-user-edit"></i> Modifica Utente: <?php echo htmlspecialchars($user_to_edit_data['username']); ?></h2>
    <?php // Flash messages sono gestiti da header.php ?>

    <form action="admin_users.php?action=edit&user_id=<?php echo $user_id_to_display_edit; ?>" method="POST" class="needs-validation mb-4" novalidate>
        <?php echo csrf_input_field(); ?>
        <input type="hidden" name="user_id_to_manage" value="<?php echo $user_id_to_display_edit; ?>">
        <div class="card"><div class="card-header">Dettagli Account</div><div class="card-body">
        <div class="form-row"><div class="form-group col-md-6"><label for="username">Username</label><input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($user_to_edit_data['username']); ?>" required minlength="3" maxlength="50"></div><div class="form-group col-md-6"><label for="email">Email</label><input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user_to_edit_data['email']); ?>" required></div></div>
        <div class="form-row"><div class="form-group col-md-4"><label for="role">Ruolo</label><select id="role" name="role" class="form-control" required><option value="User" <?php if($user_to_edit_data['role'] === 'User') echo 'selected';?>>User</option><option value="Admin" <?php if($user_to_edit_data['role'] === 'Admin') echo 'selected';?>>Admin</option></select></div><div class="form-group col-md-4"><label for="quota_mb">Quota (MB)</label><input type="number" class="form-control" id="quota_mb" name="quota_mb" value="<?php echo round($user_to_edit_data['quota_bytes'] / (1024*1024), 2); ?>" required min="0" step="any"></div><div class="form-group col-md-4 align-self-center pt-3"><div class="custom-control custom-switch mb-2"><input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php if($user_to_edit_data['is_active']) echo 'checked';?>><label class="custom-control-label" for="is_active">Attivo</label></div><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="requires_password_change" name="requires_password_change" value="1" <?php if($user_to_edit_data['requires_password_change']) echo 'checked';?>><label class="custom-control-label" for="requires_password_change">Forza Cambio Pwd</label></div></div></div>
        </div><div class="card-footer"><button type="submit" name="update_user_details" class="btn btn-primary">Salva Dettagli</button></div></div>
    </form>
    
    <form action="admin_users.php?action=edit&user_id=<?php echo $user_id_to_display_edit; ?>" method="POST" class="needs-validation mt-3" novalidate>
        <?php echo csrf_input_field(); ?><input type="hidden" name="user_id_to_manage" value="<?php echo $user_id_to_display_edit; ?>">
        <div class="card"><div class="card-header">Imposta Nuova Password</div><div class="card-body">
        <div class="form-row"><div class="form-group col-md-6"><label for="new_password">Nuova Password (min 8)</label><input type="password" class="form-control" id="new_password" name="new_password" required minlength="8"></div><div class="form-group col-md-6"><label for="confirm_new_password">Conferma Nuova Password</label><input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" required minlength="8"></div></div>
        </div><div class="card-footer"><button type="submit" name="set_user_password" class="btn btn-warning">Imposta Password</button></div></div>
    </form>
    <p class="mt-4"><a href="admin_users.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Lista Utenti</a></p>

<?php else: // Lista utenti (action === 'list' o default) ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><i class="fas fa-users"></i> Gestione Utenti Registrati</h2>
        <a href="admin_pending_users.php" class="btn btn-info"><i class="fas fa-user-clock"></i> Utenti da Validare</a>
    </div>
    <?php // Flash messages gestiti da header.php ?>

    <?php if ($all_users_list === null) $all_users_list = get_all_users_with_details(); // Carica solo se necessario ?>
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
            <td><?php if($user['is_active']):?><span class="badge badge-success">Attivo</span><?php else:?><span class="badge badge-danger">Non Attivo</span><?php endif;?>
                <?php if($user['requires_admin_validation']):?><span class="badge badge-warning ml-1">Da Validare</span><?php endif;?>
                <?php if(!($user['is_email_validated']??false)):?><span class="badge badge-secondary ml-1">Email Non Validata</span><?php endif;?>
                <?php if($user['requires_password_change']):?><span class="badge badge-info ml-1">Cambio Pwd</span><?php endif;?>
            </td>
            <td><?php echo round($user['used_space_bytes']/(1024*1024),2);?>/<?php echo round($user['quota_bytes']/(1024*1024),2);?></td>
            <td><?php echo date('d/m/y H:i', strtotime($user['created_at']));?></td>
            <td><?php echo $user['last_login_at']?date('d/m/y H:i',strtotime($user['last_login_at'])):'Mai';?></td>
            <td>
                <a href="admin_users.php?action=edit&user_id=<?php echo $user['id'];?>" class="btn btn-outline-info btn-sm m-1" title="Modifica"><i class="fas fa-user-edit"></i></a>
                <?php if ($user['id']!=$current_admin_id):?>
                <form action="admin_users.php" method="POST" class="d-inline" onsubmit="return confirm('ATTENZIONE: Eliminare utente \'<?php echo htmlspecialchars(addslashes($user['username']));?>\'? Azione IRREVERSIBILE per i dati DB.');">
                    <?php echo csrf_input_field();?><input type="hidden" name="user_id_to_delete" value="<?php echo $user['id'];?>">
                    <button type="submit" name="delete_user_submit" class="btn btn-outline-danger btn-sm m-1" title="Elimina"><i class="fas fa-user-times"></i></button>
                </form>
                <?php endif;?>
            </td>
        </tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <p class="mt-3"><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna Dashboard Admin</a></p>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/footer.php';
?>