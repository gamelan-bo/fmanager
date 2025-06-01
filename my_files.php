<?php
// /var/www/html/fm/my_files.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_file.php'; 
require_once __DIR__ . '/includes/functions_folder.php';
require_once __DIR__ . '/includes/functions_admin.php'; // Per get_user_details_for_admin

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_login(); // Proteggi pagina

$user_id = $_SESSION['user_id']; 
$is_current_user_admin = is_admin();

// Recupera folder_id da GET o POST (priorità a GET per la navigazione, POST per il form)
// $current_folder_id_param sarà usato per costruire i redirect e per il fetch dei dati
$current_folder_id_param_get = $_GET['folder_id'] ?? null;
$current_folder_id_param_post = $_POST['current_folder_id_for_redirect'] ?? null; // Se inviato da un form

$current_folder_id_param = $current_folder_id_param_get ?? $current_folder_id_param_post ?? '0';
$current_folder_id = ($current_folder_id_param === '0' || $current_folder_id_param === '') ? null : (int)$current_folder_id_param;


// BLOCCO 2: Gestione della richiesta POST per i link pubblici
// Questa logica ora viene eseguita PRIMA di qualsiasi output HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['generate_link']) || isset($_POST['revoke_link']))) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $file_id_action = isset($_POST['file_id']) ? (int)$_POST['file_id'] : null;
        
        if ($file_id_action) {
            if (isset($_POST['generate_link'])) {
                $expiry_value = $_POST['expiry_days'] ?? '';
                $expiry_days = ($expiry_value !== '' && is_numeric($expiry_value) && (int)$expiry_value > 0) ? (int)$expiry_value : null;
                $result = generate_public_link($file_id_action, $user_id, $expiry_days); 
                $_SESSION['flash_message'] = $result['message']; 
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
                if ($result['success']) {
                    $_SESSION['show_public_link_modal_for_file_id'] = $file_id_action; 
                }
            } elseif (isset($_POST['revoke_link'])) {
                $result = revoke_public_link($file_id_action, $user_id); 
                $_SESSION['flash_message'] = $result['message']; 
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            }
        } else { 
            $_SESSION['flash_message'] = "ID file mancante per l'azione sul link pubblico."; 
            $_SESSION['flash_type'] = 'danger'; 
        }
    }
    // Costruisci l'URL di redirect per tornare alla vista corrente della cartella
    // Usa $current_folder_id che è stato determinato sopra da GET o POST (se presente per redirect)
    $redirect_query_params = [];
    if ($current_folder_id !== null) {
        $redirect_query_params['folder_id'] = $current_folder_id;
    } else { // Se $current_folder_id è null, significa radice, usa '0' per consistenza URL
        $redirect_query_params['folder_id'] = '0';
    }
    // Se $current_folder_id_param era specificamente '0', il redirect lo manterrà.
    // Se $current_folder_id_param era vuoto o non settato, il redirect andrà a ?folder_id=0 se $current_folder_id è null

    $redirect_url = "my_files.php" . (!empty($redirect_query_params) ? '?' . http_build_query($redirect_query_params) : '');
    header("Location: " . $redirect_url); 
    exit; // Termina l'esecuzione dello script dopo il redirect
}


// BLOCCO 3: Recupero dati per la visualizzazione (se la richiesta è GET o il POST non ha fatto exit)
// Controllo accesso cartella DOPO aver gestito il POST, nel caso il folder_id sia cambiato
if ($current_folder_id !== null && !can_user_access_folder_view($user_id, $current_folder_id, $is_current_user_admin)) {
    $_SESSION['flash_message'] = "Accesso negato alla cartella specificata.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: my_files.php?folder_id=0'); 
    exit;
}

$sub_folders = get_child_folders_with_permission_check($user_id, $is_current_user_admin, $current_folder_id);
$files_in_current_folder = get_files_in_folder_for_user_display($current_folder_id, $user_id, $is_current_user_admin);
$breadcrumbs = get_folder_path_breadcrumbs_with_permission_check($current_folder_id, $user_id, $is_current_user_admin);

$show_modal_for_file_id_on_load = $_SESSION['show_public_link_modal_for_file_id'] ?? null;
if ($show_modal_for_file_id_on_load) {
    unset($_SESSION['show_public_link_modal_for_file_id']);
}

// Funzioni helper locali
if (!function_exists('get_file_icon_class')) { 
    function get_file_icon_class($mime_type){ 
        if(empty($mime_type)) return 'fas fa-question-circle text-muted'; 
        if(strpos($mime_type,'image/')===0) return 'fas fa-file-image text-info'; 
        if(strpos($mime_type,'audio/')===0) return 'fas fa-file-audio text-warning'; 
        if(strpos($mime_type,'video/')===0) return 'fas fa-file-video text-purple'; 
        if($mime_type==='application/pdf') return 'fas fa-file-pdf text-danger'; 
        if(strpos($mime_type,'word')!==false || $mime_type==='application/msword' || $mime_type==='application/vnd.openxmlformats-officedocument.wordprocessingml.document') return 'fas fa-file-word text-primary'; 
        if(strpos($mime_type,'excel')!==false || $mime_type==='application/vnd.ms-excel' || $mime_type==='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') return 'fas fa-file-excel text-success'; 
        if(strpos($mime_type,'presentation')!==false || $mime_type==='application/vnd.ms-powerpoint' || $mime_type==='application/vnd.openxmlformats-officedocument.presentationml.presentation') return 'fas fa-file-powerpoint text-orange'; 
        if($mime_type==='text/plain' || $mime_type==='text/markdown') return 'fas fa-file-alt text-secondary'; 
        if(strpos($mime_type,'archive')!==false || $mime_type==='application/zip' || $mime_type==='application/x-rar-compressed' || $mime_type==='application/x-7z-compressed' || $mime_type==='application/gzip' || $mime_type==='application/x-tar') return 'fas fa-file-archive text-dark'; 
        if(strpos($mime_type,'code')!==false || $mime_type==='application/json' || $mime_type==='application/xml' || strpos($mime_type,'text/html')===0 || strpos($mime_type,'text/css')===0 || strpos($mime_type,'javascript')!==false) return 'fas fa-file-code text-indigo'; 
        return 'fas fa-file text-secondary'; 
    }
}
if (!function_exists('format_file_size')) { 
    function format_file_size($bytes, $precision = 2){ 
        if(!is_numeric($bytes)||$bytes<0)return 'N/D';if($bytes==0)return '0 B'; $u=['B','KB','MB','GB','TB','PB'];$b=max($bytes,0);$p=floor(($b?log($b):0)/log(1024));$p=min($p,count($u)-1);$b/=pow(1024,$p);return round($b,$precision).' '.$u[$p];
    }
}

// Logica Quota Utente
$conn_quota = get_db_connection(); 
$stmt_quota = $conn_quota->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?"); $quota_info_html = "";
if($stmt_quota){$stmt_quota->bind_param("i",$user_id);$stmt_quota->execute();$d=$stmt_quota->get_result()->fetch_assoc();$stmt_quota->close();if($d){$ub=(float)$d['used_space_bytes'];$tb=(float)$d['quota_bytes'];$um=round($ub/(1024*1024),2);$tm=round($tb/(1024*1024),2);$pu=($tb>0)?round(($ub/$tb)*100,1):(($ub>0)?100:0);$pc='bg-success';if($pu>=50&&$pu<80)$pc='bg-info';if($pu>=80&&$pu<95)$pc='bg-warning';if($pu>=95)$pc='bg-danger';$qt=($tm==0&&$um>0)?"{$um}MB (Q:0MB)":(($tm==0&&$um==0)?"No Q":"{$um}MB/{$tm}MB({$pu}%)");$quota_info_html="<div class='mb-3 alert alert-light border'><h6 class='alert-heading'>Utilizzo Spazio</h6><p class='card-text mb-1'>{$qt}</p>";if($tm>0)$quota_info_html.="<div class='progress mt-1' style='height:12px;'><div class='progress-bar {$pc}' role='progressbar' style='width:{$pu}%;font-size:0.7rem;line-height:12px;' aria-valuenow='{$pu}' aria-valuemin='0' aria-valuemax='100'>{$pu}%</div></div>";$quota_info_html.="</div>";}}


// BLOCCO 4: Inizio output HTML
$page_title = "I Miei File";
if ($current_folder_id !== null) {
    // Trova il nome della cartella corrente per il titolo della pagina
    foreach($breadcrumbs as $crumb) {
        if ($crumb['id'] == $current_folder_id) {
            $page_title .= " - " . htmlspecialchars($crumb['name']);
            break;
        }
    }
}
require_once __DIR__ . '/includes/header.php'; // Ora header.php è incluso DOPO la logica POST
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <h2 class="mb-2 mb-md-0"><i class="fas fa-folder-open"></i> I Miei File</h2>
    <?php 
    $upload_folder_id_for_link = ($current_folder_id === null) ? '0' : $current_folder_id;
    $upload_link = "upload.php?folder_id=" . $upload_folder_id_for_link; 
    ?>
    <a href="<?php echo $upload_link; ?>" class="btn btn-success"><i class="fas fa-upload mr-1"></i> Carica File Qui</a>
</div>

<?php echo $quota_info_html; ?>
<?php // I messaggi flash sono ora gestiti da header.php ?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb bg-light p-2">
        <?php foreach ($breadcrumbs as $index => $crumb): 
            $crumb_link_id = ($crumb['id']??'0')==='0'?'0':$crumb['id'];
            $is_active_crumb = (($current_folder_id===null&&$crumb_link_id==='0')||($current_folder_id!==null&&$crumb_link_id==$current_folder_id));
            ?>
            <?php if($is_active_crumb):?>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($crumb['name']);?></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="my_files.php?folder_id=<?php echo $crumb_link_id;?>"><?php echo htmlspecialchars($crumb['name']);?></a></li>
            <?php endif;?>
        <?php endforeach; ?>
    </ol>
</nav>

<?php if (empty($sub_folders) && empty($files_in_current_folder)): ?>
    <div class="alert alert-info mt-3">Questa cartella è vuota.</div>
<?php else: ?>
    <?php if (!empty($sub_folders)): ?>
        <h4 class="mt-4 mb-2"><i class="fas fa-folder"></i> Sottocartelle</h4>
        <div class="list-group mb-4">
            <?php foreach ($sub_folders as $folder): ?>
                <a href="my_files.php?folder_id=<?php echo $folder['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div><i class="fas fa-folder text-warning mr-2"></i> <?php echo htmlspecialchars($folder['folder_name']); ?></div>
                    <?php $folder_owner_details = get_user_details_for_admin($folder['owner_user_id']); $folder_owner_name = $folder_owner_details ? htmlspecialchars($folder_owner_details['username']) : 'Sconosciuto'; ?>
                    <small class="text-muted">Proprietario: <?php echo $folder_owner_name; ?> | Creato: <?php echo date('d/m/Y', strtotime($folder['created_at'])); ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($files_in_current_folder)): ?>
        <h4 class="mt-4 mb-2"><i class="fas fa-file-alt"></i> File</h4>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-bordered table-sm" id="filesTable">
                <thead class="thead-light"><tr>
                    <th class="text-center" style="width: 5%;">Tipo</th>
                    <th>Nome File</th><th>Proprietario</th><th>Descrizione</th>
                    <th class="text-right">Dim.</th><th>Upload</th><th>Scadenza</th>
                    <th class="text-center" style="min-width: 130px;" title="Data dell'ultimo download effettuato">Ultimo Download <i class="fas fa-calendar-alt"></i></th>
                    <th class="text-center actions-column" style="min-width: 180px;">Azioni</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($files_in_current_folder as $file): ?>
                    <?php $is_file_owner = ($user_id == $file['file_owner_id']); ?>
                    <tr>
                        <td class="text-center align-middle"><i class="<?php echo get_file_icon_class(htmlspecialchars($file['file_type'])); ?>" style="font-size: 1.6em;" title="<?php echo htmlspecialchars($file['file_type']); ?>"></i></td>
                        <td class="align-middle"><?php echo htmlspecialchars($file['original_filename']); ?></td>
                        <td class="align-middle"><span class="badge badge-pill badge-light"><?php echo htmlspecialchars($file['owner_username']); ?></span></td>
                        <td class="align-middle small"><?php echo nl2br(htmlspecialchars($file['description'] ?? 'N/D')); ?></td>
                        <td class="text-right align-middle"><?php echo format_file_size($file['file_size_bytes']); ?></td>
                        <td class="align-middle"><?php echo date('d/m/Y H:i', strtotime($file['upload_date'])); ?></td>
                        <td class="align-middle">
                            <?php if ($file['expiry_date']) { echo '<span title="'.htmlspecialchars(date('d/m/Y H:i', strtotime($file['expiry_date']))).'">'.htmlspecialchars(date('d/m/Y', strtotime($file['expiry_date']))).'</span>'; if (strtotime($file['expiry_date']) < time() && !$file['is_deleted']) echo ' <span class="badge badge-danger">Scaduto</span>'; } else { echo '<em>Mai</em>'; } ?>
                        </td>
                        <td class="text-center align-middle">
                            <?php if ($file['last_download_date']): echo '<span title="Ora esatta: '.htmlspecialchars(date('d/m/Y H:i:s', strtotime($file['last_download_date']))).'">'.htmlspecialchars(date('d/m/Y H:i', strtotime($file['last_download_date']))).'</span>'; else: echo '<em>Mai</em>'; endif; ?>
                        </td>
                        <td class="text-center align-middle actions-column">
                            <a href="download.php?file_id=<?php echo $file['id']; ?>" class="btn btn-outline-primary btn-sm m-1" title="Scarica"><i class="fas fa-download"></i></a>
                            <?php $file_public_token = $file['public_link_token'] ?? null; $file_public_expires = $file['public_link_expires_at'] ?? null; $is_link_btn_active = !empty($file_public_token); if ($is_link_btn_active && $file_public_expires && time() > strtotime($file_public_expires)) $is_link_btn_active = false; ?>
                            <button type="button" class="btn btn-outline-warning btn-sm m-1" data-toggle="modal" data-target="#shareLinkModal_<?php echo $file['id']; ?>" title="<?php echo $is_link_btn_active ? 'Gestisci Link Pubblico' : 'Genera Link Pubblico'; ?>"><i class="fas fa-share-alt"></i></button>
                            <?php if ($is_file_owner || $is_current_user_admin): ?>
                                <a href="edit_file.php?file_id=<?php echo $file['id']; ?>" class="btn btn-outline-info btn-sm m-1" title="Modifica Dettagli"><i class="far fa-edit"></i></a>
                                <form action="delete_file.php" method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare il file \'<?php echo htmlspecialchars(addslashes($file['original_filename'])); ?>\'? (Soft Delete)');">
                                    <?php echo csrf_input_field(); ?><input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                    <?php $redirect_url_my_files_delete = "my_files.php"; $redirect_params_my_files_delete = [];
                                    if ($current_folder_id !== null) $redirect_params_my_files_delete['folder_id'] = $current_folder_id; else $redirect_params_my_files_delete['folder_id'] = '0';
                                    if (!empty($redirect_params_my_files_delete)) $redirect_url_my_files_delete .= '?' . http_build_query($redirect_params_my_files_delete);?>
                                    <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirect_url_my_files_delete); ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm m-1" title="Elimina (Soft Delete)"><i class="far fa-trash-alt"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <div class="modal fade" id="shareLinkModal_<?php echo $file['id']; ?>" tabindex="-1" aria-labelledby="shareLinkModalLabel_<?php echo $file['id']; ?>" aria-hidden="true">
                        <div class="modal-dialog modal-lg"><div class="modal-content">
                            <div class="modal-header"><h5 class="modal-title">Link Pubblico: <?php echo htmlspecialchars($file['original_filename']); ?></h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                            <form action="my_files.php<?php 
                                $form_action_params = [];
                                if ($current_folder_id !== null) $form_action_params['folder_id'] = $current_folder_id;
                                elseif ($current_folder_id_param === '0') $form_action_params['folder_id'] = '0'; // Mantieni folder_id=0 se era così
                                if (!empty($form_action_params)) echo '?' . http_build_query($form_action_params);
                             ?>" method="POST">
                                <?php echo csrf_input_field(); ?> <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                <div class="modal-body"><?php $is_modal_l_active = !empty($file['public_link_token']); if ($is_modal_l_active && $file['public_link_expires_at'] && time() > strtotime($file['public_link_expires_at'])) $is_modal_l_active = false; if ($is_modal_l_active): $pub_url_disp = rtrim(SITE_URL, '/') . '/public_download.php?token=' . htmlspecialchars($file['public_link_token']); ?><p><strong>Copia link:</strong></p><div class="input-group mb-2"><input type="text" class="form-control" value="<?php echo $pub_url_disp; ?>" readonly id="publicLinkInput_<?php echo $file['id']; ?>"><div class="input-group-append"><button type="button" class="btn btn-outline-secondary" onclick="copyToClipboard('publicLinkInput_<?php echo $file['id']; ?>', this)">Copia</button></div></div><?php if ($file['public_link_expires_at']): ?><p><small class="text-muted">Scade il: <?php echo date('d/m/Y H:i', strtotime($file['public_link_expires_at'])); ?></small></p><?php else: ?><p><small class="text-muted">Senza scadenza.</small></p><?php endif; ?><?php if ($is_file_owner || $is_current_user_admin): ?><hr><button type="submit" name="revoke_link" class="btn btn-danger btn-block mb-3">Revoca Link</button><?php endif; ?><hr><p class="text-center text-muted mb-1">Oppure, <?php echo ($is_modal_l_active && ($is_file_owner || $is_current_user_admin)) ? 'genera nuovo link' : 'genera link'; ?>:</p><?php else: ?><div class="alert alert-info">Nessun link pubblico attivo.</div><?php endif; ?><div class="form-group"><label for="expiry_days_<?php echo $file['id']; ?>">Validità (giorni):</label><input type="number" class="form-control" id="expiry_days_<?php echo $file['id']; ?>" name="expiry_days" min="0" placeholder="0 o vuoto per nessuna scadenza"><small class="form-text text-muted">Senza scadenza se vuoto o 0.</small></div></div>
                                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Chiudi</button><button type="submit" name="generate_link" class="btn btn-primary"><i class="fas fa-link"></i> <?php echo ($is_modal_l_active && ($is_file_owner || $is_current_user_admin)) ? 'Nuovo Link' : 'Genera Link'; ?></button></div>
                            </form>
                        </div></div>
                    </div>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<p class="mt-4"><a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Dashboard</a></p>

<script>
function copyToClipboard(elementId, buttonElement) {var cT=document.getElementById(elementId);cT.select();cT.setSelectionRange(0,99999);try{var s=document.execCommand('copy');var m=s?'Copiato!':'Fallito';if(buttonElement){var oT=buttonElement.innerHTML;buttonElement.innerHTML=m;setTimeout(function(){buttonElement.innerHTML=oT;},2000);}}catch(err){console.error('Fallback err copia',err);if(buttonElement)buttonElement.innerHTML='Errore';}window.getSelection().removeAllRanges();}
<?php if ($show_modal_for_file_id_on_load): ?>
$(document).ready(function(){ $('#shareLinkModal_<?php echo $show_modal_for_file_id_on_load; ?>').modal('show'); });
<?php endif; ?>
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>