<?php
// install/step6_success.php - Installer - Step 6: Installation Complete

if (!defined('INSTALLER_PROJECT_ROOT')) { define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); }
require_once __DIR__ . '/installer_functions.php'; 

installer_session_start(); 

// Protezione: assicurati che l'utente provenga dal Passo 5 completato con successo
if (!isset($_SESSION['installer_step']) || $_SESSION['installer_step'] != 6 || !isset($_SESSION['installer_config']['site']['site_url'])) {
    installer_log_warning("Accesso non valido allo Step 6 o configurazione mancante. Riporto allo Step 1.");
    $_SESSION['installer_step'] = 1; 
    unset($_SESSION['installer_config']); // Pulisci config parziale
    unset($_SESSION['installer_reinstall_mode']);
    unset($_SESSION['installer_final_admin_details']);
    header('Location: index.php');
    exit;
}
$current_step = 6;

$site_name_display = $_SESSION['installer_config']['site']['site_name'] ?? 'File Manager';
$site_url_display = $_SESSION['installer_config']['site']['site_url'] ?? '../index.php'; // Fallback a relativo
$admin_username_display = $_SESSION['installer_final_admin_details']['username'] ?? 'admin';

// Pulisci la maggior parte della sessione dell'installer, ma mantieni i dati per questa pagina
unset($_SESSION['installer_step']); // Rimuovi lo step per evitare rientri accidentali
// Non cancellare installer_config e final_admin_details subito, servono per visualizzare
// Saranno cancellati alla prossima interazione o quando l'utente lascia questa pagina
// O si può fare un session_destroy() più aggressivo se l'utente clicca "Vai al sito".
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione Completata!</title>
    <link rel="stylesheet" href="install_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="installer-container">
        <h1>Installazione File Manager <small style="font-size:0.5em; color:#777;">Completata!</small></h1>
        <hr>

        <h2><i class="fas fa-check-circle check-ok" style="font-size: 1.5em; color: #2ecc71;"></i> Installazione Completata con Successo!</h2>
        
        <div class="alert alert-success" style="margin-top: 20px;">
            <p>Il tuo File Manager è stato installato e configurato correttamente.</p>
            <p>L'utente amministratore principale è stato creato:</p>
            <ul style="padding-left: 20px; list-style-type: disc;">
                <li><strong>Username:</strong> <?php echo htmlspecialchars($admin_username_display); ?></li>
                <li><strong>Password:</strong> Quella che hai specificato durante il Passo 3 (è una password temporanea, ti verrà chiesto di cambiarla al primo accesso).</li>
            </ul>
        </div>

        <div class="alert alert-warning" style="margin-top: 20px;">
            <h4><i class="fas fa-exclamation-triangle"></i> Azioni Post-Installazione IMPORTANTI:</h4>
            <ol style="padding-left: 20px; list-style-type: decimal;">
                <li style="border-bottom: none; padding: 5px 0;"><strong>PER MOTIVI DI SICUREZZA, ELIMINA IMMEDIATAMENTE la cartella <code>install/</code> e tutti i suoi file dal tuo server.</strong></li>
                <li style="border-bottom: none; padding: 5px 0;">Accedi a <strong><?php echo htmlspecialchars($site_name_display); ?></strong> utilizzando l'URL principale (<code><a href="<?php echo htmlspecialchars($site_url_display); ?>" target="_blank"><?php echo htmlspecialchars($site_url_display); ?></a></code>) e le credenziali admin create.</li>
                <li style="border-bottom: none; padding: 5px 0;">Al primo accesso, ti verrà richiesto di cambiare la password temporanea.</li>
                <li style="border-bottom: none; padding: 5px 0;">Verifica tutte le impostazioni del sito nel pannello di amministrazione.</li>
                <li style="border-bottom: none; padding: 5px 0;">Rendi il file <code>config.php</code> (nella root del progetto) non scrivibile dal server web per maggiore sicurezza (es. permessi <code>0444</code> o <code>0400</code>).</li>
                <li style="border-bottom: none; padding: 5px 0;">Configura un cron job per lo script di file aging (<code>cron_tasks/process_file_aging.php</code>) se intendi usarlo (lo vedremo più avanti).</li>
                <li style="border-bottom: none; padding: 5px 0;">Configura `logrotate` per i file di log (<code>LOG/activity.log</code>, <code>LOG/error.log</code>, e quelli dell'installer in <code>LOG/install_*.log</code>).</li>
            </ol>
        </div>

        <div style="text-align:center; margin-top:30px;">
            <a href="<?php echo htmlspecialchars($site_url_display); ?>" class="btn btn-success" style="font-size:1.2em; padding: 12px 25px;"><i class="fas fa-arrow-right"></i> Vai al Sito Ora</a>
        </div>
        <?php 
            // Pulisci definitivamente la sessione dell'installer
            if (isset($_SESSION['installer_step'])) unset($_SESSION['installer_step']);
            if (isset($_SESSION['installer_config'])) unset($_SESSION['installer_config']);
            if (isset($_SESSION['installer_reinstall_mode'])) unset($_SESSION['installer_reinstall_mode']);
            if (isset($_SESSION['installer_final_admin_details'])) unset($_SESSION['installer_final_admin_details']);
            if (isset($_SESSION['installer_error_message'])) unset($_SESSION['installer_error_message']);
            if (isset($_SESSION['installer_success_message'])) unset($_SESSION['installer_success_message']);
            // session_destroy(); // Se vuoi distruggere completamente la sessione 'InstallerFM_SID'
        ?>
    </div>
</body>
</html>