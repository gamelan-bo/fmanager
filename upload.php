<?php
// /var/www/html/fm/upload.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione, gestione AJAX
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   // Per require_login(), is_admin()
require_once __DIR__ . '/includes/functions_file.php';  // Contiene handle_file_upload
require_once __DIR__ . '/includes/functions_folder.php';// Contiene can_user_access_folder_view, get_folder_details, get_folder_path_breadcrumbs_with_permission_check

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_login(); // Solo utenti loggati possono accedere a questa pagina, sia per GET che per POST AJAX

$user_id = $_SESSION['user_id'];
$is_user_admin_flag_for_upload_check = is_admin();

// Determina la cartella di destinazione (sia per GET per visualizzare, sia per POST per l'upload)
$target_folder_id_param = $_REQUEST['folder_id'] ?? '0'; // Usa $_REQUEST per prendere da GET o POST (POST per il campo hidden nel form)
$target_folder_id = ($target_folder_id_param === '0' || $target_folder_id_param === '') ? null : (int)$target_folder_id_param;


// BLOCCO 2: Gestione della Richiesta AJAX POST per l'upload
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    header('Content-Type: application/json');
    $response = ['success_messages' => [], 'error_messages' => [], 'overall_status' => 'error_initial'];

    try {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            throw new Exception("Errore di sicurezza (CSRF). Ricarica la pagina e riprova.");
        }
        // $user_id è già definito sopra
        // $target_folder_id è già definito sopra, preso da $_POST['folder_id']
        
        // Controllo permesso di upload nella cartella di destinazione
        if ($target_folder_id !== null) { // Se si carica in una sottocartella
            if (!can_user_access_folder_view($user_id, $target_folder_id, $is_user_admin_flag_for_upload_check)) { // View implica Upload come da tua semplificazione
                $folder_details_for_error = get_folder_details($target_folder_id);
                $folder_name_for_error = $folder_details_for_error ? htmlspecialchars($folder_details_for_error['folder_name']) : "ID:".$target_folder_id;
                throw new Exception("Non hai il permesso di caricare file nella cartella '{$folder_name_for_error}'.");
            }
        } elseif (!$is_user_admin_flag_for_upload_check) { // Se si tenta di caricare in radice e non si è admin
             // La logica attuale di `handle_file_upload` salva in `user_files/USER_ID/` se folder_id è null.
             // Questo è considerato "radice personale utente", quindi permettiamo l'upload.
             // Se la "radice" dovesse essere interpretata come una radice globale accessibile da tutti con permessi,
             // allora qui andrebbe un controllo più specifico (es. can_user_upload_to_folder($user_id, null, ...)).
             // Per ora, l'upload in `folder_id = null` è permesso per l'utente loggato.
        }
        
        if (!function_exists('format_file_size')) { // Assicura che esista per messaggi quota
            function format_file_size($bytes, $precision = 2) { if(!is_numeric($bytes)||$bytes<0)return 'N/D';if($bytes==0)return '0 B';$u=['B','KB','MB','GB','TB'];$b=max($bytes,0);$p=floor(($b?log($b):0)/log(1024));$p=min($p,count($u)-1);$b/=pow(1024,$p);return round($b,$precision).' '.$u[$p];}
        }

        if (isset($_FILES['files_to_upload'])) {
            $description = trim($_POST['description'] ?? '');
            $uploaded_files_count = 0;
            if (is_array($_FILES['files_to_upload']['name'])) { $filtered_names = array_filter($_FILES['files_to_upload']['name']); $uploaded_files_count = count($filtered_names); } 
            elseif (!empty($_FILES['files_to_upload']['name']) && $_FILES['files_to_upload']['error'] !== UPLOAD_ERR_NO_FILE) { $uploaded_files_count = 1; }

            $total_upload_size = 0; $files_to_process_ajax = [];
            if ($uploaded_files_count > 0) {
                if (is_array($_FILES['files_to_upload']['name'])) {
                    for ($i = 0; $i < count($_FILES['files_to_upload']['name']); $i++) {
                        if (empty($_FILES['files_to_upload']['name'][$i]) || $_FILES['files_to_upload']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                        if ($_FILES['files_to_upload']['error'][$i] === UPLOAD_ERR_OK && $_FILES['files_to_upload']['size'][$i] > 0) {
                            $files_to_process_ajax[] = ['name' => $_FILES['files_to_upload']['name'][$i], 'type' => $_FILES['files_to_upload']['type'][$i], 'tmp_name' => $_FILES['files_to_upload']['tmp_name'][$i], 'error' => $_FILES['files_to_upload']['error'][$i], 'size' => $_FILES['files_to_upload']['size'][$i]];
                            $total_upload_size += (int)$_FILES['files_to_upload']['size'][$i];
                        } else { $response['error_messages'][] = "File \"".htmlspecialchars($_FILES['files_to_upload']['name'][$i])."\": Errore (cod: " . $_FILES['files_to_upload']['error'][$i] . ")"; }
                    }
                } else { 
                    if ($_FILES['files_to_upload']['error'] === UPLOAD_ERR_OK && $_FILES['files_to_upload']['size'] > 0) {
                        $files_to_process_ajax[] = $_FILES['files_to_upload']; $total_upload_size = (int)$_FILES['files_to_upload']['size'];
                    } elseif ($_FILES['files_to_upload']['error'] !== UPLOAD_ERR_NO_FILE) { $response['error_messages'][] = "File \"".htmlspecialchars($_FILES['files_to_upload']['name'])."\": Errore (cod: " . $_FILES['files_to_upload']['error'] . ")";}
                }
            }
            if (empty($files_to_process_ajax) && empty($response['error_messages'])) $response['error_messages'][] = "Nessun file valido selezionato.";

            if (!empty($files_to_process_ajax)) {
                $conn_ajax = get_db_connection(); $user_stmt_ajax = $conn_ajax->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?"); $can_proceed_with_quota_ajax = false;
                if ($user_stmt_ajax) { $user_stmt_ajax->bind_param("i", $user_id); $user_stmt_ajax->execute(); $user_data_ajax = $user_stmt_ajax->get_result()->fetch_assoc(); $user_stmt_ajax->close(); if ($user_data_ajax) { if (($user_data_ajax['used_space_bytes'] + $total_upload_size) > $user_data_ajax['quota_bytes']) { $response['error_messages'][] = "Spazio insufficiente per file (".format_file_size($total_upload_size).")."; } else { $can_proceed_with_quota_ajax = true; }} else { $response['error_messages'][] = "Errore utente quota."; }}
                else { log_error("AJAX Upload: DB Err (user_stmt): " . ($conn_ajax->error ?? 'N/A')); $response['error_messages'][] = "Errore server quota."; }

                if ($can_proceed_with_quota_ajax) {
                    foreach ($files_to_process_ajax as $current_file_input_ajax) {
                        $result_ajax = handle_file_upload($current_file_input_ajax, $user_id, $description, $target_folder_id); // Passa folder_id
                        if ($result_ajax['success']) { $response['success_messages'][] = $result_ajax['message']; }
                        else { $response['error_messages'][] = "File \"".htmlspecialchars($current_file_input_ajax['name'])."\": " . $result_ajax['message']; }
                    }
                }
            }
        } else { $response['error_messages'][] = "Nessun file inviato."; }

        if (empty($response['error_messages']) && !empty($response['success_messages'])) { $response['overall_status'] = 'success'; }
        elseif (!empty($response['error_messages']) && empty($response['success_messages'])) { $response['overall_status'] = 'error'; }
        elseif (!empty($response['error_messages']) && !empty($response['success_messages'])) { $response['overall_status'] = 'partial_success'; }
        elseif (empty($response['error_messages']) && empty($response['success_messages'])) { $response['overall_status'] = 'no_action'; if(empty($response['error_messages'])) $response['error_messages'][] = "Nessuna operazione eseguita."; }

    } catch (Exception $e) {
        log_error("Eccezione AJAX upload.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        if (!in_array($e->getMessage(), $response['error_messages'])) $response['error_messages'][] = $e->getMessage();
        $response['overall_status'] = 'fatal_error';
    }
    echo json_encode($response);
    exit; // Termina l'esecuzione per le richieste AJAX
}


// BLOCCO 3: Preparazione dati per la visualizzazione della pagina (richiesta GET)
$folder_path_display = "Radice File"; 
if ($target_folder_id !== null) { // $target_folder_id è già stato definito e normalizzato sopra
    if (!can_user_access_folder_view($user_id, $target_folder_id, $is_user_admin_flag_for_upload_check)) {
        $_SESSION['flash_message'] = "Accesso negato alla cartella di destinazione per l'upload.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: my_files.php?folder_id=0'); 
        exit;
    }
    $breadcrumbs_upload = get_folder_path_breadcrumbs_with_permission_check($target_folder_id, $user_id, $is_user_admin_flag_for_upload_check);
    $current_folder_crumb = end($breadcrumbs_upload); 
    if ($current_folder_crumb && $current_folder_crumb['id'] == $target_folder_id) {
        $folder_path_display = htmlspecialchars($current_folder_crumb['name']);
    } elseif ($target_folder_id !== null) { 
        $folder_details_temp = get_folder_details($target_folder_id);
        if ($folder_details_temp && $folder_details_temp['id'] != '0') {
            $folder_path_display = htmlspecialchars($folder_details_temp['folder_name']);
        } else { 
            $folder_path_display = "Radice File (Cartella non valida)";
            $target_folder_id = null; 
        }
    }
}

if (!function_exists('format_file_size')) {
    function format_file_size($bytes, $precision = 2) { /* ... come prima ... */ }}

$user_id_for_quota_display = $_SESSION['user_id'];
$conn_quota_form_display = get_db_connection();
$stmt_quota_form_display = $conn_quota_form_display->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?");
$quota_available_text_display = "Quota non disponibile.";
if ($stmt_quota_form_display) { /* ... logica quota come prima ... */ }

// BLOCCO 4: Inizio Output HTML
$page_title = "Carica File";
require_once __DIR__ . '/includes/header.php'; // Ora header.php è incluso DOPO la logica POST AJAX
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Carica Nuovi File</h2>
    <a href="my_files.php<?php if($target_folder_id !== null) echo '?folder_id='.$target_folder_id; else echo '?folder_id=0'; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Torna a <?php echo htmlspecialchars($folder_path_display); ?></a>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> Stai caricando file in: <strong><?php echo htmlspecialchars($folder_path_display); ?></strong>
</div>

<div id="upload-response-messages" class="mb-3"></div>

<div class="card">
    <div class="card-body">
        <form id="uploadForm" action="upload.php" method="POST" enctype="multipart/form-data" novalidate>
            <?php echo csrf_input_field(); ?>
            <input type="hidden" name="folder_id" value="<?php echo $target_folder_id ?? ''; ?>">
            
            <div class="form-group">
                <label for="files_to_upload">Seleziona uno o più file:</label>
                <input type="file" class="form-control-file" id="files_to_upload" name="files_to_upload[]" required multiple>
                <small class="form-text text-muted">Max singolo file: <?php echo ini_get('upload_max_filesize'); ?>. <?php echo $quota_available_text_display; ?></small>
            </div>
            <div class="form-group">
                <label for="description">Descrizione (opzionale):</label>
                <textarea class="form-control" id="description" name="description" rows="3" maxlength="500" placeholder="Breve descrizione..."></textarea>
            </div>

            <div class="form-group" id="progress-container" style="display: none;">
                <label>Avanzamento:</label>
                <div class="progress"><div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div></div>
                <div id="upload-status-text" class="mt-1 small"></div>
                <div id="bytes-uploaded-text" class="mt-1 small"></div>
            </div>
            
            <button type="submit" class="btn btn-primary" id="uploadButton"><i class="fas fa-cloud-upload-alt"></i> Carica Selezionati</button>
            <a href="my_files.php<?php if($target_folder_id !== null) echo '?folder_id='.$target_folder_id; else echo '?folder_id=0'; ?>" class="btn btn-secondary ml-2">Annulla</a>
        </form>
    </div>
</div>

<script> /* ... JavaScript per AJAX upload IDENTICO a prima ... */ </script>
<?php
// Copio qui lo script JS per intero per sicurezza
// Assicurati che questo script sia IDENTICO a quello che funzionava prima per l'upload AJAX.
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const uploadForm = document.getElementById('uploadForm');
    const uploadButton = document.getElementById('uploadButton');
    const progressBar = document.getElementById('progressBar');
    const progressContainer = document.getElementById('progress-container');
    const uploadStatusText = document.getElementById('upload-status-text');
    const bytesUploadedText = document.getElementById('bytes-uploaded-text');
    const responseMessagesContainer = document.getElementById('upload-response-messages');
    const fileInput = document.getElementById('files_to_upload');

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault(); 
            responseMessagesContainer.innerHTML = ''; 
            const files = fileInput.files;
            if (files.length === 0) { displayResponseMessage('Nessun file selezionato.', 'warning'); return; }
            const formData = new FormData(uploadForm); 
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'upload.php', true); 
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); 
            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable) {
                    const percentComplete = Math.round((event.loaded / event.total) * 100);
                    progressBar.style.width = percentComplete + '%'; progressBar.setAttribute('aria-valuenow', percentComplete); progressBar.textContent = percentComplete + '%';
                    bytesUploadedText.textContent = `Caricati ${formatBytesJS(event.loaded)} di ${formatBytesJS(event.total)}`;
                }
            };
            xhr.onloadstart = function() {
                progressContainer.style.display = 'block'; progressBar.style.width = '0%'; progressBar.textContent = '0%';
                uploadButton.disabled = true; uploadStatusText.textContent = 'Caricamento in corso...'; bytesUploadedText.textContent = '';
            };
            xhr.onload = function () {
                uploadButton.disabled = false; progressContainer.style.display = 'none'; uploadStatusText.textContent = 'Completato.';
                if (xhr.status === 200) {
                    try { const response = JSON.parse(xhr.responseText); handleUploadResponse(response); }
                    catch (e) { displayResponseMessage('Errore risposta server: ' + escapeHtml(e.message) + '. Dettagli console.', 'danger'); console.error("Errore parsing JSON:", xhr.responseText); }
                } else { displayResponseMessage('Errore server: ' + xhr.status + ' ' + escapeHtml(xhr.statusText), 'danger'); console.error("Errore server:", xhr.responseText); }
                fileInput.value = ''; 
            };
            xhr.onerror = function () { uploadButton.disabled = false; progressContainer.style.display = 'none'; displayResponseMessage('Errore di rete.', 'danger'); uploadStatusText.textContent = 'Errore.'; };
            xhr.onabort = function () { uploadButton.disabled = false; progressContainer.style.display = 'none'; displayResponseMessage('Upload annullato.', 'warning'); uploadStatusText.textContent = 'Annullato.'; };
            xhr.send(formData);
        });
    }
    function handleUploadResponse(response) {
        let messagesHtml = "";
        if (response.success_messages && response.success_messages.length > 0) {
            messagesHtml += "<div class='alert alert-success p-2'><strong>File caricati:</strong><ul class='mb-0 pl-3'>";
            response.success_messages.forEach(msg => { messagesHtml += `<li>${escapeHtml(msg)}</li>`; });
            messagesHtml += "</ul></div>";
        }
        if (response.error_messages && response.error_messages.length > 0) {
            messagesHtml += "<div class='alert alert-danger p-2'><strong>Errori:</strong><ul class='mb-0 pl-3'>";
            response.error_messages.forEach(msg => { messagesHtml += `<li>${escapeHtml(msg)}</li>`; });
            messagesHtml += "</ul></div>";
        }
        if (messagesHtml === "" && response.overall_status && response.overall_status !== 'success'){
             messagesHtml = `<div class="alert alert-info p-2">${escapeHtml(response.error_messages && response.error_messages.length > 0 ? response.error_messages[0] : 'Nessuna operazione o risultato sconosciuto.')}</div>`;
        }
        responseMessagesContainer.innerHTML = messagesHtml;
    }
    function displayResponseMessage(message, type = 'info') { responseMessagesContainer.innerHTML = `<div class="alert alert-${escapeHtml(type)} p-2">${escapeHtml(message)}</div>`; }
    function escapeHtml(unsafe) { if (typeof unsafe !== 'string') return String(unsafe); return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");}
    function formatBytesJS(bytes, decimals = 2) { if (bytes === 0) return '0 Bytes'; const k = 1024; const dm = decimals < 0 ? 0 : decimals; const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']; const i = Math.floor(Math.log(bytes) / Math.log(k)); return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]; }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>