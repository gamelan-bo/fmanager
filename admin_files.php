<?php
// /var/www/html/fm/admin_files.php

// BLOCCO 1: Inclusioni PHP e Setup
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_admin.php'; 
require_once __DIR__ . '/includes/functions_file.php';  
if (!function_exists('format_file_size') && file_exists(__DIR__ . '/includes/functions_utils.php')) {
    require_once __DIR__ . '/includes/functions_utils.php';
} elseif (!function_exists('format_file_size')) {
    function format_file_size($bytes, $p = 2){ if($bytes<=0)return "0 B"; $u=['B','KB','MB','GB','TB']; return round($bytes/pow(1024,($i=floor(log($bytes,1024)))),$p).' '.$u[$i]; }
}
if (!function_exists('get_file_icon_class')) { 
    function get_file_icon_class($mime_type){ return 'fas fa-file'; }
}

if (session_status() == PHP_SESSION_NONE) { session_start(); }
require_admin(); 
$admin_id = $_SESSION['user_id'];

// BLOCCO 2: Gestione Azioni POST
$current_get_params = $_GET; // Mantiene tutti i parametri GET (filtri e paginazione)
unset($current_get_params['file_id_action'], $current_get_params['action_nonce']); // Rimuovi parametri di azione specifici se presenti

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore CSRF."; $_SESSION['flash_type'] = 'danger';
    } else {
        $action_result = null; $processed_count = 0; $success_count = 0;
        $selected_files_ids = $_POST['selected_files'] ?? [];

        if (isset($_POST['bulk_soft_delete_submit']) && !empty($selected_files_ids) && is_array($selected_files_ids)) {
            foreach ($selected_files_ids as $file_id) { $res = admin_soft_delete_file((int)$file_id, $admin_id); $processed_count++; if($res['success']) $success_count++; }
            if ($processed_count > 0) { $_SESSION['flash_message'] = "Azione eseguita su {$processed_count} file. Successi: {$success_count}."; $_SESSION['flash_type'] = ($success_count == $processed_count) ? 'success' : 'warning'; } else { $_SESSION['flash_message'] = "Nessun file selezionato."; $_SESSION['flash_type'] = 'info'; }
        } elseif (isset($_POST['bulk_hard_delete_submit']) && !empty($selected_files_ids) && is_array($selected_files_ids)) {
            foreach ($selected_files_ids as $file_id) { $res = admin_hard_delete_file((int)$file_id, $admin_id); $processed_count++; if($res['success']) $success_count++; }
            if ($processed_count > 0) { $_SESSION['flash_message'] = "Azione eseguita su {$processed_count} file. Successi: {$success_count}."; $_SESSION['flash_type'] = ($success_count == $processed_count) ? 'success' : 'warning'; } else { $_SESSION['flash_message'] = "Nessun file selezionato."; $_SESSION['flash_type'] = 'info'; }
        } elseif (isset($_POST['file_id_action'])) { 
            $file_id_to_action = (int)$_POST['file_id_action'];
            if (isset($_POST['restore_file_submit'])) { $action_result = admin_restore_file($file_id_to_action, $admin_id); } 
            elseif (isset($_POST['hard_delete_file_submit'])) { $action_result = admin_hard_delete_file($file_id_to_action, $admin_id); }
            if ($action_result) { $_SESSION['flash_message'] = $action_result['message']; $_SESSION['flash_type'] = $action_result['success'] ? 'success' : 'danger'; }
        }
    }
    header("Location: " . generate_url('admin_all_files', $current_get_params)); 
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
$all_files = $result_data['files']; $total_files_count = $result_data['total_count']; $total_pages = $result_data['total_pages'];

// BLOCCO 4: Inizio output HTML
$page_title = "Gestione Globale File";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-3"><h2><i class="fas fa-archive"></i> Gestione Globale dei File</h2></div>
<form method="GET" action="<?php echo generate_url('admin_all_files'); ?>" class="form-inline mb-4 p-3 bg-light border rounded">
    <div class="form-group mr-2 mb-2"><input type="text" name="filename_filter" class="form-control form-control-sm" placeholder="Nome file..." value="<?php echo htmlspecialchars($filters['filename_filter']); ?>"></div>
    <div class="form-group mr-2 mb-2"><input type="text" name="owner_username_filter" class="form-control form-control-sm" placeholder="Proprietario..." value="<?php echo htmlspecialchars($filters['owner_username_filter']); ?>"></div>
    <div class="form-group mr-2 mb-2"><label for="show_deleted_filter" class="mr-1 small">Stato:</label><select name="show_deleted" id="show_deleted_filter" class="form-control form-control-sm"><option value="active" <?php if ($filters['show_deleted'] === 'active') echo 'selected'; ?>>Attivi</option><option value="deleted" <?php if ($filters['show_deleted'] === 'deleted') echo 'selected'; ?>>Eliminati</option><option value="all" <?php if ($filters['show_deleted'] === 'all') echo 'selected'; ?>>Tutti</option></select></div>
    <button type="submit" class="btn btn-primary btn-sm mb-2"><i class="fas fa-filter"></i> Filtra</button>
    <a href="<?php echo generate_url('admin_all_files'); ?>" class="btn btn-secondary btn-sm mb-2 ml-2"><i class="fas fa-times"></i> Reset Filtri</a>
</form>

<?php if (empty($all_files) && $total_files_count === 0 && empty(array_filter($filters, 'strlen')) ): ?> <div class="alert alert-info">Nessun file nel sistema.</div>
<?php elseif (empty($all_files) && !empty(array_filter($filters, 'strlen'))): ?> <div class="alert alert-warning">Nessun file corrisponde ai filtri. <a href="<?php echo generate_url('admin_all_files'); ?>">Reset</a>.</div>
<?php elseif (empty($all_files) && $current_page > 1): ?> <div class="alert alert-warning">Nessun file per pag <?php echo htmlspecialchars($current_page); ?>. <a href="<?php echo generate_url('admin_all_files', array_merge($filters, ['p' => 1])); ?>">Prima pag.</a>.</div>
<?php else: ?>
    <?php $current_url_params_for_form_action = array_merge(['p'=>$current_page], $filters); ?>
    <form id="bulk_action_form" action="<?php echo generate_url('admin_all_files', $current_url_params_for_form_action); ?>" method="POST">
        <?php echo csrf_input_field(); ?>
        <div class="mb-3 d-flex align-items-center"><button type="submit" name="bulk_soft_delete_submit" class="btn btn-warning btn-sm mr-2" id="bulk_soft_delete_button" disabled onclick="return confirm('Eliminare (soft delete) i file selezionati?');"><i class="fas fa-trash"></i> Soft Delete Selezionati</button><button type="submit" name="bulk_hard_delete_submit" class="btn btn-danger btn-sm" id="bulk_hard_delete_button" disabled onclick="return confirm('ATTENZIONE ELIMINAZIONE DEFINITIVA!\nEliminare PERMANENTEMENTE i file selezionati? L\'azione è IRREVERSIBILE.');"><i class="fas fa-skull-crossbones"></i> Hard Delete Selezionati</button><small class="ml-2 text-muted">(Abilitati se almeno un file è selezionato)</small></div>
        <p class="text-muted">Trovati <?php echo $total_files_count; ?> file. Visualizzati <?php echo count($all_files); ?> (Pagina <?php echo $current_page; ?> di <?php echo $total_pages; ?>).</p>
        <div class="table-responsive"><table class="table table-striped table-hover table-bordered table-sm">
            <thead class="thead-light"><tr>
                <th class="text-center" style="width:30px;"><input type="checkbox" id="select_all_files_header" title="Seleziona tutti in questa pagina"></th>
                <th>ID</th><th class="text-center">Tipo</th><th>Nome File</th><th>Proprietario</th><th>Cartella</th><th class="text-right">Dim.</th><th>Upload</th><th>Scadenza</th><th class="text-center">Stato</th><th class="text-center" style="min-width:200px;">Azioni Singole</th>
            </tr></thead><tbody>
            <?php foreach ($all_files as $file): ?>
            <tr class="<?php if($file['is_deleted']) echo 'table-light text-muted font-italic'; ?>">
                <td class="text-center"><input type="checkbox" name="selected_files[]" value="<?php echo $file['id']; ?>" class="file_checkbox"></td>
                <td><?php echo $file['id']; ?></td><td class="text-center"><i class="<?php echo get_file_icon_class($file['file_type']); ?>" style="font-size:1.5em;" title="<?php echo htmlspecialchars($file['file_type']); ?>"></i></td>
                <td><?php echo htmlspecialchars($file['original_filename']); ?></td><td><?php echo htmlspecialchars($file['owner_username']); ?> <small>(ID:<?php echo $file['file_owner_id']; ?>)</small></td><td><?php echo $file['folder_name'] ? htmlspecialchars($file['folder_name']) : '<em>Radice</em>'; ?></td>
                <td class="text-right"><?php echo format_file_size($file['file_size_bytes']); ?></td><td><?php echo date('d/m/y H:i', strtotime($file['upload_date'])); ?></td>
                <td><?php if($file['expiry_date']){echo date('d/m/y',strtotime($file['expiry_date'])); if(strtotime($file['expiry_date'])<time()&&!$file['is_deleted'])echo ' <span class="badge badge-danger">Scaduto</span>';}else{echo '<em>Mai</em>';}?></td>
                <td class="text-center"><?php if($file['is_deleted']): ?><span class="badge badge-secondary">Eliminato</span><small class="d-block">(<?php echo date('d/m/y',strtotime($file['deleted_at']));?> da <?php echo htmlspecialchars($file['deleted_by_username']??'Sistema');?>)</small><?php else: ?><span class="badge badge-success">Attivo</span><?php endif; ?></td>
                <td class="text-center">
                    <a href="<?php echo generate_url('file_download', ['id' => $file['id']]); ?>" class="btn btn-outline-primary btn-sm m-1" title="Scarica"><i class="fas fa-download"></i></a>
                    <?php $admin_files_return_url = generate_url('admin_all_files', $current_url_params_for_form_action); ?>
                    <a href="<?php echo generate_url('file_edit', ['id' => $file['id'], 'return_to' => $admin_files_return_url]); ?>" class="btn btn-outline-info btn-sm m-1" title="Modifica"><i class="fas fa-edit"></i></a>
                    <?php if ($file['is_deleted']): ?>
                        <button type="submit" name="restore_file_submit" value="1" formaction="<?php echo generate_url('admin_all_files', array_merge($current_url_params_for_form_action, ['file_id_action' => $file['id']])); ?>" class="btn btn-outline-success btn-sm m-1" title="Ripristina" onclick="return confirm('Ripristinare file ID <?php echo $file['id']; ?>?');"><i class="fas fa-undo"></i></button>
                        <button type="submit" name="hard_delete_file_submit" value="1" formaction="<?php echo generate_url('admin_all_files', array_merge($current_url_params_for_form_action, ['file_id_action' => $file['id']])); ?>" class="btn btn-danger btn-sm m-1" title="Elimina Definitivamente" onclick="return confirm('ELIMINAZIONE DEFINITIVA!\nFile: <?php echo htmlspecialchars(addslashes($file['original_filename'])); ?> (ID: <?php echo $file['id']; ?>).\nSicuro?');"><i class="fas fa-skull-crossbones"></i></button>
                    <?php else: ?>
                        <form action="<?php echo generate_url('delete_file_action'); ?>" method="POST" class="d-inline" onsubmit="return confirm('Eliminare (soft) file ID <?php echo $file['id']; ?>?');">
                            <?php echo csrf_input_field(); ?> <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>"><input type="hidden" name="admin_action" value="1">
                            <input type="hidden" name="redirect_to" value="<?php echo generate_url('admin_all_files', $current_url_params_for_form_action); ?>">
                            <button type="submit" class="btn btn-outline-warning btn-sm m-1" title="Soft Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?></tbody></table></div>
    </form> 
    <?php if ($total_pages > 1): ?><nav><ul class="pagination justify-content-center mt-4">
        <?php if ($current_page > 1): ?><li class="page-item"><a class="page-link" href="<?php echo generate_url('admin_all_files', array_merge($filters, ['p'=>1])); ?>">Primo</a></li><li class="page-item"><a class="page-link" href="<?php echo generate_url('admin_all_files', array_merge($filters, ['p'=>$current_page-1])); ?>">Prec.</a></li><?php else: ?><li class="page-item disabled"><span class="page-link">Primo</span></li><li class="page-item disabled"><span class="page-link">Prec.</span></li><?php endif; ?>
        <?php $range=2; $s_range=max(1,$current_page-$range); $e_range=min($total_pages,$current_page+$range); if($s_range>1&&$s_range>2)echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; elseif($s_range>1&&$s_range==2)echo '<li class="page-item"><a class="page-link" href="'.generate_url('admin_all_files',array_merge($filters,['p'=>1])).'">1</a></li>'; for($i=$s_range;$i<=$e_range;$i++):?><li class="page-item <?php if($i==$current_page)echo 'active';?>"><a class="page-link" href="<?php echo generate_url('admin_all_files', array_merge($filters, ['p'=>$i]));?>"><?php echo $i;?></a></li><?php endfor; if($e_range<$total_pages&&$e_range<$total_pages-1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; elseif($e_range<$total_pages&&$e_range==$total_pages-1) echo '<li class="page-item"><a class="page-link" href="'.generate_url('admin_all_files',array_merge($filters,['p'=>$total_pages])).'">'.$total_pages.'</a></li>';?>
        <?php if ($current_page < $total_pages): ?><li class="page-item"><a class="page-link" href="<?php echo generate_url('admin_all_files', array_merge($filters, ['p'=>$current_page+1])); ?>">Succ.</a></li><li class="page-item"><a class="page-link" href="<?php echo generate_url('admin_all_files', array_merge($filters, ['p'=>$total_pages])); ?>">Ultimo</a></li><?php else: ?><li class="page-item disabled"><span class="page-link">Succ.</span></li><li class="page-item disabled"><span class="page-link">Ultimo</span></li><?php endif; ?>
    </ul></nav><?php endif; ?>
<?php endif; ?>
<p class="mt-4"><a href="<?php echo generate_url('admin_dashboard'); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna Dashboard Admin</a></p>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAllCheckbox = document.getElementById('select_all_files_header');
    const fileCheckboxes = document.querySelectorAll('.file_checkbox');
    const bulkSoftDeleteButton = document.getElementById('bulk_soft_delete_button');
    const bulkHardDeleteButton = document.getElementById('bulk_hard_delete_button');

    function toggleBulkButtons() {
        // Cerca se almeno una checkbox è selezionata
        const anyChecked = [...fileCheckboxes].some(cb => cb.checked);
        if(bulkSoftDeleteButton) bulkSoftDeleteButton.disabled = !anyChecked;
        if(bulkHardDeleteButton) bulkHardDeleteButton.disabled = !anyChecked;
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            fileCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            toggleBulkButtons();
        });
    }

    fileCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            // Controlla se tutte le checkbox sono selezionate per aggiornare lo stato di 'select all'
            if (!this.checked) {
                selectAllCheckbox.checked = false;
            } else {
                const allChecked = [...fileCheckboxes].every(cb => cb.checked);
                selectAllCheckbox.checked = allChecked;
            }
            toggleBulkButtons();
        });
    });

    // Inizializza lo stato dei pulsanti al caricamento della pagina
    toggleBulkButtons();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>