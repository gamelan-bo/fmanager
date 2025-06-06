<?php
// /var/www/html/fm/upload.php

// BLOCCO 1: Inclusioni PHP e Setup
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_file.php';  
require_once __DIR__ . '/includes/functions_folder.php';
if (!function_exists('format_file_size') && file_exists(__DIR__ . '/includes/functions_utils.php')) {
    require_once __DIR__ . '/includes/functions_utils.php';
} elseif (!function_exists('format_file_size')) {
    function format_file_size($bytes, $precision = 2){ if(!is_numeric($bytes)||$bytes<0)return 'N/D';if($bytes==0)return '0 B'; $u=['B','KB','MB','GB','TB','PB'];$b=max($bytes,0);$p=floor(($b?log($b):0)/log(1024));$p=min($p,count($u)-1);$b/=pow(1024,$p);return round($b,$precision).' '.$u[$p];}
}

if (session_status() == PHP_SESSION_NONE) { if (defined('SESSION_NAME')) session_name(SESSION_NAME); session_start(); }
require_login(); 

$user_id = $_SESSION['user_id'];
$is_user_admin_flag_for_upload_check = is_admin();
$target_folder_id_param = $_GET['folder_id'] ?? $_POST['folder_id'] ?? '0';
$target_folder_id = ($target_folder_id_param === '0' || $target_folder_id_param === '') ? null : (int)$target_folder_id_param;

// BLOCCO 2: Gestione Richiesta AJAX POST per l'upload
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' ) {
    header('Content-Type: application/json');
    $response = ['success_messages' => [], 'error_messages' => [], 'overall_status' => 'error_initial'];
    try {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) { throw new Exception("Errore CSRF."); }
        if ($target_folder_id !== null) { if (!can_user_access_folder_view($user_id, $target_folder_id, $is_user_admin_flag_for_upload_check)) { $folder_details_for_error = get_folder_details($target_folder_id); $folder_name_for_error = $folder_details_for_error ? htmlspecialchars($folder_details_for_error['folder_name']) : "ID:".$target_folder_id; throw new Exception("Permesso negato per caricare nella cartella '{$folder_name_for_error}'."); } }
        if (isset($_FILES['files_to_upload'])) {
            $description = trim($_POST['description'] ?? ''); $uploaded_files_count = 0;
            if (is_array($_FILES['files_to_upload']['name'])) { $filtered_names = array_filter($_FILES['files_to_upload']['name']); $uploaded_files_count = count($filtered_names); } 
            elseif (!empty($_FILES['files_to_upload']['name']) && $_FILES['files_to_upload']['error'] !== UPLOAD_ERR_NO_FILE) { $uploaded_files_count = 1; }
            $total_upload_size = 0; $files_to_process_ajax = [];
            if ($uploaded_files_count > 0) {
                if (is_array($_FILES['files_to_upload']['name'])) { for ($i = 0; $i < count($_FILES['files_to_upload']['name']); $i++) { if (empty($_FILES['files_to_upload']['name'][$i]) || $_FILES['files_to_upload']['error'][$i] === UPLOAD_ERR_NO_FILE) continue; if ($_FILES['files_to_upload']['error'][$i] === UPLOAD_ERR_OK && $_FILES['files_to_upload']['size'][$i] > 0) { $files_to_process_ajax[] = ['name' => $_FILES['files_to_upload']['name'][$i], 'type' => $_FILES['files_to_upload']['type'][$i], 'tmp_name' => $_FILES['files_to_upload']['tmp_name'][$i], 'error' => $_FILES['files_to_upload']['error'][$i], 'size' => $_FILES['files_to_upload']['size'][$i]]; $total_upload_size += (int)$_FILES['files_to_upload']['size'][$i]; } else { $response['error_messages'][] = "File \"".htmlspecialchars($_FILES['files_to_upload']['name'][$i])."\": Errore (cod: " . $_FILES['files_to_upload']['error'][$i] . ")"; }}}
                else { if ($_FILES['files_to_upload']['error'] === UPLOAD_ERR_OK && $_FILES['files_to_upload']['size'] > 0) { $files_to_process_ajax[] = $_FILES['files_to_upload']; $total_upload_size = (int)$_FILES['files_to_upload']['size']; } elseif ($_FILES['files_to_upload']['error'] !== UPLOAD_ERR_NO_FILE) { $response['error_messages'][] = "File \"".htmlspecialchars($_FILES['files_to_upload']['name'])."\": Errore (cod: " . $_FILES['files_to_upload']['error'] . ")";}}
            }
            if (empty($files_to_process_ajax) && empty($response['error_messages'])) $response['error_messages'][] = "Nessun file valido.";
            if (!empty($files_to_process_ajax)) {
                $conn_ajax = get_db_connection(); $user_stmt_ajax = $conn_ajax->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?"); $can_proceed_with_quota_ajax = false;
                if ($user_stmt_ajax) { $user_stmt_ajax->bind_param("i", $user_id); $user_stmt_ajax->execute(); $user_data_ajax = $user_stmt_ajax->get_result()->fetch_assoc(); $user_stmt_ajax->close(); if ($user_data_ajax) { if (($user_data_ajax['used_space_bytes'] + $total_upload_size) > $user_data_ajax['quota_bytes']) { $response['error_messages'][] = "Spazio insuff. (".format_file_size($total_upload_size).")."; } else { $can_proceed_with_quota_ajax = true; }} else { $response['error_messages'][] = "Err utente quota."; }} else { log_error("AJAX Upload: DB Err (user_stmt): " . ($conn_ajax->error ?? 'N/A')); $response['error_messages'][] = "Err server quota."; }
                if ($can_proceed_with_quota_ajax) { foreach ($files_to_process_ajax as $current_file_input_ajax) { $result_ajax = handle_file_upload($current_file_input_ajax, $user_id, $description, $target_folder_id); if ($result_ajax['success']) { $response['success_messages'][] = $result_ajax['message']; } else { $response['error_messages'][] = "File \"".htmlspecialchars($current_file_input_ajax['name'])."\": " . $result_ajax['message']; }}}
            }
        } else { $response['error_messages'][] = "Nessun file inviato."; }
        if (empty($response['error_messages']) && !empty($response['success_messages'])) $response['overall_status'] = 'success'; elseif (!empty($response['error_messages']) && empty($response['success_messages'])) $response['overall_status'] = 'error'; elseif (!empty($response['error_messages']) && !empty($response['success_messages'])) $response['overall_status'] = 'partial_success'; elseif (empty($response['error_messages']) && empty($response['success_messages'])) { $response['overall_status'] = 'no_action'; if(empty($response['error_messages'])) $response['error_messages'][] = "Nessuna operazione."; }
    } catch (Exception $e) { log_error("Exc AJAX upload: " . $e->getMessage()); if (!in_array($e->getMessage(), $response['error_messages'])) $response['error_messages'][] = $e->getMessage(); $response['overall_status'] = 'fatal_error'; }
    echo json_encode($response); exit; 
}

// BLOCCO 3: Preparazione dati per la visualizzazione della pagina (GET)
$folder_path_display = "Radice File"; $current_folder_name_for_title = "Radice";
if ($target_folder_id !== null) { /* ... Logica breadcrumbs come prima ... */ }
$quota_available_text_display = "Quota non disp."; $conn_quota_form_display = get_db_connection(); $stmt_quota_form_display = $conn_quota_form_display->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?");
if ($stmt_quota_form_display) { /* ... logica quota come prima ... */ }

// BLOCCO 4: Inizio output HTML
$page_title = "Carica File in: " . htmlspecialchars($current_folder_name_for_title);
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Carica Nuovi File</h2>
    <?php $back_link_route = ($target_folder_id !== null) ? 'folder_view' : 'my_files_root'; $back_link_params = ($target_folder_id !== null) ? ['id' => $target_folder_id] : []; ?>
    <a href="<?php echo generate_url($back_link_route, $back_link_params); ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Torna a <?php echo htmlspecialchars($folder_path_display); ?></a>
</div>
<div class="alert alert-info"><i class="fas fa-info-circle"></i> Stai caricando in: <strong><?php echo htmlspecialchars($folder_path_display); ?></strong> (ID Cartella: <?php echo $target_folder_id ?? 'Radice Personale'; ?>)</div>
<div id="upload-response-messages" class="mb-3"></div>
<div class="card"><div class="card-body">
    <?php $form_action_route = ($target_folder_id === null) ? 'upload_to_root' : 'upload_to_folder'; $form_action_params = ($target_folder_id === null) ? [] : ['id' => $target_folder_id]; ?>
    <form id="uploadForm" action="<?php echo generate_url($form_action_route, $form_action_params); ?>" method="POST" enctype="multipart/form-data" novalidate>
        <?php echo csrf_input_field(); ?>
        <input type="hidden" name="folder_id" value="<?php echo $target_folder_id ?? ''; ?>">
        <div class="form-group"><label for="files_to_upload">Seleziona file:</label><input type="file" class="form-control-file" id="files_to_upload" name="files_to_upload[]" required multiple><small class="form-text text-muted">Max singolo: <?php echo ini_get('upload_max_filesize'); ?>. <?php echo $quota_available_text_display; ?></small></div>
        <div class="form-group"><label for="description">Descrizione:</label><textarea class="form-control" id="description" name="description" rows="3" maxlength="500" placeholder="Breve descrizione..."></textarea></div>
        <div class="form-group" id="progress-container" style="display: none;"><label>Avanzamento:</label><div class="progress"><div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div></div><div id="upload-status-text" class="mt-1 small"></div><div id="bytes-uploaded-text" class="mt-1 small"></div></div>
        <button type="submit" class="btn btn-primary" id="uploadButton"><i class="fas fa-cloud-upload-alt"></i> Carica</button>
        <a href="<?php echo generate_url($back_link_route, $back_link_params); ?>" class="btn btn-secondary ml-2">Annulla</a>
    </form>
</div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    console.log('[UploadJS] DOMContentLoaded - Script avviato.'); // DEBUG
    const uploadForm = document.getElementById('uploadForm');
    const uploadButton = document.getElementById('uploadButton');
    const progressBar = document.getElementById('progressBar');
    const progressContainer = document.getElementById('progress-container');
    const uploadStatusText = document.getElementById('upload-status-text');
    const bytesUploadedText = document.getElementById('bytes-uploaded-text');
    const responseMessagesContainer = document.getElementById('upload-response-messages');
    const fileInput = document.getElementById('files_to_upload');

    if (!uploadForm) { console.error('[UploadJS] Errore: Elemento form "uploadForm" non trovato!'); return; }
    if (!fileInput) { console.error('[UploadJS] Errore: Elemento input file "files_to_upload" non trovato!'); return; }
    if (!uploadButton) { console.error('[UploadJS] Errore: Pulsante "uploadButton" non trovato!'); return; }
    
    const ajaxUploadUrl = uploadForm.getAttribute('action');
    console.log('[UploadJS] URL per AJAX preso da form action:', ajaxUploadUrl); // DEBUG

    if (!ajaxUploadUrl) {
        console.error('[UploadJS] Errore: Attributo action del form è vuoto o non definito.');
        displayResponseMessage('Errore configurazione form (action mancante).', 'danger');
        return;
    }

    uploadForm.addEventListener('submit', function (event) {
        console.log('[UploadJS] Evento submit del form catturato.'); // DEBUG
        event.preventDefault(); 
        responseMessagesContainer.innerHTML = ''; 
        
        const files = fileInput.files;
        if (files.length === 0) {
            console.log('[UploadJS] Nessun file selezionato.'); // DEBUG
            displayResponseMessage('Nessun file selezionato per l\'upload.', 'warning');
            return;
        }
        console.log(`[UploadJS] Numero di file selezionati: ${files.length}`); // DEBUG

        const formData = new FormData(uploadForm); 
        console.log('[UploadJS] FormData creato.'); // DEBUG
        // Puoi loggare i campi di formData se necessario, ma non i file stessi
        // for (var pair of formData.entries()) { console.log('[UploadJS] FormData entry: ' + pair[0]+ ', ' + pair[1]); }


        const xhr = new XMLHttpRequest();
        console.log('[UploadJS] Tentativo di xhr.open("POST", "' + ajaxUploadUrl + '", true)'); // DEBUG
        xhr.open('POST', ajaxUploadUrl, true); 
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); 
        
        xhr.upload.onprogress = function (event) {
            if (event.lengthComputable) {
                const percentComplete = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = percentComplete + '%'; progressBar.setAttribute('aria-valuenow', percentComplete); progressBar.textContent = percentComplete + '%';
                bytesUploadedText.textContent = `Caricati ${formatBytesJS(event.loaded)} di ${formatBytesJS(event.total)}`;
            }
        };
        xhr.onloadstart = function() {
            console.log('[UploadJS] xhr.onloadstart - Inizio upload.'); // DEBUG
            progressContainer.style.display = 'block'; progressBar.style.width = '0%'; progressBar.textContent = '0%';
            uploadButton.disabled = true; uploadStatusText.textContent = 'Caricamento in corso...'; bytesUploadedText.textContent = '';
        };
        xhr.onload = function () {
            console.log('[UploadJS] xhr.onload - Richiesta completata. Status: ' + xhr.status); // DEBUG
            uploadButton.disabled = false; 
            // Nascondi progress bar solo se non ci sono stati errori gravi che hanno interrotto prima
            if (xhr.status === 200 || xhr.status === 0) { // status 0 può capitare per file locali o interruzioni
                 // Non nascondere subito la progress bar, aspetta handleUploadResponse
            }
            // Mantieni uploadStatusText con "Completato" o errore, non nasconderlo subito
            // uploadStatusText.textContent = 'Completato.'; // Spostato in handleUploadResponse o gestito da lì

            if (xhr.status === 200) {
                try { 
                    const response = JSON.parse(xhr.responseText); 
                    console.log('[UploadJS] Risposta JSON ricevuta:', response); // DEBUG
                    handleUploadResponse(response); 
                }
                catch (e) { 
                    displayResponseMessage('Errore parsing risposta server: ' + escapeHtml(e.message) + '. Controlla la console per dettagli sulla risposta.', 'danger'); 
                    console.error("[UploadJS] Errore parsing JSON:", e);
                    console.error("[UploadJS] Risposta grezza dal server:", xhr.responseText); 
                }
            } else { 
                displayResponseMessage('Errore server durante l\'upload: ' + xhr.status + ' ' + escapeHtml(xhr.statusText), 'danger'); 
                console.error("[UploadJS] Errore server:", xhr.status, xhr.statusText, xhr.responseText); 
                uploadStatusText.textContent = 'Errore server.';
            }
            fileInput.value = ''; // Pulisci l'input file in ogni caso dopo il tentativo
        };
        xhr.onerror = function () { 
            console.error('[UploadJS] xhr.onerror - Errore di rete o CORS.'); // DEBUG
            uploadButton.disabled = false; progressContainer.style.display = 'none'; 
            displayResponseMessage('Errore di rete durante l\'upload.', 'danger'); 
            uploadStatusText.textContent = 'Errore di rete.';
        };
        xhr.onabort = function () { 
            console.log('[UploadJS] xhr.onabort - Upload annullato.'); // DEBUG
            uploadButton.disabled = false; progressContainer.style.display = 'none'; 
            displayResponseMessage('Upload annullato.', 'warning'); 
            uploadStatusText.textContent = 'Annullato.'; 
        };
        
        console.log('[UploadJS] Invio xhr.send(formData)'); // DEBUG
        xhr.send(formData);
    });

    function handleUploadResponse(response) {
        let messagesHtml = "";
        let hasErrors = false;
        if (response.success_messages && response.success_messages.length > 0) {
            messagesHtml += "<div class='alert alert-success p-2'><strong>File caricati con successo:</strong><ul class='mb-0 pl-3'>";
            response.success_messages.forEach(msg => { messagesHtml += `<li>${escapeHtml(msg)}</li>`; });
            messagesHtml += "</ul></div>";
        }
        if (response.error_messages && response.error_messages.length > 0) {
            hasErrors = true;
            messagesHtml += "<div class='alert alert-danger p-2'><strong>Errori durante l'upload:</strong><ul class='mb-0 pl-3'>";
            response.error_messages.forEach(msg => { messagesHtml += `<li>${escapeHtml(msg)}</li>`; });
            messagesHtml += "</ul></div>";
        }
        if (messagesHtml === "" && response.overall_status && response.overall_status !== 'success'){
             messagesHtml = `<div class="alert alert-info p-2">${escapeHtml(response.error_messages && response.error_messages.length > 0 ? response.error_messages[0] : 'Nessuna operazione specifica eseguita o risultato sconosciuto.')}</div>`;
        }
        responseMessagesContainer.innerHTML = messagesHtml;

        if (response.overall_status === 'success' || response.overall_status === 'partial_success' && !hasErrors) {
            uploadStatusText.textContent = 'Caricamento completato con successo!';
            // Potresti voler nascondere la progress bar qui o dopo un timeout
            setTimeout(() => { progressContainer.style.display = 'none'; }, 3000);
        } else {
            uploadStatusText.textContent = 'Caricamento completato con errori.';
            // Non nascondere la progress bar se ci sono errori, l'utente potrebbe voler vedere lo stato
        }
    }
    function displayResponseMessage(message, type = 'info') { responseMessagesContainer.innerHTML = `<div class="alert alert-${escapeHtml(type)} p-2">${escapeHtml(message)}</div>`; }
    function escapeHtml(unsafe) { if (typeof unsafe !== 'string') return String(unsafe); return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");}
    function formatBytesJS(bytes, decimals = 2) { if (bytes === 0) return '0 Bytes'; const k = 1024; const dm = decimals < 0 ? 0 : decimals; const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']; const i = Math.floor(Math.log(bytes) / Math.log(k)); return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]; }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>