<?php
// install/step4_options.php - Installer - Step 4: Optional Settings

if (!defined('INSTALLER_PROJECT_ROOT')) { define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); }
require_once __DIR__ . '/installer_functions.php'; 

installer_session_start(); 

// Protezione: assicurati che l'utente provenga dal Passo 3 completato
if (!isset($_SESSION['installer_step']) || $_SESSION['installer_step'] < 4) {
    if (!($_SESSION['installer_step'] == 4 && isset($_SESSION['installer_config']['site']['site_name']))) { // Controlla un dato chiave del passo 3
        installer_log_warning("Accesso non valido allo Step 4. Dati sito mancanti o step errato. Riporto al Passo 3.");
        $_SESSION['installer_error_message'] = "Per favore, completa prima le impostazioni del sito e dell'admin.";
        $_SESSION['installer_step'] = 3; 
        header('Location: step3_site_admin.php' . (($_SESSION['installer_reinstall_mode'] ?? false) ? '?reinstall=true' : ''));
        exit;
    }
}
$_SESSION['installer_step'] = 4; 
$current_step = 4;

$error_message_flash = $_SESSION['installer_error_message'] ?? null;
unset($_SESSION['installer_error_message']);
$success_message_flash = $_SESSION['installer_success_message'] ?? null;
unset($_SESSION['installer_success_message']);

$is_reinstalling = $_SESSION['installer_reinstall_mode'] ?? false;
$reinstall_param_html = $is_reinstalling ? "&reinstall=true" : "";
$reinstall_param_php_query = $is_reinstalling ? "?reinstall=true" : "";
$reinstall_hidden_field = $is_reinstalling ? '<input type="hidden" name="reinstall_flag" value="true">' : '';

// Valori per precompilare il form
$site_config_session = $_SESSION['installer_config']['site'] ?? [];
$recaptcha_site_key_form = $_POST['recaptcha_site_key'] ?? ($site_config_session['recaptcha_site_key'] ?? '');
$recaptcha_secret_key_form = $_POST['recaptcha_secret_key'] ?? ($site_config_session['recaptcha_secret_key'] ?? '');
$site_logo_max_height_form = $_POST['site_logo_max_height'] ?? ($site_config_session['site_logo_max_height'] ?? '50');
$site_logo_max_width_form = $_POST['site_logo_max_width'] ?? ($site_config_session['site_logo_max_width'] ?? '0');
$site_logo_alignment_form = $_POST['site_logo_alignment'] ?? ($site_config_session['site_logo_alignment'] ?? 'center');
$current_logo_in_session_path = $site_config_session['logo_url'] ?? null; // Path relativo come verrà salvato in config.php


// --- LOGICA POST PER IL PASSO 4 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_optional_settings'])) {
    installer_log_activity("Ricevuto POST per Step 4: Impostazioni Opzionali.");
    
    $_SESSION['installer_config']['site']['recaptcha_site_key'] = trim($_POST['recaptcha_site_key'] ?? '');
    $_SESSION['installer_config']['site']['recaptcha_secret_key'] = trim($_POST['recaptcha_secret_key'] ?? '');
    
    $logo_max_h = isset($_POST['site_logo_max_height']) ? (int)$_POST['site_logo_max_height'] : 50;
    if ($logo_max_h < 20 || $logo_max_h > 300) $logo_max_h = 50;
    $_SESSION['installer_config']['site']['site_logo_max_height'] = (string)$logo_max_h;

    $logo_max_w = isset($_POST['site_logo_max_width']) ? (int)$_POST['site_logo_max_width'] : 0;
    if ($logo_max_w < 0 || $logo_max_w > 500) $logo_max_w = 0;
    $_SESSION['installer_config']['site']['site_logo_max_width'] = (string)$logo_max_w;
    
    $logo_align = $_POST['site_logo_alignment'] ?? 'center';
    if (!in_array($logo_align, ['left', 'center', 'right'])) $logo_align = 'center';
    $_SESSION['installer_config']['site']['site_logo_alignment'] = $logo_align;

    // Gestione Upload Logo
    if (isset($_FILES['site_logo_upload']) && $_FILES['site_logo_upload']['error'] == UPLOAD_ERR_OK) {
        $logo_upload_dir_abs = INSTALLER_SYSTEMIMAGES_PATH; // Path assoluto sul server
        if (!is_dir($logo_upload_dir_abs)) { 
            if (!@mkdir($logo_upload_dir_abs, 0755, true)) {
                $_SESSION['installer_error_message'] = "Errore critico: impossibile creare la cartella SystemImages in " . htmlspecialchars($logo_upload_dir_abs);
                installer_log_error("Creazione SystemImages fallita: " . $logo_upload_dir_abs);
                header("Location: step4_options.php" . $reinstall_param_php_query); exit;
            }
        }
        
        if (is_writable($logo_upload_dir_abs)) {
            $allowed_logo_types = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml']; 
            $max_logo_size = 2 * 1024 * 1024; // 2MB
            $logo_file = $_FILES['site_logo_upload'];
            $logo_file_type = function_exists('mime_content_type') ? mime_content_type($logo_file['tmp_name']) : $logo_file['type'];

            if ($logo_file['size'] > $max_logo_size) {
                $_SESSION['installer_error_message'] = 'Logo troppo grande (max 2MB). Il logo precedente (se esistente) è stato mantenuto.';
            } elseif (!in_array($logo_file_type, $allowed_logo_types)) {
                $_SESSION['installer_error_message'] = 'Tipo file logo non consentito (JPG, PNG, GIF, SVG). Trovato: '.htmlspecialchars($logo_file_type).'. Il logo precedente (se esistente) è stato mantenuto.';
            } else {
                $extension = strtolower(pathinfo($logo_file['name'], PATHINFO_EXTENSION));
                $new_logo_filename = 'site_logo.' . $extension; 
                $logo_destination_abs = $logo_upload_dir_abs . $new_logo_filename;
                
                $existing_logos = glob($logo_upload_dir_abs . "site_logo.*");
                foreach ($existing_logos as $existing_logo) { if (is_file($existing_logo)) @unlink($existing_logo); }

                if (move_uploaded_file($logo_file['tmp_name'], $logo_destination_abs)) {
                    $_SESSION['installer_config']['site']['logo_url'] = 'SystemImages/' . $new_logo_filename; // Path relativo per config.php
                    $_SESSION['installer_success_message'] = 'Nuovo logo caricato con successo.';
                    installer_log_activity("Nuovo logo caricato: SystemImages/{$new_logo_filename}");
                    $current_logo_in_session_path = $_SESSION['installer_config']['site']['logo_url']; // Aggiorna per visualizzazione
                } else { 
                    $_SESSION['installer_error_message'] = 'Errore durante il caricamento del nuovo logo sul server. Permessi corretti?'; 
                    installer_log_error("Errore move_uploaded_file per logo: {$logo_destination_abs}");
                }
            }
        } else { 
            $_SESSION['installer_error_message'] = 'La cartella SystemImages/ non è scrivibile. Upload logo fallito.'; 
            installer_log_error("Cartella SystemImages non scrivibile: {$logo_upload_dir_abs}");
        }
    } elseif (isset($_FILES['site_logo_upload']) && $_FILES['site_logo_upload']['error'] != UPLOAD_ERR_NO_FILE && $_FILES['site_logo_upload']['error'] != UPLOAD_ERR_OK) {
        $_SESSION['installer_error_message'] = 'Errore durante l\'upload del logo (Codice PHP: '.$_FILES['site_logo_upload']['error'].'). Verifica i limiti di upload del server.';
        installer_log_error('Errore upload logo PHP: '.$_FILES['site_logo_upload']['error']);
    }
    // Se non c'è un errore di upload, ma c'era un successo precedente, non sovrascrivere.
    // Se c'è un errore di upload, il messaggio di errore sovrascriverà quello di successo.

    $_SESSION['installer_step'] = 5;
    installer_log_activity("Passo 4 completato. Impostazioni opzionali e logo (se caricato) salvati in sessione.");
    header("Location: step5_finalize.php" . $reinstall_param_php_query);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione File Manager - Passo 4: Opzioni</title>
    <link rel="stylesheet" href="install_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="installer-container">
        <h1>Installazione File Manager <small style="font-size:0.5em; color:#777;">Passo 4 di 5</small></h1>
        <hr>

        <?php if ($error_message_flash): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error_message_flash)); ?></div>
        <?php endif; ?>
        <?php if ($success_message_flash): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message_flash); ?></div>
        <?php endif; ?>

        <h2>Impostazioni Opzionali</h2>
        <p>Configura funzionalità aggiuntive come reCAPTCHA e il logo del sito. Puoi anche saltare questo passo se non ti servono ora o configurarle più tardi dal pannello admin del sito già installato.</p>
        
        <form action="step4_options.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?php echo $reinstall_hidden_field; ?>
            <fieldset class="mb-3">
                <legend>Google reCAPTCHA v2 (Opzionale)</legend>
                <small class="form-text text-muted d-block mb-2">Se vuoi usare reCAPTCHA nei form di login/registrazione, inserisci le tue chiavi "Site Key" e "Secret Key" da <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">Google reCAPTCHA</a>. Altrimenti, lascia i campi vuoti per disabilitarlo.</small>
                <div class="form-group">
                    <label for="recaptcha_site_key">Site Key reCAPTCHA:</label>
                    <input type="text" id="recaptcha_site_key" name="recaptcha_site_key" class="form-control" value="<?php echo htmlspecialchars($recaptcha_site_key_form); ?>">
                </div>
                <div class="form-group">
                    <label for="recaptcha_secret_key">Secret Key reCAPTCHA:</label>
                    <input type="text" id="recaptcha_secret_key" name="recaptcha_secret_key" class="form-control" value="<?php echo htmlspecialchars($recaptcha_secret_key_form); ?>">
                </div>
            </fieldset>

            <hr>
            <fieldset class="mb-3">
                <legend>Logo del Sito (Opzionale)</legend>
                 <?php if ($current_logo_in_session_path): ?>
                    <div class="mb-2">
                        <p class="mb-1">Logo attualmente configurato per l'installazione:</p>
                        <p><code><?php echo htmlspecialchars($current_logo_in_session_path); ?></code></p>
                        <small>(L'anteprima effettiva sarà visibile dopo l'installazione, se il percorso è corretto e il file è accessibile via web)</small>
                    </div>
                 <?php endif; ?>
                <div class="form-group">
                    <label for="site_logo_upload">Carica/Sostituisci Logo:</label>
                    <input type="file" class="form-control-file" id="site_logo_upload" name="site_logo_upload" accept="image/jpeg,image/png,image/gif,image/svg+xml">
                    <small class="form-text text-muted">Consigliato PNG trasparente o SVG. Max 2MB.</small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="site_logo_max_height">Altezza Max Logo (px):</label>
                        <input type="number" class="form-control form-control-sm" id="site_logo_max_height" name="site_logo_max_height" value="<?php echo htmlspecialchars($site_logo_max_height_form); ?>" min="20" max="300">
                    </div>
                    <div class="form-group col-md-4">
                        <label for="site_logo_max_width">Larghezza Max Logo (px):</label>
                        <input type="number" class="form-control form-control-sm" id="site_logo_max_width" name="site_logo_max_width" value="<?php echo htmlspecialchars($site_logo_max_width_form); ?>" min="0" max="500">
                        <small class="form-text text-muted">0 per auto.</small>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="site_logo_alignment">Allineamento Logo (sopra navbar):</label>
                        <select class="form-control form-control-sm" id="site_logo_alignment" name="site_logo_alignment">
                            <option value="left" <?php if ($site_logo_alignment_form === 'left') echo 'selected'; ?>>Sinistra</option>
                            <option value="center" <?php if ($site_logo_alignment_form === 'center') echo 'selected'; ?>>Centro</option>
                            <option value="right" <?php if ($site_logo_alignment_form === 'right') echo 'selected'; ?>>Destra</option>
                        </select>
                    </div>
                </div>
            </fieldset>
            <div class="nav-buttons">
                <a href="step3_site_admin.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" class="btn btn-nav"><i class="fas fa-arrow-left"></i> Torna Impostazioni Sito/Admin</a>
                <button type="submit" name="submit_optional_settings" class="btn">Salva Opzionali e Vai alla Finalizzazione <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>
    </div>
</body>
</html>