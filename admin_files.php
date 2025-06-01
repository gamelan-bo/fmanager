<?php
// /var/www/html/fm/admin_files.php
$page_title = "Gestione Globale File";

// BLOCCO 1: Inclusioni PHP e Setup
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_admin.php'; 
require_once __DIR__ . '/includes/functions_file.php';  

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_admin(); 
$admin_id = $_SESSION['user_id'];

// BLOCCO 2: Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore CSRF."; $_SESSION['flash_type'] = 'danger';
    } else {
        $action_result = null;
        $processed_count = 0;
        $success_count = 0;
        $selected_files_ids = $_POST['selected_files'] ?? [];

        if (isset($_POST['bulk_soft_delete_submit']) && !empty($selected_files_ids) && is_array($selected_files_ids)) {
            foreach ($selected_files_ids as $file_id_to_action) {
                $file_id_to_action = (int)$file_id_to_action;
                if ($file_id_to_action > 0) {
                    $result_single = soft_delete_file($file_id_to_action, $admin_id, false); 
                    if ($result_single['success']) $success_count++;
                    $processed_count++;
                }
            }
            if ($processed_count > 0) {
                $_SESSION['flash_message'] = "Soft delete di gruppo: {$success_count}/{$processed_count} file processati.";
                $_SESSION['flash_type'] = ($success_count === $processed_count && $success_count > 0) ? 'success' : ($success_count > 0 ? 'warning' : 'danger');
            } else {
                $_SESSION['flash_message'] = "Nessun file selezionato per il soft delete di gruppo."; $_SESSION['flash_type'] = 'info';
            }
        } elseif (isset($_POST['bulk_hard_delete_submit']) && !empty($selected_files_ids) && is_array($selected_files_ids)) {
            foreach ($selected_files_ids as $file_id_to_action) {
                $file_id_to_action = (int)$file_id_to_action;
                if ($file_id_to_action > 0) {
                    $result_single = admin_hard_delete_file($file_id_to_action, $admin_id); 
                    if ($result_single['success']) $success_count++;
                    $processed_count++;
                }
            }
            if ($processed_count > 0) {
                $_SESSION['flash_message'] = "Eliminazione definitiva di gruppo: {$success_count}/{$processed_count} file processati.";
                $_SESSION['flash_type'] = ($success_count === $processed_count && $success_count > 0) ? 'success' : ($success_count > 0 ? 'warning' : 'danger');
            } else {
                $_SESSION['flash_message'] = "Nessun file selezionato per l'eliminazione definitiva di gruppo."; $_SESSION['flash_type'] = 'info';
            }
        } elseif (isset($_POST['file_id_action'])) { // Azioni singole
            $file_id_to_action = (int)$_POST['file_id_action'];
            if (isset($_POST['restore_file_submit'])) {
                $action_result = admin_restore_file($file_id_to_action, $admin_id);
            } 
            // Hard delete singolo è già nel form della tabella, gestito dal POST 'hard_delete_file_submit' con 'file_id_action'
            // Questa sezione è per le azioni singole che potrebbero essere aggiunte qui, ma per ora il bulk è separato.
            // Per coerenza, l'hard delete singolo potrebbe anche essere un submit con un nome diverso.
            // L'attuale hard delete singolo nella tabella invia 'hard_delete_file_submit', quindi rientra nel blocco sopra
            // se $selected_files_ids non viene impostato e si legge $_POST['file_id_action'] nel loop.
            // Ho modificato la logica per distinguere meglio.
            
            if ($action_result) {
                $_SESSION['flash_message'] = $action_result['message'];
                $_SESSION['flash_type'] = $action_result['success'] ? 'success' : 'danger';
            }
        }
    }
    // Ricostruisci i parametri GET per il redirect
    $redirect_params = [];
    if (isset($_GET['p'])) $redirect_params['p'] = $_GET['p'];
    if (isset($_GET['filename_filter'])) $redirect_params['filename_filter'] = $_GET['filename_filter'];
    if (isset($_GET['owner_username_filter'])) $redirect_params['owner_username_filter'] = $_GET['owner_username_filter'];
    if (isset($_GET['show_deleted'])) $redirect_params['show_deleted'] = $_GET['show_deleted'];
    header("Location: admin_files.php" . (!empty($redirect_params) ? '?' . http_build_query($redirect_params) : '')); 
    exit;
}

// BLOCCO 3: Recupero dati per la visualizzazione
$filters = [];
$filters['filename_filter'] = trim($_GET['filename_filter'] ?? '');
$filters['owner_username_filter'] = trim($_GET['owner_username_filter'] ?? '');
$filters['show_deleted'] = $_GET['show_deleted'] ?? 'active'; 
$items_per_page = 20; 
$current_page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
$current_page = max(1, $current_page);
$result_data = get_all_files_for_admin_view($filters, $current_page, $items_per_page);
$all_files = $result_data['files'];
$total_files_count = $result_data['total_count'];
$total_pages = $result_data['total_pages'];

if (!function_exists('get_file_icon_class')) { /* ... definizione ... */ }
if (!function_exists('format_file_size')) { /* ... definizione ... */ }
if (!function_exists('get_file_icon_class')) { function get_file_icon_class($mime_type){ if(empty($mime_type)) return 'fas fa-question-circle text-muted'; if(strpos($mime_type,'image/')===0) return 'fas fa-file-image text-info'; if(strpos($mime_type,'audio/')===0) return 'fas fa-file-audio text-warning'; if(strpos($mime_type,'video/')===0) return 'fas fa-file-video text-purple'; if($mime_type==='application/pdf') return 'fas fa-file-pdf text-danger'; if(strpos($mime_type,'word')!==false) return 'fas fa-file-word text-primary'; if(strpos($mime_type,'excel')!==false) return 'fas fa-file-excel text-success'; if(strpos($mime_type,'presentation')!==false) return 'fas fa-file-powerpoint text-orange'; if($mime_type==='text/plain') return 'fas fa-file-alt text-secondary'; if(strpos($mime_type,'archive')!==false) return 'fas fa-file-archive text-dark'; if(strpos($mime_type,'code')!==false || $mime_type==='application/json' || $mime_type==='application/xml' || strpos($mime_type,'text/html')===0 || strpos($mime_type,'text/css')===0 || strpos($mime_type,'javascript')!==false) return 'fas fa-file-code text-indigo'; return 'fas fa-file text-secondary'; }}
if (!function_exists('format_file_size')) { function format_file_size($bytes, $precision = 2){ if(!is_numeric($bytes)||$bytes<0)return 'N/D';if($bytes==0)return '0 B'; $u=['B','KB','MB','GB','TB','PB'];$b=max($bytes,0);$p=floor(($b?log($b):0)/log(1024));$p=min($p,count($u)-1);$b/=pow(1024,$p);return round($b,$precision).' '.$u[$p];}}

$query_params_for_pagination = $filters;

// BLOCCO 4: Inizio output HTML
$page_title = "Gestione Globale File";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-archive"></i> Gestione Globale dei File</h2>
</div>

<form method="GET" action="admin_files.php" class="form-inline mb-4 p-3 bg-light border rounded">
    <?php /* ... Form Filtri come prima ... */ ?>
    <div class="form-group mr-2 mb-2"><input type="text" name="filename_filter" class="form-control form-control-sm" placeholder="Nome file..." value="<?php echo htmlspecialchars($filters['filename_filter']); ?>"></div>
    <div class="form-group mr-2 mb-2"><input type="text" name="owner_username_filter" class="form-control form-control-sm" placeholder="Proprietario..." value="<?php echo htmlspecialchars($filters['owner_username_filter']); ?>"></div>
    <div class="form-group mr-2 mb-2"><label for="show_deleted_filter" class="mr-1 small">Stato:</label><select name="show_deleted" id="show_deleted_filter" class="form-control form-control-sm"><option value="active" <?php if ($filters['show_deleted'] === 'active') echo 'selected'; ?>>Attivi</option><option value="deleted" <?php if ($filters['show_deleted'] === 'deleted') echo 'selected'; ?>>Eliminati</option><option value="all" <?php if ($filters['show_deleted'] === 'all') echo 'selected'; ?>>Tutti</option></select></div>
    <button type="submit" class="btn btn-primary btn-sm mb-2"><i class="fas fa-filter"></i> Filtra</button>
    <a href="admin_files.php" class="btn btn-secondary btn-sm mb-2 ml-2"><i class="fas fa-times"></i> Reset</a>
</form>

<?php if (empty($all_files) && $total_files_count === 0 && empty(array_filter($filters)) ): ?> <div class="alert alert-info">Nessun file.</div>
<?php elseif (empty($all_files) && !empty(array_filter($filters))): ?> <div class="alert alert-warning">Nessun file per i filtri. <a href="admin_files.php">Reset</a>.</div>
<?php elseif (empty($all_files) && $current_page > 1): ?> <div class="alert alert-warning">Nessun file per pag <?php echo htmlspecialchars($current_page); ?>. <a href="?p=1&<?php echo http_build_query($query_params_for_pagination);?>">Prima pag.</a>.</div>
<?php else: ?>
    <form id="bulk_action_form" action="admin_files.php?<?php echo http_build_query(array_merge(['p'=>$current_page], $filters)); ?>" method="POST">
        <?php echo csrf_input_field(); ?>
        <div class="mb-3 d-flex align-items-center">
            <button type="submit" name="bulk_soft_delete_submit" class="btn btn-warning btn-sm mr-2" id="bulk_soft_delete_button" disabled onclick="return confirm('Sei sicuro di voler eliminare (soft delete) i file selezionati?');">
                <i class="fas fa-trash"></i> Elimina Selezionati (Soft)
            </button>
            <button type="submit" name="bulk_hard_delete_submit" class="btn btn-danger btn-sm" id="bulk_hard_delete_button" disabled onclick="return confirm('ATTENZIONE ELIMINAZIONE DEFINITIVA!\nSei sicuro di voler eliminare PERMANENTEMENTE i file selezionati (DB e disco)? L\'azione è IRREVERSIBILE.');">
                <i class="fas fa-skull-crossbones"></i> Elimina Selezionati (Definitivo)
            </button>
            <small class="ml-2 text-muted">(Seleziona i file dalla tabella sottostante per abilitare le azioni di gruppo)</small>
        </div>

        <p class="text-muted">Trovati <?php echo $total_files_count; ?> file. Visualizzati <?php echo count($all_files); ?> (Pagina <?php echo $current_page; ?> di <?php echo $total_pages; ?>).</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-bordered table-sm">
                <thead class="thead-light"><tr>
                    <th class="text-center" style="width: 30px;"><input type="checkbox" id="select_all_files_header" title="Seleziona/Deseleziona tutti in questa pagina"></th>
                    <th>ID</th><th class="text-center">Tipo</th><th>Nome File</th><th>Proprietario</th><th>Cartella</th>
                    <th class="text-right">Dim.</th><th>Upload</th><th>Scadenza</th>
                    <th class="text-center">Stato</th><th class="text-center" style="min-width:220px;">Azioni Singole</th>
                </tr></thead><tbody>
                <?php foreach ($all_files as $file): ?>
                <tr class="<?php if($file['is_deleted']) echo 'table-light text-muted font-italic'; ?>">
                    <td class="text-center"><input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file_checkbox"></td>
                    <td><?php echo $file['id']; ?></td>
                    <td class="text-center"><i class="<?php echo get_file_icon_class(htmlspecialchars($file['file_type'])); ?>" style="font-size:1.5em;" title="<?php echo htmlspecialchars($file['file_type']); ?>"></i></td>
                    <td><?php echo htmlspecialchars($file['original_filename']); ?></td>
                    <td><?php echo htmlspecialchars($file['owner_username']); ?> <small>(ID:<?php echo $file['file_owner_id']; ?>)</small></td>
                    <td><?php echo $file['folder_name'] ? htmlspecialchars($file['folder_name']) : '<em>Radice</em>'; ?></td>
                    <td class="text-right"><?php echo format_file_size($file['file_size_bytes']); ?></td>
                    <td><?php echo date('d/m/y H:i', strtotime($file['upload_date'])); ?></td>
                    <td><?php if($file['expiry_date']){echo date('d/m/y', strtotime($file['expiry_date'])); if(strtotime($file['expiry_date'])<time()&&!$file['is_deleted'])echo ' <span class="badge badge-danger">Scaduto</span>';}else{echo '<em>Mai</em>';}?></td>
                    <td class="text-center">
                        <?php if($file['is_deleted']): ?><span class="badge badge-secondary">Eliminato</span><small class="d-block">(<?php echo date('d/m/y', strtotime($file['deleted_at'])); ?> da <?php echo htmlspecialchars($file['deleted_by_username'] ?? 'Sistema'); ?>)</small>
                        <?php else: ?><span class="badge badge-success">Attivo</span><?php endif; ?>
                    </td>
                    <td class="text-center"> <?php // Azioni singole ?>
                        <a href="download.php?file_id=<?php echo $file['id']; ?>" class="btn btn-outline-primary btn-sm m-1" title="Scarica"><i class="fas fa-download"></i></a>
                        <a href="edit_file.php?file_id=<?php echo $file['id']; ?>&context=admin" class="btn btn-outline-info btn-sm m-1" title="Modifica"><i class="fas fa-edit"></i></a>
                        <?php if ($file['is_deleted']): ?>
                            <form action="admin_files.php?<?php echo http_build_query(array_merge(['p'=>$current_page], $filters)); ?>" method="POST" class="d-inline" onsubmit="return confirm('Ripristinare file ID <?php echo $file['id']; ?>?');">
                                <?php echo csrf_input_field(); ?><input type="hidden" name="file_id_action" value="<?php echo $file['id']; ?>">
                                <button type="submit" name="restore_file_submit" class="btn btn-outline-success btn-sm m-1" title="Ripristina"><i class="fas fa-undo"></i></button>
                            </form>
                            <?php // L'hard delete singolo è qui sotto, come parte del form bulk, ma potremmo volerlo anche come azione singola qui ?>
                             <button type="submit" form="bulk_action_form" name="hard_delete_file_submit_single" value="<?php echo $file['id']; ?>" class="btn btn-danger btn-sm m-1" title="Elimina Definitivamente (Singolo)" onclick="document.getElementById('single_hard_delete_id').value='<?php echo $file['id']; ?>'; return confirm('ELIMINAZIONE DEFINITIVA SINGOLA!\nFile: <?php echo htmlspecialchars(addslashes($file['original_filename'])); ?> (ID: <?php echo $file['id']; ?>).\nSei sicuro?');">
                                <i class="fas fa-skull-crossbones"></i>
                            </button>
                        <?php else: ?>
                            <form action="delete_file.php" method="POST" class="d-inline" onsubmit="return confirm('Eliminare (soft delete) file ID <?php echo $file['id']; ?>?');">
                                <?php echo csrf_input_field(); ?> <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>"><input type="hidden" name="admin_action" value="1">
                                <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF']).'?p='.$current_page . '&' . http_build_query($filters)); ?>">
                                <button type="submit" class="btn btn-outline-warning btn-sm m-1" title="Soft Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?></tbody></table>
        </div>
        <input type="hidden" name="single_hard_delete_id" id="single_hard_delete_id" value=""> <?php // Per l'hard delete singolo?>
    </form> 

    <?php if ($total_pages > 1): ?><nav><ul class="pagination justify-content-center mt-4">
        <?php /* ... Paginazione come prima ... */ ?>
    </ul></nav><?php endif; ?>
<?php endif; ?>
<p class="mt-4"><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna Dashboard Admin</a></p>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllHeaderCheckbox = document.getElementById('select_all_files_header');
    const fileCheckboxes = document.querySelectorAll('.file_checkbox');
    const bulkSoftDeleteButton = document.getElementById('bulk_soft_delete_button');
    const bulkHardDeleteButton = document.getElementById('bulk_hard_delete_button'); // Nuovo

    function toggleBulkButtons() {
        let oneChecked = false;
        fileCheckboxes.forEach(function(checkbox) {
            if (checkbox.checked) oneChecked = true;
        });
        if (bulkSoftDeleteButton) bulkSoftDeleteButton.disabled = !oneChecked;
        if (bulkHardDeleteButton) bulkHardDeleteButton.disabled = !oneChecked; // Abilita/Disabilita anche questo
    }

    if (selectAllHeaderCheckbox) {
        selectAllHeaderCheckbox.addEventListener('change', function() {
            fileCheckboxes.forEach(function(checkbox) { checkbox.checked = selectAllHeaderCheckbox.checked; });
            toggleBulkButtons();
        });
    }
    fileCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            let allFileCheckboxesChecked = true;
            fileCheckboxes.forEach(function(cb) { if (!cb.checked) allFileCheckboxesChecked = false; });
            if(selectAllHeaderCheckbox) selectAllHeaderCheckbox.checked = allFileCheckboxesChecked;
            toggleBulkButtons();
        });
    });
    toggleBulkButtons(); // Stato iniziale
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>