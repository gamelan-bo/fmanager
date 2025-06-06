<?php
// /var/www/html/fm/admin_folders.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; // Per generate_url(), SITE_URL, ecc.
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_folder.php'; // Per create_folder(), get_folders_flat_tree_with_permission_check()

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_admin(); 

$admin_id = $_SESSION['user_id'];
$is_current_user_admin_flag = true; 

// BLOCCO 2: Gestione della richiesta POST per la creazione della cartella
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder_submit'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $new_folder_name = trim($_POST['new_folder_name'] ?? ''); 
        $parent_folder_id_input = $_POST['parent_folder_id'] ?? '';
        $parent_folder_id = ($parent_folder_id_input === '0' || $parent_folder_id_input === '') ? null : (int)$parent_folder_id_input;

        $result = create_folder($new_folder_name, $parent_folder_id, $admin_id);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
    }
    $redirect_url = function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php';
    header("Location: " . $redirect_url); 
    exit; 
}

// BLOCCO 3: Recupero dati per la visualizzazione della pagina (GET request)
$all_folders_tree = get_folders_flat_tree_with_permission_check($admin_id, $is_current_user_admin_flag, null, 0);
$creatable_folders_tree = $all_folders_tree; 

// BLOCCO 4: Inizio output HTML
$page_title = "Gestione Cartelle";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-folder-open"></i> Gestione Cartelle</h2>
</div>

<?php // I messaggi flash sono ora gestiti da header.php ?>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-3 shadow-sm">
            <div class="card-header"><i class="fas fa-folder-plus"></i> Crea Nuova Cartella</div>
            <div class="card-body">
                <form action="<?php echo function_exists('generate_url') ? generate_url('admin_folders_list') : 'admin_folders.php'; ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <div class="form-group">
                        <label for="new_folder_name">Nome Nuova Cartella:</label>
                        <input type="text" class="form-control" id="new_folder_name" name="new_folder_name" required maxlength="100" value="<?php echo htmlspecialchars($_POST['new_folder_name_sticky'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="parent_folder_id">Cartella Genitore:</label>
                        <select class="form-control" id="parent_folder_id" name="parent_folder_id">
                            <option value="0">-- Radice (Nessun Genitore) --</option>
                            <?php 
                            if (!empty($creatable_folders_tree)):
                                foreach ($creatable_folders_tree as $folder_item_option): ?>
                                    <option value="<?php echo $folder_item_option['id']; ?>">
                                        <?php echo $folder_item_option['display_name']; ?>
                                    </option>
                                <?php endforeach;
                            endif; ?>
                        </select>
                    </div>
                    <button type="submit" name="create_folder_submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Crea Cartella</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header"><i class="fas fa-list-ul"></i> Struttura Cartelle Esistente</div>
            <div class="card-body">
                <?php if (empty($all_folders_tree)): ?>
                    <p class="text-muted">Nessuna cartella creata finora.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($all_folders_tree as $folder_item_display): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center" style="padding-left: <?php echo ($folder_item_display['level'] * 20 + 15); ?>px !important; border-left: <?php echo ($folder_item_display['level'] * 3); ?>px solid #<?php echo substr(md5((string)$folder_item_display['id']), 0, 6); ?>33;">
                                <div>
                                    <i class="fas fa-folder text-warning mr-2"></i>
                                    <?php echo htmlspecialchars($folder_item_display['folder_name']); ?>
                                    <small class="text-muted ml-2">(ID: <?php echo $folder_item_display['id']; ?>)</small>
                                </div>
                                <span class="folder-actions">
                                    <a href="<?php echo function_exists('generate_url') ? generate_url('admin_folder_manage', ['id' => $folder_item_display['id']]) : 'admin_manage_folder.php?folder_id=' . $folder_item_display['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Gestisci Cartella & Permessi">
                                        <i class="fas fa-cogs"></i> Gestisci
                                    </a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<p class="mt-4">
    <a href="<?php echo function_exists('generate_url') ? generate_url('admin_dashboard') : 'index.php'; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Dashboard Admin</a>
</p>

<?php
require_once __DIR__ . '/includes/footer.php';
?>