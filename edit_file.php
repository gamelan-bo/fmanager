<?php
// /var/www/html/fm/edit_file.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; // Per generate_url(), SITE_URL, ecc.
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_file.php'; 

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_login(); 

$user_id = $_SESSION['user_id']; 
$is_current_user_admin = is_admin();

// Recupera file_id e l'URL di ritorno
$file_id = null;
if (isset($_POST['file_id_hidden']) && is_numeric($_POST['file_id_hidden'])) {
    $file_id = (int)$_POST['file_id_hidden'];
} elseif (isset($_GET['file_id']) && is_numeric($_GET['file_id'])) {
    $file_id = (int)$_GET['file_id'];
}

// Gestisce l'URL di ritorno, default a my_files root se non specificato
$return_to_url_raw = $_REQUEST['return_to'] ?? ''; // Prende da GET o POST per flessibilità
$default_return_url = function_exists('generate_url') ? generate_url('my_files_root') : 'my_files.php';
$return_to_url = $default_return_url;

if (!empty($return_to_url_raw)) {
    // Validazione base per l'URL di ritorno: deve essere interno al sito.
    if (defined('SITE_URL') && strpos($return_to_url_raw, SITE_URL) === 0) {
        // Potremmo aggiungere ulteriori validazioni qui per assicurarci che sia una route nota
        $return_to_url = $return_to_url_raw;
    } else {
        // Se non è un URL interno valido, logga e usa il default
        if(function_exists('log_warning')) log_warning("Tentativo di redirect non valido (return_to) in edit_file.php: " . htmlspecialchars($return_to_url_raw));
        // $return_to_url rimane $default_return_url
    }
}


// BLOCCO 2: Gestione Richiesta POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_file_id_for_action = (int)($_POST['file_id_hidden'] ?? 0); // Usa il file_id dal form per l'azione

    if ($current_file_id_for_action <= 0) { 
        $_SESSION['flash_message'] = "ID file non valido per l'azione di modifica.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $return_to_url); // Usa il return_to_url o il default
        exit;
    }

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        // Recupera i dettagli del file PRIMA di tentare l'aggiornamento per avere folder_id (anche se non lo usiamo più per il redirect principale)
        $file_details_check = get_file_for_editing($current_file_id_for_action, $user_id);
        if (!$file_details_check || (isset($file_details_check['is_deleted']) && $file_details_check['is_deleted'])) {
            $_SESSION['flash_message'] = "File non trovato, eliminato o non hai permessi per modificarlo.";
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $return_to_url); exit;
        }

        $new_original_filename = trim($_POST['original_filename'] ?? '');
        $new_description = trim($_POST['description'] ?? null);
        $new_expiry_date_to_pass = $file_details_check['expiry_date']; 

        if ($is_current_user_admin) { 
            if (isset($_POST['never_expires']) && $_POST['never_expires'] === '1') {
                $new_expiry_date_to_pass = null; 
            } else {
                $new_expiry_date_input = trim($_POST['expiry_date'] ?? '');
                if ($new_expiry_date_input === '' && !(isset($_POST['never_expires']) && $_POST['never_expires'] === '1') ) { 
                    $new_expiry_date_to_pass = null;
                } elseif (!empty($new_expiry_date_input)) {
                    $new_expiry_date_to_pass = $new_expiry_date_input;
                }
            }
        }
        
        $result = update_file_metadata($current_file_id_for_action, $new_original_filename, $new_description, $new_expiry_date_to_pass, $user_id);
        
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';

        if ($result['success']) {
            header('Location: ' . $return_to_url); // Torna all'URL specificato
            exit;
        }
        // Se fallisce, il redirect sotto riporterà alla pagina di modifica con il messaggio flash
        // e mantenendo i parametri originali (file_id e return_to)
    }
    // Redirect alla pagina di modifica per mostrare il flash message in caso di errore CSRF o altri fallimenti
    $edit_url_params = ['id' => $current_file_id_for_action];
    if (!empty($return_to_url_raw)) $edit_url_params['return_to'] = $return_to_url_raw; // Ripassa il return_to originale
    header("Location: " . generate_url('file_edit', $edit_url_params)); 
    exit;
}

// BLOCCO 3: Recupero Dati per Visualizzazione Pagina (richiesta GET)
if (!$file_id) { 
    $_SESSION['flash_message'] = "ID file non specificato per la modifica.";
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $default_return_url); 
    exit;
}

$file_details = get_file_for_editing($file_id, $user_id);
if (!$file_details) {
    $_SESSION['flash_message'] = "File non trovato o non hai i permessi per modificarlo.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: ' . $return_to_url); // Usa il return_to anche per questo redirect
    exit;
}
if (isset($file_details['is_deleted']) && $file_details['is_deleted']) {
    $_SESSION['flash_message'] = "Non è possibile modificare un file che è stato eliminato.";
    $_SESSION['flash_type'] = 'warning';
    header('Location: ' . $return_to_url); 
    exit;
}

$form_original_filename = htmlspecialchars($file_details['original_filename'] ?? '');
$form_description = htmlspecialchars($file_details['description'] ?? '');
$form_expiry_date = $file_details['expiry_date'] ? date('Y-m-d', strtotime($file_details['expiry_date'])) : '';
$form_never_expires = ($file_details['expiry_date'] === null);


// BLOCCO 4: Inizio output HTML
$page_title = "Modifica Dettagli File: " . $form_original_filename;
require_once __DIR__ . '/includes/header.php'; 
?>

<h2>Modifica Dettagli File: <em><?php echo $form_original_filename; ?></em></h2>
<?php // I messaggi flash sono gestiti da header.php ?>

<?php
// Prepara i parametri per l'action del form, includendo l'ID del file e il return_to URL
$form_action_params = ['id' => $file_id];
if (!empty($return_to_url_raw)) { // Usa il return_to originale non ancora validato per passarlo com'era
    $form_action_params['return_to'] = $return_to_url_raw;
}
?>
<form action="<?php echo generate_url('file_edit', $form_action_params); ?>" method="POST" novalidate>
    <?php echo csrf_input_field(); ?>
    <input type="hidden" name="file_id_hidden" value="<?php echo $file_id; ?>">
    <?php // Passa il return_to anche come campo hidden per il POST, sebbene sia già nell'action
    if (!empty($return_to_url_raw)) {
        echo '<input type="hidden" name="return_to" value="' . htmlspecialchars($return_to_url_raw) . '">';
    }
    ?>
    
    <div class="form-group">
        <label for="original_filename">Nome File Visualizzato:</label>
        <input type="text" class="form-control" id="original_filename" name="original_filename" value="<?php echo $form_original_filename; ?>" required maxlength="255">
    </div>
    <div class="form-group">
        <label for="description">Descrizione:</label>
        <textarea class="form-control" id="description" name="description" rows="4" maxlength="500"><?php echo $form_description; ?></textarea>
    </div>

    <?php if ($is_current_user_admin): ?>
    <fieldset class="mt-4 p-3 border rounded">
        <legend class="w-auto px-2 h6"><i class="fas fa-calendar-times"></i> Impostazioni Scadenza File (Admin)</legend>
        <div class="form-group">
            <label for="expiry_date">Data di Scadenza:</label>
            <input type="date" class="form-control" id="expiry_date" name="expiry_date" value="<?php echo $form_expiry_date; ?>" <?php if($form_never_expires) echo 'disabled'; ?>>
            <small class="form-text text-muted">Lascia vuoto (o seleziona "Non scade mai") per rimuovere/non impostare una scadenza.</small>
        </div>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="never_expires" name="never_expires" value="1" <?php if($form_never_expires) echo 'checked'; ?> onchange="document.getElementById('expiry_date').disabled = this.checked; if(this.checked) { document.getElementById('expiry_date').value=''; }">
            <label class="form-check-label" for="never_expires">Questo file non scade mai</label>
        </div>
    </fieldset>
    <?php endif; ?>
    
    <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Salva Modifiche</button>
    <a href="<?php echo htmlspecialchars($return_to_url); // Usa l'URL di ritorno validato per il link Annulla ?>" class="btn btn-secondary mt-3 ml-2">Annulla</a>
</form>

<?php
require_once __DIR__ . '/includes/footer.php';
?>