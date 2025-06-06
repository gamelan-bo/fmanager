<?php
// install/index.php - File Manager Installer - Step 1

// --- CONFIGURAZIONE INIZIALE INSTALLER ---
error_reporting(E_ALL);
ini_set('display_errors', 1); 
if (!headers_sent() && function_exists('date_default_timezone_set') && !@date_default_timezone_set('Europe/Rome')) {
    date_default_timezone_set('UTC');
}

if (!defined('INSTALLER_PROJECT_ROOT')) { define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); }
// Le seguenti costanti sono usate solo per i check, non per il logging dentro questo file specifico ancora
define('INSTALLER_LOG_DIR_CHECK', INSTALLER_PROJECT_ROOT . '/LOG/'); 
define('INSTALLER_CONFIG_PATH_CHECK', INSTALLER_PROJECT_ROOT . '/config.php');
define('INSTALLER_SYSTEMIMAGES_PATH_CHECK', INSTALLER_PROJECT_ROOT . '/SystemImages/');

// Includi le funzioni helper dell'installer (che ora conterranno anche i logger)
if (file_exists(__DIR__ . '/installer_functions.php')) {
    require_once __DIR__ . '/installer_functions.php';
} else {
    // Fallback se installer_functions.php non è ancora stato creato (anche se dovrebbe)
    // Definisci funzioni di log minimali qui se necessario per questo script
    if (!function_exists('installer_log_error')) { function installer_log_error($m){ @error_log("INSTALL_ERR:".$m); } }
    if (!function_exists('installer_log_activity')) { function installer_log_activity($m){ @error_log("INSTALL_ACT:".$m); } }
    if (!function_exists('installer_session_start')) { function installer_session_start(){ if(session_status()==PHP_SESSION_NONE){session_name('InstallerFM_SID'); session_set_cookie_params(['path'=>dirname($_SERVER['SCRIPT_NAME']).'/','httponly'=>true,'samesite'=>'Lax']); session_start(); if(!isset($_SESSION['installer_step']))$_SESSION['installer_step']=1; if(!isset($_SESSION['installer_config']))$_SESSION['installer_config']=['db'=>[],'site'=>[],'admin'=>[]]; if(!isset($_SESSION['installer_reinstall_mode']))$_SESSION['installer_reinstall_mode']=false;}}}
}

installer_session_start(); // Avvia o ripristina la sessione dell'installer

$current_step_from_get = isset($_GET['step']) && is_numeric($_GET['step']) ? (int)$_GET['step'] : null;
if ($current_step_from_get !== null && $current_step_from_get == 1) { // Permetti solo di tornare allo step 1
    $_SESSION['installer_step'] = 1;
}
$current_step = $_SESSION['installer_step'] ?? 1;
if ($current_step != 1) { // Se per qualche motivo siamo qui ma lo step è diverso da 1, forza step 1
    $_SESSION['installer_step'] = 1;
    $current_step = 1;
}


$error_message_flash = $_SESSION['installer_error_message'] ?? null;
unset($_SESSION['installer_error_message']);
$success_message_flash = $_SESSION['installer_success_message'] ?? null; // INIZIALIZZATA QUI
unset($_SESSION['installer_success_message']);

$is_reinstalling = $_SESSION['installer_reinstall_mode'] ?? (isset($_GET['reinstall']) && $_GET['reinstall'] === 'true');
if ($is_reinstalling) { $_SESSION['installer_reinstall_mode'] = true; }
$reinstall_param_html = $is_reinstalling ? "&reinstall=true" : "";
$reinstall_param_php = $is_reinstalling ? "?reinstall=true" : "";


// --- ESEGUI I CONTROLLI PRELIMINARI ---
$checks = [];
$all_critical_checks_ok = true;

$required_php_version = '7.4.0'; $current_php_version = PHP_VERSION;
$php_version_ok = version_compare($current_php_version, $required_php_version, '>=');
$checks[] = ['desc' => "Versione PHP >= {$required_php_version}", 'status' => $php_version_ok, 'msg' => $php_version_ok ? "OK ({$current_php_version})" : "Fallito (Versione attuale: {$current_php_version}. Richiesta: {$required_php_version}+)", 'crit' => true];
if (!$php_version_ok) $all_critical_checks_ok = false;

$req_ext = ['mysqli'=>'Accesso DB MySQL', 'mbstring'=>'Stringhe Multibyte (UTF-8)', 'json'=>'Supporto JSON', 'fileinfo'=>'Rilevamento MIME Type', 'gd'=>'Manipolazione Immagini (Opzionale)'];
foreach ($req_ext as $ext => $desc) {
    $loaded = extension_loaded($ext);
    $is_crit = ($ext === 'mysqli' || $ext === 'mbstring' || $ext === 'json' || $ext === 'fileinfo');
    $checks[] = ['desc' => "Estensione PHP: {$ext}", 'status' => $loaded, 'msg' => $loaded ? "OK (Caricata)" : ($is_crit ? "Fallito (Mancante)" : "Avviso: Non Caricata"), 'crit' => $is_crit];
    if (!$loaded && $is_crit) $all_critical_checks_ok = false;
}

$config_writable_ok = (file_exists(INSTALLER_CONFIG_PATH_CHECK) && is_writable(INSTALLER_CONFIG_PATH_CHECK)) || (!file_exists(INSTALLER_CONFIG_PATH_CHECK) && is_writable(INSTALLER_PROJECT_ROOT));
$checks[] = ['desc' => "Scrittura file <code>config.php</code> (o directory principale)", 'status' => $config_writable_ok, 'msg' => $config_writable_ok ? "OK" : "Fallito", 'crit' => true];
if (!$config_writable_ok) $all_critical_checks_ok = false;

$log_writable_ok = (is_dir(INSTALLER_LOG_DIR_CHECK) && is_writable(INSTALLER_LOG_DIR_CHECK)) || (!is_dir(INSTALLER_LOG_DIR_CHECK) && @mkdir(INSTALLER_LOG_DIR_CHECK,0755,true) && is_writable(INSTALLER_LOG_DIR_CHECK));
$checks[] = ['desc' => "Creazione/Scrittura cartella <code>LOG/</code>", 'status' => $log_writable_ok, 'msg' => $log_writable_ok ? "OK" : "Fallito", 'crit' => true];
if (!$log_writable_ok) $all_critical_checks_ok = false;

$sysimg_writable_ok = (is_dir(INSTALLER_SYSTEMIMAGES_PATH_CHECK) && is_writable(INSTALLER_SYSTEMIMAGES_PATH_CHECK)) || (!is_dir(INSTALLER_SYSTEMIMAGES_PATH_CHECK) && @mkdir(INSTALLER_SYSTEMIMAGES_PATH_CHECK,0755,true) && is_writable(INSTALLER_SYSTEMIMAGES_PATH_CHECK));
$checks[] = ['desc' => "Creazione/Scrittura cartella <code>SystemImages/</code> (per logo)", 'status' => $sysimg_writable_ok, 'msg' => $sysimg_writable_ok ? "OK" : "Fallito (Upload logo potrebbe non funzionare)", 'crit' => false]; 

$htaccess_path = INSTALLER_PROJECT_ROOT . '/.htaccess'; $htaccess_exists = file_exists($htaccess_path);
$checks[] = ['desc' => "Presenza file <code>.htaccess</code> (per URL \"puliti\")", 'status' => $htaccess_exists, 'msg' => $htaccess_exists ? "OK (Trovato)" : "Avviso: non trovato (URL \"puliti\" potrebbero non funzionare)", 'crit' => false];

$config_exists_warning_msg = null;
if (file_exists(INSTALLER_CONFIG_PATH_CHECK) && !$is_reinstalling) {
    $reinstall_link_url = htmlspecialchars(basename($_SERVER['SCRIPT_NAME']) . "?reinstall=true"); 
    $config_exists_warning_msg = "Attenzione: Un file <code>config.php</code> esiste già! Procedere lo sovrascriverà. Per forzare una reinstallazione (CON CAUTELA), <a href='{$reinstall_link_url}' style='color:red; font-weight:bold;'>clicca qui</a>.";
    $all_critical_checks_ok = false; 
}
if ($is_reinstalling) {
     $config_exists_warning_msg = "<strong style='color:red;'>MODALITÀ REINSTALLAZIONE ATTIVA.</strong> <code>config.php</code> e tabelle DB (con stesso prefisso) verranno sovrascritti/ricreati.";
}
    
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proceed_to_db_config'])) {
    if ($all_critical_checks_ok) {
        $_SESSION['installer_step'] = 2; 
        header("Location: step2_db_config.php" . $reinstall_param_php); // Reindirizza al file del passo 2
        exit;
    } else {
        $_SESSION['installer_error_message'] = "Risolvi i problemi critici o conferma la reinstallazione.";
        header("Location: index.php" . $reinstall_param_php); // Ricarica questo file (step 1)
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione File Manager - Passo 1</title>
    <link rel="stylesheet" href="install_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="installer-container">
        <h1>Installazione File Manager <small style="font-size:0.5em; color:#777;">Passo 1 di 5</small></h1>
        <hr>

        <?php if ($error_message_flash): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error_message_flash)); ?></div>
        <?php endif; ?>
        <?php if ($success_message_flash): // Controlla la variabile corretta ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message_flash); ?></div>
        <?php endif; ?>

        <h2>Benvenuto e Controlli Preliminari</h2>
        <?php if (isset($config_exists_warning_msg)): ?>
            <div class="alert <?php echo $is_reinstalling ? 'alert-info' : 'alert-warning'; ?>"><?php echo $config_exists_warning_msg; ?></div>
        <?php endif; ?>
        <p>Questo script ti guiderà attraverso i passaggi necessari per installare e configurare il File Manager sul tuo server.</p>
        
        <h3>Requisiti del Server:</h3>
        <ul class="checks">
            <?php foreach ($checks as $check): ?>
                <li>
                    <span class="check-desc"><?php echo $check['desc']; ?></span>
                    <span class="check-status <?php echo $check['status'] ? 'check-ok' : ($check['crit'] ? 'check-fail' : 'check-warning'); ?>">
                        <span><?php echo htmlspecialchars($check['msg']); ?></span>
                    </span>
                    <?php if (!$check['status'] && $check['crit']): ?>
                         <i class="fas fa-times-circle check-fail" title="Critico - Deve essere risolto"></i>
                    <?php elseif (!$check['status'] && !$check['crit']): ?>
                         <i class="fas fa-exclamation-triangle check-warning" title="Avviso - Consigliato risolvere"></i>
                    <?php else: ?>
                        <i class="fas fa-check-circle check-ok" title="OK"></i>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($all_critical_checks_ok): ?>
            <p class="check-ok" style="padding:15px; background-color:#e8f5e9; border:1px solid #a5d6a7; border-radius:4px; text-align:center; font-size:1.1em; margin-top: 20px;">
                <i class="fas fa-thumbs-up"></i> Tutti i controlli critici sono stati superati! Puoi procedere.
            </p>
            <form action="index.php<?php if($is_reinstalling) echo $reinstall_param_php; ?>" method="POST" style="text-align:right; margin-top:20px;">
                <input type="hidden" name="reinstall_flag" value="<?php echo $is_reinstalling ? 'true' : 'false'; ?>">
                <button type="submit" name="proceed_to_db_config" class="btn">
                    Vai alla Configurazione Database <i class="fas fa-arrow-right"></i>
                </button>
            </form>
        <?php else: ?>
            <p class="check-fail" style="padding:15px; background-color:#fdecea; border:1px solid #ef9a9a; border-radius:4px; text-align:center; font-size:1.1em; margin-top: 20px;">
                <i class="fas fa-times-circle"></i> Alcuni controlli critici non sono stati superati (o è necessario confermare la reinstallazione se <code>config.php</code> esiste già). Per favore, risolvi i problemi indicati sopra e poi ricarica la pagina.
            </p>
            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn btn-nav" onclick="window.location.href='index.php<?php if($is_reinstalling) echo $reinstall_param_php; // Mantieni il flag se presente ?>';">
                    <i class="fas fa-sync-alt"></i> Riprova Controlli
                </button>
            </div>
        <?php endif; ?>
        
    </div> 
</body>
</html>