<?php
// install/step3_site_admin.php - Installer - Step 3: Site & Admin Configuration

if (!defined('INSTALLER_PROJECT_ROOT')) { define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); }
require_once __DIR__ . '/installer_functions.php'; 

installer_session_start(); 

// Protezione: assicurati che l'utente provenga dal Passo 2 completato
if (!isset($_SESSION['installer_step']) || $_SESSION['installer_step'] < 3) {
    // Permetti l'accesso diretto se lo step è già 3 (es. ricaricamento pagina dopo errore)
    // o se i dati del DB sono presenti in sessione
    if (!($_SESSION['installer_step'] == 3 && isset($_SESSION['installer_config']['db']['host']))) {
        installer_log_warning("Accesso non valido allo Step 3. Dati DB mancanti o step errato. Riporto al Passo 2.");
        $_SESSION['installer_error_message'] = "Per favore, completa prima la configurazione del database.";
        $_SESSION['installer_step'] = 2; // Forza il ritorno al passo DB
        header('Location: step2_db_config.php' . (($_SESSION['installer_reinstall_mode'] ?? false) ? '?reinstall=true' : ''));
        exit;
    }
}
$_SESSION['installer_step'] = 3; // Conferma che siamo allo step 3
$current_step = 3;

$error_message_flash = $_SESSION['installer_error_message'] ?? null;
unset($_SESSION['installer_error_message']);
$success_message_flash = $_SESSION['installer_success_message'] ?? null;
unset($_SESSION['installer_success_message']);

$is_reinstalling = $_SESSION['installer_reinstall_mode'] ?? false;
$reinstall_param_html = $is_reinstalling ? "&reinstall=true" : "";
$reinstall_param_php_query = $is_reinstalling ? "?reinstall=true" : "";
$reinstall_hidden_field = $is_reinstalling ? '<input type="hidden" name="reinstall_flag" value="true">' : '';

// Valori di default o da sessione/POST per il form
$site_config_session = $_SESSION['installer_config']['site'] ?? [];
$admin_config_session = $_SESSION['installer_config']['admin'] ?? [];

// Suggerimento per SITE_URL
$protocol_sugg = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host_sugg = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Path fino alla cartella genitore di 'install/'
$path_to_parent_of_install = dirname(dirname($_SERVER['SCRIPT_NAME'])); // Es. /fm se script è /fm/install/step3_site_admin.php
$project_base_path_suggestion = rtrim($path_to_parent_of_install, '/'); 
$site_url_suggestion = rtrim($protocol_sugg . "://" . $host_sugg . $project_base_path_suggestion, '/');


$site_name_form = $_POST['site_name'] ?? ($site_config_session['site_name'] ?? 'File Manager FM');
$site_url_form = $_POST['site_url'] ?? ($site_config_session['site_url'] ?? $site_url_suggestion);
$nas_root_path_form = $_POST['nas_root_path'] ?? ($site_config_session['nas_root_path'] ?? INSTALLER_PROJECT_ROOT . '/user_files_storage/'); // Suggerisce una cartella locale come fallback
$system_email_from_form = $_POST['system_email_from'] ?? ($site_config_session['system_email_from'] ?? 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'example.com'));
$admin_username_form = $_POST['admin_username'] ?? ($admin_config_session['username'] ?? 'admin');
$admin_email_form = $_POST['admin_email'] ?? ($admin_config_session['email'] ?? '');
// Non precompilare le password
$admin_password_form = '';
$admin_password_confirm_form = '';


// --- LOGICA POST PER IL PASSO 3 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_site_admin_config'])) {
    installer_log_activity("Ricevuto POST per Step 3: Impostazioni Sito e Admin.");
    
    $site_name_input = trim($_POST['site_name'] ?? '');
    $site_url_input = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $nas_root_path_input = rtrim(trim($_POST['nas_root_path'] ?? ''), '/') . '/'; // Assicura trailing slash
    $system_email_from_input = trim($_POST['system_email_from'] ?? '');
    $admin_username_input = trim($_POST['admin_username'] ?? '');
    $admin_email_input = trim($_POST['admin_email'] ?? '');
    $admin_password_input = $_POST['admin_password'] ?? '';
    $admin_password_confirm_input = $_POST['admin_password_confirm'] ?? '';

    $errors_step3 = [];
    if (empty($site_name_input)) $errors_step3[] = "Il Nome del Sito è richiesto.";
    if (empty($site_url_input) || !filter_var($site_url_input, FILTER_VALIDATE_URL)) $errors_step3[] = "L'URL del Sito non è valido o è vuoto.";
    if (empty($nas_root_path_input)) $errors_step3[] = "Il Percorso Storage File (NAS_ROOT_PATH) è richiesto.";
    if (empty($system_email_from_input) || !filter_var($system_email_from_input, FILTER_VALIDATE_EMAIL)) $errors_step3[] = "L'Email Mittente del Sistema non è valida o è vuota.";
    if (empty($admin_username_input) || strlen($admin_username_input) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $admin_username_input)) $errors_step3[] = "Username Amministratore non valido (min 3 caratteri, solo alfanumerici e underscore).";
    if (empty($admin_email_input) || !filter_var($admin_email_input, FILTER_VALIDATE_EMAIL)) $errors_step3[] = "Email Amministratore non valida o è vuota.";
    if (empty($admin_password_input) || strlen($admin_password_input) < 8) $errors_step3[] = "La Password Amministratore deve essere di almeno 8 caratteri.";
    if ($admin_password_input !== $admin_password_confirm_input) $errors_step3[] = "Le password dell'amministratore non coincidono.";

    if (empty($errors_step3)) {
        $_SESSION['installer_config']['site']['site_name'] = $site_name_input;
        $_SESSION['installer_config']['site']['site_url'] = $site_url_input;
        $_SESSION['installer_config']['site']['nas_root_path'] = $nas_root_path_input;
        $_SESSION['installer_config']['site']['system_email_from'] = $system_email_from_input;
        
        // Inizializza altri valori site se non già presenti in sessione (per passi successivi)
        $_SESSION['installer_config']['site']['footer_text'] = $site_config_session['footer_text'] ?? ('© ' . date('Y') . ' ' . htmlspecialchars($site_name_input) . ". Tutti i diritti riservati.");
        $_SESSION['installer_config']['site']['aging_enabled'] = $site_config_session['aging_enabled'] ?? '0';
        $_SESSION['installer_config']['site']['aging_delete_grace_period_days'] = $site_config_session['aging_delete_grace_period_days'] ?? '7';
        $_SESSION['installer_config']['site']['recaptcha_site_key'] = $site_config_session['recaptcha_site_key'] ?? '';
        $_SESSION['installer_config']['site']['recaptcha_secret_key'] = $site_config_session['recaptcha_secret_key'] ?? '';
        $_SESSION['installer_config']['site']['logo_url'] = $site_config_session['logo_url'] ?? '';
        $_SESSION['installer_config']['site']['site_logo_max_height'] = $site_config_session['site_logo_max_height'] ?? '50';
        $_SESSION['installer_config']['site']['site_logo_max_width'] = $site_config_session['site_logo_max_width'] ?? '0';
        $_SESSION['installer_config']['site']['site_logo_alignment'] = $site_config_session['site_logo_alignment'] ?? 'center';
        
        $_SESSION['installer_config']['admin'] = [
            'username' => $admin_username_input,
            'email' => $admin_email_input,
            'password_temp' => $admin_password_input 
        ];
        $_SESSION['installer_step'] = 4;
        installer_log_activity("Passo 3 completato. Dati sito e admin salvati in sessione.");
        header("Location: step4_options.php" . $reinstall_param_php_query);
        exit;
    } else {
        $_SESSION['installer_error_message'] = implode("<br>", $errors_step3);
        // Salva i valori inviati (tranne password) per ripopolare il form
        $_SESSION['installer_config']['site']['site_name'] = $site_name_input;
        $_SESSION['installer_config']['site']['site_url'] = $site_url_input;
        $_SESSION['installer_config']['site']['nas_root_path'] = $nas_root_path_input;
        $_SESSION['installer_config']['site']['system_email_from'] = $system_email_from_input;
        $_SESSION['installer_config']['admin']['username'] = $admin_username_input;
        $_SESSION['installer_config']['admin']['email'] = $admin_email_input;
        header("Location: step3_site_admin.php" . $reinstall_param_php_query);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione File Manager - Passo 3: Sito & Admin</title>
    <link rel="stylesheet" href="install_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="installer-container">
        <h1>Installazione File Manager <small style="font-size:0.5em; color:#777;">Passo 3 di 5</small></h1>
        <hr>

        <?php if ($error_message_flash): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error_message_flash)); ?></div>
        <?php endif; ?>
        <?php if ($success_message_flash): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message_flash); ?></div>
        <?php endif; ?>

        <h2>Impostazioni Sito e Account Amministratore</h2>
        <p>Configura le impostazioni di base del sito e crea l'account amministratore principale.</p>
        
        <form action="step3_site_admin.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" method="POST" novalidate>
            <?php echo $reinstall_hidden_field; ?>
            <fieldset class="mb-3">
                <legend>Impostazioni Generali del Sito</legend>
                <div class="form-group">
                    <label for="site_name">Nome del Sito:</label>
                    <input type="text" id="site_name" name="site_name" class="form-control" value="<?php echo htmlspecialchars($site_name_form); ?>" required>
                </div>
                <div class="form-group">
                    <label for="site_url">URL Completo del Sito (con http/https e eventuale sottocartella):</label>
                    <input type="url" id="site_url" name="site_url" class="form-control" value="<?php echo htmlspecialchars($site_url_form); ?>" placeholder="Es. http://tuodominio.com/fm" required>
                    <small class="form-text text-muted">Assicurati che termini senza slash finale (/). Esempio suggerito: <code><?php echo htmlspecialchars($site_url_suggestion); ?></code></small>
                </div>
                <div class="form-group">
                    <label for="system_email_from">Email Mittente per Notifiche di Sistema:</label>
                    <input type="email" id="system_email_from" name="system_email_from" class="form-control" value="<?php echo htmlspecialchars($system_email_from_form); ?>" required>
                    <small class="form-text text-muted">Es. noreply@tuodominio.com. Deve essere un indirizzo email valido.</small>
                </div>
                <div class="form-group">
                    <label for="nas_root_path">Percorso Assoluto Storage File (<code>NAS_ROOT_PATH</code>):</label>
                    <input type="text" id="nas_root_path" name="nas_root_path" class="form-control" value="<?php echo htmlspecialchars($nas_root_path_form); ?>" required>
                    <small class="form-text text-muted">Es. <code>/mnt/nas_share/</code> o <code>C:/server/files_storage/</code>. Deve terminare con uno slash (<code>/</code>) e il server web deve avere i permessi di scrittura su questa destinazione.</small>
                </div>
            </fieldset>
            
            <fieldset class="mb-3">
                <legend>Account Amministratore Principale</legend>
                <div class="form-group">
                    <label for="admin_username">Username Amministratore:</label>
                    <input type="text" id="admin_username" name="admin_username" class="form-control" value="<?php echo htmlspecialchars($admin_username_form); ?>" required minlength="3" pattern="^[a-zA-Z0-9_]+$">
                    <small class="form-text text-muted">Min. 3 caratteri, solo lettere (a-z, A-Z), numeri (0-9) e underscore (_).</small>
                </div>
                <div class="form-group">
                    <label for="admin_email">Email Amministratore:</label>
                    <input type="email" id="admin_email" name="admin_email" class="form-control" value="<?php echo htmlspecialchars($admin_email_form); ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="admin_password">Password Temporanea Admin:</label>
                        <input type="password" id="admin_password" name="admin_password" class="form-control" required minlength="8">
                        <small class="form-text text-muted">Min. 8 caratteri. Dovrà essere cambiata al primo login.</small>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="admin_password_confirm">Conferma Password Temporanea:</label>
                        <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="form-control" required minlength="8">
                    </div>
                </div>
            </fieldset>

            <div class="nav-buttons">
                <a href="step2_db_config.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" class="btn btn-nav"><i class="fas fa-arrow-left"></i> Torna Config. Database</a>
                <button type="submit" name="submit_site_admin_config" class="btn">Salva e Vai a Impostazioni Opzionali <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>
    </div>
</body>
</html>