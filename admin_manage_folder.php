<?php
// /var/www/html/fm/admin_manage_folder.php

// BLOCCO 1: Includi file di configurazione e funzioni per la logica, avvia sessione
require_once __DIR__ . '/config.php'; // <<< PERCORSO CORRETTO QUI
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   // Per require_admin(), is_admin()
require_once __DIR__ . '/includes/functions_folder.php'; // Per le funzioni delle cartelle

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_admin(); 

$admin_id = $_SESSION['user_id'];
$is_current_user_admin_flag = true; 

$folder_id_to_manage = isset($_GET['folder_id']) ? (int)$_GET['folder_id'] : null;

// BLOCCO 2: Gestione Azioni POST (Permessi, Rinomina, Elimina)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $posted_folder_id = isset($_POST['folder_id_action']) ? (int)$_POST['folder_id_action'] : null;
        // Se l'azione POST è su questa pagina, folder_id_to_manage da GET dovrebbe corrispondere
        if ($posted_folder_id === null && $folder_id_to_manage) {
            $posted_folder_id = $folder_id_to_manage; // Usa quello da GET se l'azione è per la pagina corrente
        }

        if ($posted_folder_id && $posted_folder_id > 0) {
            $current_folder_details_for_action = get_folder_details($posted_folder_id); 

            if (isset($_POST['update_folder_permissions'])) {
                $permissions_posted = $_POST['permissions'] ?? []; 
                $users_for_permissions = get_users_for_folder_permission_settings($posted_folder_id);
                $all_permissions_updated = true;
                foreach ($users_for_permissions as $user) {
                    $user_id_to_set = $user['user_id'];
                    $can_view_posted = isset($permissions_posted[$user_id_to_set]['can_view']) && $permissions_posted[$user_id_to_set]['can_view'] === '1';
                    if (!set_folder_permission($posted_folder_id, $user_id_to_set, $can_view_posted)) {
                        $all_permissions_updated = false;
                        log_error("Fallimento aggiornamento permessi per folderID {$posted_folder_id}, userID {$user_id_to_set}");
                    }
                }
                if ($all_permissions_updated) { $_SESSION['flash_message'] = "Permessi per '" . htmlspecialchars($current_folder_details_for_action['folder_name'] ?? 'la cartella') . "' aggiornati."; $_SESSION['flash_type'] = 'success';}
                else { $_SESSION['flash_message'] = "Errore aggiornamento alcuni permessi."; $_SESSION['flash_type'] = 'danger';}
            
            } elseif (isset($_POST['rename_folder_submit'])) {
                $new_folder_name_rename = $_POST['new_folder_name_rename'] ?? '';
                $result = rename_folder($posted_folder_id, $new_folder_name_rename, $admin_id);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            
            } elseif (isset($_POST['delete_folder_submit'])) {
                $result = soft_delete_folder($posted_folder_id, $admin_id);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
                if ($result['success']) {
                    header('Location: admin_folders.php'); 
                    exit;
                }
            }
        } else {
             $_SESSION['flash_message'] = "ID cartella mancante o non valido per l'azione POST.";
             $_SESSION['flash_type'] = 'danger';
        }
    }
    // Reindirizza alla stessa pagina per mostrare il messaggio flash e aggiornare i dati (tranne per delete)
    $redirect_folder_id = $posted_folder_id ?: $folder_id_to_manage;
    if ($redirect_folder_id) { // Solo se abbiamo un ID valido
        header("Location: admin_manage_folder.php?folder_id=" . $redirect_folder_id); 
    } else { // Fallback se non riusciamo a determinare un ID valido
        header("Location: admin_folders.php");
    }
    exit;
}


// BLOCCO 3: Recupero dati per la visualizzazione (solo se non c'è stato redirect)
// Questo blocco viene eseguito per le richieste GET o se il POST non ha fatto redirect (es. errore CSRF gestito con redirect)
if ($folder_id_to_manage === null || $folder_id_to_manage <= 0) {
    $_SESSION['flash_message'] = "ID cartella non valido per la visualizzazione.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin_folders.php');
    exit;
}
$folder_details = get_folder_details($folder_id_to_manage); 
if (!$folder_details || (isset($folder_details['id']) && $folder_details['id'] == '0' && $folder_id_to_manage != 0) ) { 
    // Se get_folder_details restituisce null o la radice virtuale quando ci aspettavamo una cartella specifica
    $_SESSION['flash_message'] = "Cartella non trovata o non più accessibile (ID: ".htmlspecialchars($folder_id_to_manage).").";
    $_SESSION['flash_type'] = 'danger';
    header('Location: admin_folders.php');
    exit;
}
$users_with_permissions = get_users_for_folder_permission_settings($folder_id_to_manage);
$breadcrumbs = get_folder_path_breadcrumbs_with_permission_check($folder_id_to_manage, $admin_id, $is_current_user_admin_flag);


// BLOCCO 4: Inizio output HTML - Includi header.php QUI
$page_title = "Gestisci Cartella: " . htmlspecialchars($folder_details['folder_name']);
require_once __DIR__ . '/includes/header.php'; 
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb bg-light p-2">
        <?php foreach ($breadcrumbs as $index => $crumb): 
            $crumb_link_id = ($crumb['id'] === null || $crumb['id'] === '0') ? '0' : $crumb['id'];
            $is_last_crumb = ($index === count($breadcrumbs) - 1);
            $is_active_crumb = ($folder_details && $folder_details['id'] != '0' && $crumb_link_id == $folder_details['id']);
            ?>
            <?php if ($is_last_crumb && $is_active_crumb): ?>
                <li class="breadcrumb-item active" aria-current="page">Gestisci: <?php echo htmlspecialchars($crumb['name']); ?></li>
            <?php else: ?>
                <li class="breadcrumb-item">
                    <?php if ($crumb['id'] === '0'): ?>
                        <a href="admin_folders.php"><?php echo htmlspecialchars($crumb['name']); ?></a>
                    <?php else: ?>
                         <a href="admin_manage_folder.php?folder_id=<?php echo $crumb_link_id; ?>"><?php echo htmlspecialchars($crumb['name']); ?></a>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>

<h2><i class="fas fa-cogs"></i> Gestisci Cartella: <em><?php echo htmlspecialchars($folder_details['folder_name']); ?></em></h2>
<p class="text-muted">ID Cartella: <?php echo $folder_details['id']; ?></p>

<?php // I messaggi flash sono ora gestiti da header.php, che è incluso sopra ?>

<div class="row mt-4">
    <div class="col-md-7">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-users-cog"></i> Permessi Utente per "<?php echo htmlspecialchars($folder_details['folder_name']); ?>"</div>
            <div class="card-body">
                <p class="text-info small"><i class="fas fa-info-circle"></i> Concedendo "Visualizza", si concede anche "Carica". Il permesso "Scarica" per file altrui è gestito a livello di file (solo proprietario/admin possono scaricare file altrui, indipendentemente da questo settaggio per ora).</p>
                <?php if (empty($users_with_permissions)): ?> <p class="text-muted">Nessun utente 'User' per impostare permessi.</p>
                <?php else: ?>
                <form action="admin_manage_folder.php?folder_id=<?php echo $folder_id_to_manage; ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="folder_id_action" value="<?php echo $folder_id_to_manage; ?>">
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-striped table-bordered table-sm">
                            <thead class="thead-light"><tr><th>Username</th><th class="text-center">Visualizza/Carica <i class="fas fa-eye"></i></th></tr></thead>
                            <tbody>
                            <?php foreach ($users_with_permissions as $user_perm): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user_perm['username']); ?><br><small class="text-muted"><?php echo htmlspecialchars($user_perm['email']); ?></small></td>
                                    <td class="text-center">
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="can_view_<?php echo $user_perm['user_id']; ?>" name="permissions[<?php echo $user_perm['user_id']; ?>][can_view]" value="1" <?php echo ($user_perm['can_view'] ?? false) ? 'checked' : ''; ?>><label class="custom-control-label" for="can_view_<?php echo $user_perm['user_id']; ?>"></label></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="update_folder_permissions" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Salva Permessi</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-edit"></i> Rinomina Cartella</div>
            <div class="card-body">
                <form action="admin_manage_folder.php?folder_id=<?php echo $folder_id_to_manage; ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="folder_id_action" value="<?php echo $folder_id_to_manage; ?>">
                    <div class="form-group">
                        <label for="new_folder_name_rename">Nuovo Nome Cartella:</label>
                        <input type="text" class="form-control" id="new_folder_name_rename" name="new_folder_name_rename" value="<?php echo htmlspecialchars($folder_details['folder_name']); ?>" required maxlength="100">
                    </div>
                    <button type="submit" name="rename_folder_submit" class="btn btn-info"><i class="fas fa-pencil-alt"></i> Rinomina</button>
                </form>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header bg-danger text-white"><i class="fas fa-trash"></i> Elimina Cartella</div>
            <div class="card-body">
                <p class="text-danger small"><i class="fas fa-exclamation-triangle"></i> Attenzione: L'eliminazione marca la cartella come cancellata. Non sarà più visibile e i file al suo interno non saranno accessibili tramite essa. L'azione non elimina fisicamente i file.</p>
                <form action="admin_manage_folder.php?folder_id=<?php echo $folder_id_to_manage; ?>" method="POST" onsubmit="return confirm('ATTENZIONE!\nSei sicuro di voler eliminare la cartella \'<?php echo htmlspecialchars(addslashes($folder_details['folder_name'])); ?>\'?\nQuesta azione la contrassegnerà come eliminata.');">
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="folder_id_action" value="<?php echo $folder_id_to_manage; ?>">
                    <button type="submit" name="delete_folder_submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Elimina Questa Cartella</button>
                </form>
            </div>
        </div>
    </div>
</div>

<p class="mt-3"><a href="admin_folders.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna a Elenco Cartelle</a></p>

<?php
require_once __DIR__ . '/includes/footer.php';
?>