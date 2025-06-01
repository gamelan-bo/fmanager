<?php
// /var/www/html/fm/edit_file.php

// --- BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione ---
require_once __DIR__ . '/config.php'; // Per SESSION_NAME, costanti DB, funzioni di log
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   // Per require_login(), is_admin()
require_once __DIR__ . '/includes/functions_file.php'; // Per get_file_for_editing(), update_file_metadata()

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_login(); // Proteggi pagina

$user_id = $_SESSION['user_id']; // Utente che compie l'azione
$is_current_user_admin = is_admin();

// Recupera file_id da GET per la visualizzazione iniziale o da POST per l'azione
$file_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_id_hidden'])) {
    $file_id = is_numeric($_POST['file_id_hidden']) ? (int)$_POST['file_id_hidden'] : null;
} elseif (isset($_GET['file_id']) && is_numeric($_GET['file_id'])) {
    $file_id = (int)$_GET['file_id'];
}

// --- BLOCCO 2: Gestione Richiesta POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$file_id) { // Se file_id non è stato determinato correttamente dal POST
        $_SESSION['flash_message'] = "ID file non valido per l'azione di modifica.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: my_files.php'); // Redirect generico se file_id è mancante
        exit;
    }

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        // Recupera i dettagli del file PRIMA di tentare l'aggiornamento per avere folder_id per il redirect
        $file_details_for_redirect = get_file_for_editing($file_id, $user_id);
        if (!$file_details_for_redirect || (isset($file_details_for_redirect['is_deleted']) && $file_details_for_redirect['is_deleted'])) {
            $_SESSION['flash_message'] = "File non trovato, eliminato o non hai permessi per modificarlo.";
            $_SESSION['flash_type'] = 'danger';
            header('Location: my_files.php'); exit;
        }

        $new_original_filename = trim($_POST['original_filename'] ?? '');
        $new_description = trim($_POST['description'] ?? null);
        
        $new_expiry_date_to_pass = $file_details_for_redirect['expiry_date']; // Default al valore esistente

        if ($is_current_user_admin) { // Solo admin può modificare la scadenza
            if (isset($_POST['never_expires']) && $_POST['never_expires'] === '1') {
                $new_expiry_date_to_pass = null; 
            } else {
                $new_expiry_date_input = trim($_POST['expiry_date'] ?? '');
                if ($new_expiry_date_input === '' && !(isset($_POST['never_expires']) && $_POST['never_expires'] === '1') ) { 
                    // Se il campo data è vuoto e "non scade mai" NON è checkato, significa rimuovere la scadenza
                    $new_expiry_date_to_pass = null;
                } elseif (!empty($new_expiry_date_input)) {
                    $new_expiry_date_to_pass = $new_expiry_date_input;
                }
                // Se $new_expiry_date_input è vuoto E "non scade mai" è checkato, $new_expiry_date_to_pass è già null (corretto)
            }
        }
        // Se non è admin, $new_expiry_date_to_pass mantiene il valore originale del file (non viene modificato)

        $result = update_file_metadata($file_id, $new_original_filename, $new_description, $new_expiry_date_to_pass, $user_id);
        
        $_SESSION['flash_message'] = $result['message'];
        $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';

        if ($result['success']) {
            $folder_id_for_redirect = $file_details_for_redirect['folder_id'] ?? '0';
            if ($folder_id_for_redirect === null) $folder_id_for_redirect = '0';
            header('Location: my_files.php?folder_id=' . $folder_id_for_redirect); 
            exit;
        }
        // Se fallisce, il redirect sotto riporterà alla pagina di modifica con il messaggio flash
    }
    // Redirect alla pagina di modifica per mostrare il flash message in caso di errore CSRF o altri fallimenti non gestiti da redirect specifico
    header("Location: edit_file.php?file_id=" . $file_id); 
    exit;
}

// --- BLOCCO 3: Recupero Dati per Visualizzazione Pagina (richiesta GET) ---
if (!$file_id) { // Se file_id non è valido o non è stato passato via GET
    $_SESSION['flash_message'] = "ID file non specificato per la modifica.";
    $_SESSION['flash_type'] = 'warning';
    header('Location: my_files.php'); 
    exit;
}

$file_details = get_file_for_editing($file_id, $user_id);
if (!$file_details) {
    $_SESSION['flash_message'] = "File non trovato o non hai i permessi per modificarlo.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: my_files.php'); 
    exit;
}
if (isset($file_details['is_deleted']) && $file_details['is_deleted']) {
    $_SESSION['flash_message'] = "Non è possibile modificare un file che è stato eliminato.";
    $_SESSION['flash_type'] = 'warning';
    $folder_id_for_redirect = $file_details['folder_id'] ?? '0';
    if ($folder_id_for_redirect === null) $folder_id_for_redirect = '0';
    header('Location: my_files.php?folder_id=' . $folder_id_for_redirect); 
    exit;
}

$form_original_filename = htmlspecialchars($file_details['original_filename'] ?? '');
$form_description = htmlspecialchars($file_details['description'] ?? '');
$form_expiry_date = $file_details['expiry_date'] ? date('Y-m-d', strtotime($file_details['expiry_date'])) : '';
$form_never_expires = ($file_details['expiry_date'] === null);


// --- BLOCCO 4: Inizio Output HTML ---
$page_title = "Modifica Dettagli File: " . $form_original_filename;
require_once __DIR__ . '/includes/header.php'; // Ora header.php viene incluso DOPO la logica POST
?>

<h2>Modifica Dettagli File: <em><?php echo $form_original_filename; ?></em></h2>
<?php // I messaggi flash sono gestiti da header.php ?>

<form action="edit_file.php?file_id=<?php echo $file_id; ?>" method="POST" novalidate>
    <?php echo csrf_input_field(); ?>
    <input type="hidden" name="file_id_hidden" value="<?php echo $file_id; ?>">
    
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
    <?php 
        $folder_id_for_cancel_link = $file_details['folder_id'] ?? '0';
        if ($folder_id_for_cancel_link === null) $folder_id_for_cancel_link = '0';
    ?>
    <a href="my_files.php?folder_id=<?php echo $folder_id_for_cancel_link; ?>" class="btn btn-secondary mt-3 ml-2">Annulla</a>
</form>

<?php
require_once __DIR__ . '/includes/footer.php';
?>