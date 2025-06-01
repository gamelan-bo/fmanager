<?php
// /var/www/html/fm/admin_folders.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_folder.php'; 

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_admin(); // Proteggi la pagina

$admin_id = $_SESSION['user_id'];
$is_current_user_admin_flag = true; // Dato che require_admin() è passato

// BLOCCO 2: Gestione della richiesta POST per la creazione della cartella
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder_submit'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $new_folder_name = $_POST['new_folder_name'] ?? '';
        $parent_folder_id_input = $_POST['parent_folder_id'] ?? '';
        
        $parent_folder_id = ($parent_folder_id_input === '0' || $parent_folder_id_input === '') ? null : (int)$parent_folder_id_input;

        $result = create_folder($new_folder_name, $parent_folder_id, $admin_id);
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
    }
    // Reindirizza dopo il POST per evitare reinvio del form e per mostrare il flash message
    header("Location: admin_folders.php"); 
    exit; // Termina l'esecuzione dello script dopo il redirect
}

// BLOCCO 3: Recupero dati per la visualizzazione della pagina (se la richiesta è GET o il POST non ha fatto exit)
// Per visualizzare l'albero, l'admin vede tutte le cartelle (o quelle che ha creato, a seconda della logica di get_folders_flat_tree)
// La funzione get_folders_flat_tree_with_permission_check con $is_requester_admin = true mostrerà tutte le cartelle.
$all_folders_tree = get_folders_flat_tree_with_permission_check($admin_id, $is_current_user_admin_flag, null, 0);
// Anche per il dropdown, l'admin dovrebbe poter selezionare qualsiasi cartella esistente come genitore.
$creatable_folders_tree = $all_folders_tree; // Possiamo riutilizzare la stessa struttura

// BLOCCO 4: Inizio output HTML
$page_title = "Gestione Cartelle";
require_once __DIR__ . '/includes/header.php'; // Ora header.php viene incluso DOPO la logica POST
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-folder-open"></i> Gestione Cartelle</h2>
    <?php // Eventuale pulsante per tornare alla dashboard admin, se non già presente nel menu ?>
</div>

<?php // I messaggi flash sono ora gestiti da header.php, che è stato appena incluso ?>

<div class="row">
    <div class="col-md-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-folder-plus"></i> Crea Nuova Cartella</div>
            <div class="card-body">
                <form action="admin_folders.php" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <div class="form-group">
                        <label for="new_folder_name">Nome Nuova Cartella:</label>
                        <input type="text" class="form-control" id="new_folder_name" name="new_folder_name" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="parent_folder_id">Cartella Genitore:</label>
                        <select class="form-control" id="parent_folder_id" name="parent_folder_id">
                            <option value="0">-- Radice (Nessun Genitore) --</option>
                            <?php 
                            if (!empty($creatable_folders_tree)):
                                foreach ($creatable_folders_tree as $folder_item): ?>
                                    <option value="<?php echo $folder_item['id']; ?>">
                                        <?php echo $folder_item['display_name']; // Già con htmlspecialchars e indentazione da get_folders_flat_tree... ?>
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
        <div class="card">
            <div class="card-header"><i class="fas fa-list-ul"></i> Struttura Cartelle Esistente</div>
            <div class="card-body">
                <?php if (empty($all_folders_tree)): ?>
                    <p class="text-muted">Nessuna cartella creata finora.</p>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($all_folders_tree as $folder_item): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center" style="padding-left: <?php echo ($folder_item['level'] * 20 + 15); ?>px !important;">
                                <div>
                                    <i class="fas fa-folder text-warning mr-2"></i>
                                    <?php echo htmlspecialchars($folder_item['folder_name']); ?>
                                    <small class="text-muted ml-2">(ID: <?php echo $folder_item['id']; ?>)</small>
                                </div>
                                <span class="folder-actions">
                                    <a href="admin_manage_folder.php?folder_id=<?php echo $folder_item['id']; ?>" class="btn btn-sm btn-outline-primary" title="Gestisci Cartella & Permessi">
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

<p class="mt-4"><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Dashboard Admin</a></p>

<?php
require_once __DIR__ . '/includes/footer.php';
?>