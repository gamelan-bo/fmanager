<?php
// /var/www/html/fm/admin_manage_folder.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; // Per generate_url(), SITE_URL, ecc.
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_folder.php'; 
require_once __DIR__ . '/includes/functions_admin.php'; // Per get_all_users_for_select

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_admin();

$admin_id = $_SESSION['user_id'];
// Recupera folder_id sia da GET (per il caricamento iniziale) sia da POST (per i form che potrebbero inviarlo)
$folder_id_to_manage = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) { // Dal rewrite rule per folder_id (es. /admin/cartelle/gestisci/ID)
    $folder_id_to_manage = (int)$_GET['id'];
} elseif (isset($_REQUEST['folder_id']) && is_numeric($_REQUEST['folder_id'])) { // Fallback se passato come folder_id
    $folder_id_to_manage = (int)$_REQUEST['folder_id'];
}


if ($folder_id_to_manage === null || $folder_id_to_manage <= 0) {
    $_SESSION['flash_message'] = "ID cartella non valido o mancante per la gestione.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . (function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php'));
    exit;
}

$folder_details = get_folder_details($folder_id_to_manage);
if (!$folder_details || $folder_details['id'] === '0') { // Aggiunto controllo per non gestire la "Radice File" logica qui
    $_SESSION['flash_message'] = "Cartella non trovata o non gestibile (ID: " . htmlspecialchars($folder_id_to_manage) . ").";
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . (function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php'));
    exit;
}

// BLOCCO 2: Gestione delle azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $action_result = null;
        // L'ID della cartella da gestire dovrebbe essere nel campo hidden del form o nell'URL
        $current_folder_id_for_action = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : $folder_id_to_manage;

        if ($current_folder_id_for_action !== $folder_id_to_manage) {
             // Tentativo di manomissione o errore logico
            $_SESSION['flash_message'] = "ID cartella non corrispondente. Azione annullata.";
            $_SESSION['flash_type'] = 'danger';
        } else {
            if (isset($_POST['update_folder_name_submit'])) {
                $new_folder_name = trim($_POST['new_folder_name'] ?? '');
                $result = update_folder_name($current_folder_id_for_action, $new_folder_name, $admin_id);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            } elseif (isset($_POST['add_user_permission_submit'])) {
                $user_id_to_add = isset($_POST['user_id_to_add_permission']) ? (int)$_POST['user_id_to_add_permission'] : null;
                $can_view = isset($_POST['permission_can_view']) ? 1 : 0;
                // Le altre permission seguono can_view nella logica semplificata di grant_folder_permission
                if ($user_id_to_add) {
                    $result = grant_folder_permission($current_folder_id_for_action, $user_id_to_add, $can_view, $can_view, $can_view, $can_view, $admin_id);
                    $_SESSION['flash_message'] = $result['message'];
                    $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
                } else {
                    $_SESSION['flash_message'] = "ID utente non valido per aggiungere permesso.";
                    $_SESSION['flash_type'] = 'danger';
                }
            } elseif (isset($_POST['remove_user_permission_submit']) && isset($_POST['user_id_to_remove'])) {
                $user_id_to_remove = (int)$_POST['user_id_to_remove'];
                $result = revoke_folder_permission($current_folder_id_for_action, $user_id_to_remove, $admin_id);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            } elseif (isset($_POST['delete_folder_submit'])) {
                $result = delete_folder_recursive($current_folder_id_for_action, $admin_id);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
                if ($result['success']) {
                    // Se la cartella è stata eliminata con successo, torna alla lista delle cartelle
                    header('Location: ' . (function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php'));
                    exit;
                }
            }
        }
    }
    // Reindirizza alla stessa pagina di gestione cartella per mostrare il flash message e aggiornare la vista
    $redirect_url_params = ['id' => $folder_id_to_manage]; // Usa 'id' come definito nella rotta
    header("Location: " . (function_exists('generate_url') ? generate_url('admin_folder_manage', $redirect_url_params) : ('admin_manage_folder.php?folder_id=' . $folder_id_to_manage))); 
    exit;
}

// BLOCCO 3: Recupero dati per la visualizzazione (GET request)
// $folder_details è già stato recuperato e verificato
$folder_permissions = get_folder_permissions($folder_id_to_manage);
$users_with_permissions_ids = array_column($folder_permissions, 'user_id'); // Ottieni gli ID per escluderli dal dropdown
$all_users_for_dropdown = get_all_users_for_select(); // Funzione da functions_admin.php
$breadcrumbs = get_folder_path_breadcrumbs_with_permission_check($folder_id_to_manage, $admin_id, true); // true perché l'admin può vedere tutto

// BLOCCO 4: Inizio output HTML
$page_title = "Gestisci Cartella: " . htmlspecialchars($folder_details['folder_name']);
require_once __DIR__ . '/includes/header.php'; 
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo function_exists('generate_url') ? generate_url('admin_dashboard') : 'index.php'; ?>">Admin</a></li>
        <li class="breadcrumb-item"><a href="<?php echo function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php'; ?>">Gestione Cartelle</a></li>
        <?php 
        // Logica breadcrumb parent (assicurati che get_folder_path_breadcrumbs_with_permission_check restituisca id e name)
        if (count($breadcrumbs) > 1) { // Se c'è più di "Radice File"
            $temp_display_breadcrumbs = array_slice($breadcrumbs, 1, -1); // Rimuovi "Radice File" e la cartella corrente
            foreach ($temp_display_breadcrumbs as $crumb_item) {
                if($crumb_item['id'] === null || $crumb_item['id'] === '0') continue; 
                echo '<li class="breadcrumb-item"><a href="'.(function_exists('generate_url') ? generate_url('admin_folder_manage', ['id' => $crumb_item['id']]) : 'admin_manage_folder.php?folder_id='.$crumb_item['id']).'">'.htmlspecialchars($crumb_item['name']).'</a></li>';
            }
        }
        ?>
        <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($folder_details['folder_name']); ?> (ID: <?php echo $folder_id_to_manage; ?>)</li>
    </ol>
</nav>

<h2><i class="fas fa-edit"></i> Gestisci Cartella: <em><?php echo htmlspecialchars($folder_details['folder_name']); ?></em></h2>
<?php // I messaggi flash sono gestiti da header.php ?>

<div class="row mt-3">
    <div class="col-md-6">
        <div class="card mb-3 shadow-sm">
            <div class="card-header"><i class="fas fa-pencil-alt"></i> Modifica Nome Cartella</div>
            <div class="card-body">
                <form action="<?php echo function_exists('generate_url') ? generate_url('admin_folder_manage', ['id' => $folder_id_to_manage]) : ('admin_manage_folder.php?folder_id=' . $folder_id_to_manage); ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="folder_id" value="<?php echo $folder_id_to_manage; ?>">
                    <div class="form-group">
                        <label for="new_folder_name">Nuovo Nome:</label>
                        <input type="text" class="form-control" id="new_folder_name" name="new_folder_name" value="<?php echo htmlspecialchars($folder_details['folder_name']); ?>" required maxlength="100">
                    </div>
                    <button type="submit" name="update_folder_name_submit" class="btn btn-primary">Salva Nome</button>
                </form>
            </div>
        </div>

        <div class="card mb-3 shadow-sm">
            <div class="card-header bg-danger text-white"><i class="fas fa-trash-alt"></i> Elimina Cartella</div>
            <div class="card-body">
                <p class="text-danger"><strong>Attenzione:</strong> L'eliminazione di una cartella eliminerà anche tutte le sue sottocartelle dal database e contrassegnerà come eliminati (soft delete) tutti i file contenuti in esse e nelle loro sottocartelle.</p>
                <form action="<?php echo function_exists('generate_url') ? generate_url('admin_folder_manage', ['id' => $folder_id_to_manage]) : ('admin_manage_folder.php?folder_id=' . $folder_id_to_manage); ?>" method="POST" 
                      onsubmit="return confirm('Sei SICURO di voler eliminare la cartella \'<?php echo htmlspecialchars(addslashes($folder_details['folder_name'])); ?>\' e tutto il suo contenuto (sottocartelle e file verranno soft-deletati)? L\'azione sulla struttura delle cartelle è irreversibile.');">
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="folder_id" value="<?php echo $folder_id_to_manage; ?>">
                    <button type="submit" name="delete_folder_submit" class="btn btn-danger">Elimina Questa Cartella e Contenuto</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card mb-3 shadow-sm">
            <div class="card-header"><i class="fas fa-users-cog"></i> Gestisci Permessi Utente</div>
            <div class="card-body">
                <h5><i class="fas fa-user-plus"></i> Aggiungi Permesso Utente</h5>
                <form action="<?php echo function_exists('generate_url') ? generate_url('admin_folder_manage', ['id' => $folder_id_to_manage]) : ('admin_manage_folder.php?folder_id=' . $folder_id_to_manage); ?>" method="POST" class="mb-4">
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="folder_id" value="<?php echo $folder_id_to_manage; ?>">
                    <div class="form-group">
                        <label for="user_id_to_add_permission">Seleziona Utente:</label>
                        <select name="user_id_to_add_permission" id="user_id_to_add_permission" class="form-control form-control-sm">
                            <option value="">-- Seleziona Utente --</option>
                            <?php foreach ($all_users_for_dropdown as $user_for_select): 
                                if (!in_array($user_for_select['id'], $users_with_permissions_ids)): ?>
                                <option value="<?php echo $user_for_select['id']; ?>"><?php echo htmlspecialchars($user_for_select['username']) . " (ID: " . $user_for_select['id'] . ")"; ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="permission_can_view" name="permission_can_view" value="1" checked>
                            <label class="custom-control-label" for="permission_can_view">Può Visualizzare (implica upload/condivisione/eliminazione propri file)</label>
                        </div>
                        <small class="form-text text-muted">Con la logica attuale, il permesso di visualizzazione per un utente non proprietario gli concede pieni poteri sui *propri* file all'interno di questa cartella condivisa. Non può modificare la cartella o i file di altri.</small>
                    </div>
                    <button type="submit" name="add_user_permission_submit" class="btn btn-success btn-sm">Aggiungi Permesso</button>
                </form>
                <hr>
                <h5><i class="fas fa-user-shield"></i> Utenti con Permesso su Questa Cartella</h5>
                <?php if (empty($folder_permissions)): ?>
                    <p class="text-muted">Nessun utente specifico ha permessi diretti su questa cartella (oltre al proprietario/admin).</p>
                <?php else: ?>
                    <ul class="list-group">
                    <?php foreach ($folder_permissions as $permission): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <?php echo htmlspecialchars($permission['username']); ?> (ID: <?php echo $permission['user_id']; ?>)
                                - <small>Visualizza: <?php echo $permission['can_view'] ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>'; ?></small>
                                <?php /* // Se volessi mostrare altri permessi granulari (e la tabella li avesse)
                                - <small>Upload: <?php echo $permission['can_upload_files'] ? 'Sì' : 'No'; ?></small>
                                */ ?>
                            </span>
                            <form action="<?php echo function_exists('generate_url') ? generate_url('admin_folder_manage', ['id' => $folder_id_to_manage]) : ('admin_manage_folder.php?folder_id=' . $folder_id_to_manage); ?>" method="POST" class="d-inline" onsubmit="return confirm('Rimuovere i permessi per l\'utente <?php echo htmlspecialchars(addslashes($permission['username'])); ?> da questa cartella?');">
                                <?php echo csrf_input_field(); ?>
                                <input type="hidden" name="folder_id" value="<?php echo $folder_id_to_manage; ?>">
                                <input type="hidden" name="user_id_to_remove" value="<?php echo $permission['user_id']; ?>">
                                <button type="submit" name="remove_user_permission_submit" class="btn btn-danger btn-sm"><i class="fas fa-user-minus"></i> Rimuovi</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<p class="mt-4"><a href="<?php echo function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php'; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna a Gestione Cartelle</a></p>

<?php
require_once __DIR__ . '/includes/footer.php';
?>