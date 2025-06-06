<?php
// install/step5_finalize.php - Installer - Step 5: Finalization

if (!defined('INSTALLER_PROJECT_ROOT')) { define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); }
require_once __DIR__ . '/installer_functions.php'; 

installer_session_start(); 

// Protezione: assicurati che l'utente provenga dal Passo 4 completato
if (!isset($_SESSION['installer_step']) || $_SESSION['installer_step'] < 5) {
    // Controlla anche che i dati essenziali siano in sessione
    if (!($_SESSION['installer_step'] == 5 && 
          isset($_SESSION['installer_config']['db']['host']) &&
          isset($_SESSION['installer_config']['site']['site_name']) &&
          isset($_SESSION['installer_config']['admin']['username']))) { 
        installer_log_warning("Accesso non valido allo Step 5. Dati config mancanti o step errato. Riporto al Passo appropriato.");
        $_SESSION['installer_error_message'] = "Per favore, completa tutti i passaggi di configurazione precedenti.";
        // Reindirizza al passo più logico da cui ricominciare
        if (!isset($_SESSION['installer_config']['db']['host'])) {
            $_SESSION['installer_step'] = 2;
            header('Location: step2_db_config.php' . (($_SESSION['installer_reinstall_mode'] ?? false) ? '?reinstall=true' : ''));
        } else {
            $_SESSION['installer_step'] = 3; 
            header('Location: step3_site_admin.php' . (($_SESSION['installer_reinstall_mode'] ?? false) ? '?reinstall=true' : ''));
        }
        exit;
    }
}
$_SESSION['installer_step'] = 5; 
$current_step = 5;

$error_message_flash = $_SESSION['installer_error_message'] ?? null; unset($_SESSION['installer_error_message']);
$success_message_flash = $_SESSION['installer_success_message'] ?? null; unset($_SESSION['installer_success_message']);
$is_reinstalling = $_SESSION['installer_reinstall_mode'] ?? false;
$reinstall_param_php_query = $is_reinstalling ? "?reinstall=true" : "";
$reinstall_hidden_field = $is_reinstalling ? '<input type="hidden" name="reinstall_flag" value="true">' : '';

// Recupera tutte le configurazioni dalla sessione
$db_conf = $_SESSION['installer_config']['db'] ?? null;
$site_conf = $_SESSION['installer_config']['site'] ?? null;
$admin_conf = $_SESSION['installer_config']['admin'] ?? null;

if (!$db_conf || !$site_conf || !$admin_conf) { // Controllo di sicurezza aggiuntivo
    $_SESSION['installer_error_message'] = "Configurazione critica mancante in sessione. Impossibile finalizzare. Riparti dal Passo 1.";
    $_SESSION['installer_step'] = 1; 
    installer_log_error("Tentativo di finalizzazione con configurazione critica mancante in sessione.");
    header("Location: index.php" . $reinstall_param_php_query); exit;
}

// --- LOGICA POST PER IL PASSO 5: Finalizzazione ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_finalize_installation'])) {
    installer_log_activity("Ricevuto POST per Step 5: Finalizzazione dell'Installazione.");
    $errors_finalize = [];

    // Assicura che il prefisso sia vuoto come deciso
    $db_conf['prefix'] = ''; 

    // --- 1. Genera e scrivi il file config.php ---
    $log_error_func_str = <<<'EOD'
if (!function_exists('log_error')) { function log_error($m,$s='',$l='') { $ts=date('Y-m-d H:i:s');$le="[{$ts}]";if(!empty($s))$le.=" [S: ".basename($s)."]";if(!empty($l))$le.=" [L:{$l}]";$le.=" - Error: {$m}".PHP_EOL;$elf=defined('ERROR_LOG')?ERROR_LOG:(PROJECT_ROOT.'/LOG/error.log');@error_log($le,3,$elf);}}
EOD;
    $log_activity_func_str = <<<'EOD'
if (!function_exists('log_activity')) { function log_activity($m,$uid=null) { $ts=date('Y-m-d H:i:s');$le="[{$ts}]";if($uid!==null)$le.=" [UID:{$uid}]";$le.=" - Activity: {$m}".PHP_EOL;$alf=defined('ACTIVITY_LOG')?ACTIVITY_LOG:(PROJECT_ROOT.'/LOG/activity.log');@error_log($le,3,$alf);}}
EOD;
    $log_warning_func_str = <<<'EOD'
if (!function_exists('log_warning')) { function log_warning($m,$s='',$l='') { $ts=date('Y-m-d H:i:s');$le="[{$ts}]";if(!empty($s))$le.=" [S: ".basename($s)."]";if(!empty($l))$le.=" [L:{$l}]";$le.=" - Warning: {$m}".PHP_EOL;$elf=defined('ERROR_LOG')?ERROR_LOG:(PROJECT_ROOT.'/LOG/error.log');@error_log($le,3,$elf);}}
EOD;

    $config_content = "<?php\n// File di configurazione generato dall'installer il " . date('Y-m-d H:i:s') . "\n\n";
    $config_content .= "ini_set('display_errors', 1);\nini_set('display_startup_errors', 1);\nerror_reporting(E_ALL);\n";
    $config_content .= "if(!@date_default_timezone_set('Europe/Rome')) { date_default_timezone_set('UTC'); }\n\n";
    
    $config_content .= "// --- Impostazioni Database ---\n";
    $config_content .= "if (!defined('DB_HOST')) define('DB_HOST', '" . addslashes($db_conf['host']) . "');\n";
    $config_content .= "if (!defined('DB_USER')) define('DB_USER', '" . addslashes($db_conf['user']) . "');\n";
    $config_content .= "if (!defined('DB_PASS')) define('DB_PASS', '" . addslashes($db_conf['pass']) . "');\n";
    $config_content .= "if (!defined('DB_NAME')) define('DB_NAME', '" . addslashes($db_conf['name']) . "');\n";
    $config_content .= "if (!defined('DB_TABLE_PREFIX')) define('DB_TABLE_PREFIX', '" . addslashes($db_conf['prefix']) . "'); // Sarà vuoto\n\n";

    $config_content .= "// --- Impostazioni Sito ---\n";
    $site_url_final = rtrim($site_conf['site_url'], '/');
    $config_content .= "if (!defined('SITE_URL')) define('SITE_URL', '" . addslashes($site_url_final) . "');\n";
    $rewrite_base = rtrim(parse_url($site_url_final, PHP_URL_PATH) ?: '/', '/');
    if (empty($rewrite_base) || $rewrite_base === '/') { $rewrite_base = '/'; } 
    elseif (strpos($rewrite_base, '/') !== 0) { $rewrite_base = '/' . $rewrite_base; }
    $config_content .= "if (!defined('REWRITE_BASE_FOR_PHP')) define('REWRITE_BASE_FOR_PHP', '" . addslashes($rewrite_base) . "');\n";
    $config_content .= "if (!defined('SITE_NAME')) define('SITE_NAME', '" . addslashes($site_conf['site_name']) . "');\n";
    $config_content .= "if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', dirname(__FILE__)); // Definisce PROJECT_ROOT relativo a config.php\n";
    $config_content .= "if (defined('PROJECT_ROOT') && !defined('PHPMAILER_PATH')) define('PHPMAILER_PATH', PROJECT_ROOT . '/includes/PHPMailer/src/');\n";
    
    $recaptcha_site_key = $site_conf['recaptcha_site_key'] ?? ''; $recaptcha_secret_key = $site_conf['recaptcha_secret_key'] ?? '';
    $config_content .= "if (!defined('RECAPTCHA_SITE_KEY')) define('RECAPTCHA_SITE_KEY', '" . addslashes($recaptcha_site_key) . "');\n";
    $config_content .= "if (!defined('RECAPTCHA_SECRET_KEY')) define('RECAPTCHA_SECRET_KEY', '" . addslashes($recaptcha_secret_key) . "');\n";
    
    $config_content .= "if (!defined('SESSION_NAME')) define('SESSION_NAME', 'MyFileShareFM_SID');\n";
    $config_content .= "if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', 1800);\n";
    $config_content .= "if (!defined('LOG_PATH')) define('LOG_PATH', PROJECT_ROOT . '/LOG/');\n";
    $config_content .= "if (!defined('ACTIVITY_LOG')) define('ACTIVITY_LOG', LOG_PATH . 'activity.log');\n";
    $config_content .= "if (!defined('ERROR_LOG')) define('ERROR_LOG', LOG_PATH . 'error.log');\n";
    $config_content .= "if (!defined('NAS_ROOT_PATH')) define('NAS_ROOT_PATH', '" . addslashes($site_conf['nas_root_path']) . "');\n";
    $config_content .= "if (!defined('ADMIN_NOTIFICATION_EMAIL')) define('ADMIN_NOTIFICATION_EMAIL', '" . addslashes($admin_conf['email']) . "');\n";
    $config_content .= "if (!defined('SITE_EMAIL_FROM')) define('SITE_EMAIL_FROM', '" . addslashes($site_conf['system_email_from']) . "');\n";
    $config_content .= "if (!defined('DEFAULT_USER_QUOTA_BYTES')) define('DEFAULT_USER_QUOTA_BYTES', " . (string)(1024*1024*1024) . "); // 1GB\n\n";

    $config_content .= "// --- Funzioni di Logging Base ---\n";
    $config_content .= str_replace("PROJECT_ROOT", "dirname(__FILE__)", $log_error_func_str) . "\n"; 
    $config_content .= str_replace("PROJECT_ROOT", "dirname(__FILE__)", $log_activity_func_str) . "\n"; 
    $config_content .= str_replace("PROJECT_ROOT", "dirname(__FILE__)", $log_warning_func_str) . "\n\n";
    $config_content .= "if (defined('LOG_PATH') && !is_dir(LOG_PATH)) { if (!@mkdir(LOG_PATH,0755,true)&&!is_dir(LOG_PATH)) { trigger_error(\"CRITICO: Impossibile creare LOG_PATH: \".LOG_PATH,E_USER_WARNING);}}\n\n";
    $config_content .= "if (defined('PROJECT_ROOT')&&file_exists(PROJECT_ROOT.'/includes/functions_url.php')){require_once PROJECT_ROOT.'/includes/functions_url.php';} elseif(file_exists(dirname(__FILE__).'/includes/functions_url.php')){require_once dirname(__FILE__).'/includes/functions_url.php';} else { if(function_exists(\"log_error\")) log_error(\"CRITICO: functions_url.php non trovato da config.php\"); else error_log(\"CRITICO: functions_url.php non trovato da config.php\");}\n\n";
    $config_content .= "if (session_status()==PHP_SESSION_NONE){if(defined('SESSION_NAME'))session_name(SESSION_NAME);session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>(\$_SERVER['HTTP_HOST'] ?? ''),'secure'=>isset(\$_SERVER['HTTPS'])&&\$_SERVER['HTTPS']==='on','httponly'=>true,'samesite'=>'Lax']);if(!session_start()){if(function_exists('log_error'))log_error('Impossibile avviare sessione da config.php.',__FILE__,__LINE__);}}\n";
    $config_content .= "?>";

    if (!defined('INSTALLER_CONFIG_PATH')) { $errors_finalize[] = "Errore critico: INSTALLER_CONFIG_PATH non definito nello script dell'installer."; installer_log_error("Finalizzazione: INSTALLER_CONFIG_PATH non definito.");}
    elseif (@file_put_contents(INSTALLER_CONFIG_PATH, $config_content) === false) { 
        $errors_finalize[] = "Errore fatale: Impossibile scrivere il file <code>config.php</code>. Verifica i permessi di scrittura sulla directory principale del progetto (<code>" . htmlspecialchars(INSTALLER_PROJECT_ROOT) . "/</code>).";
        installer_log_error("Finalizzazione: Impossibile scrivere config.php a: " . INSTALLER_CONFIG_PATH);
    } else {
        installer_log_activity("Finalizzazione: File config.php scritto con successo in " . INSTALLER_CONFIG_PATH);
        @chmod(INSTALLER_CONFIG_PATH, 0644); // Permessi di lettura per tutti, scrittura per il proprietario
    }

    // --- 2. Creazione Tabelle Database ---
    if (empty($errors_finalize)) {
        $db_conn_install = @mysqli_connect($db_conf['host'], $db_conf['user'], $db_conf['pass'], $db_conf['name']);
        if (!$db_conn_install) {
            $errors_finalize[] = "Errore di connessione al database (<code>" . htmlspecialchars($db_conf['name']) . "</code>) per creare le tabelle: " . mysqli_connect_error() . ". Verifica il file <code>config.php</code> appena creato o i permessi dell'utente DB.";
            installer_log_error("Finalizzazione: Riconnessione DB fallita per creazione tabelle: " . mysqli_connect_error());
        } else {
            mysqli_set_charset($db_conn_install, 'utf8mb4');
            installer_log_activity("Finalizzazione: Connesso al DB '{$db_conf['name']}' per creazione tabelle.");

            if (!defined('INSTALLER_SCHEMA_TEMPLATE_PATH') || !file_exists(INSTALLER_SCHEMA_TEMPLATE_PATH)) {
                $errors_finalize[] = "File dello schema del database (<code>schema_template.sql</code>) non trovato nella cartella <code>install/</code>.";
                installer_log_error("Finalizzazione: schema_template.sql non trovato: " . (defined('INSTALLER_SCHEMA_TEMPLATE_PATH') ? INSTALLER_SCHEMA_TEMPLATE_PATH : 'Percorso non definito'));
            } else {
                $sql_schema_raw = file_get_contents(INSTALLER_SCHEMA_TEMPLATE_PATH);
                $db_table_prefix_to_use = $db_conf['prefix'] ?? ''; // Dovrebbe essere '' come da step2
                $sql_schema_processed = str_replace('%%DB_TABLE_PREFIX%%', $db_table_prefix_to_use, $sql_schema_raw);
                
                $sql_commands = array_filter(array_map('trim', explode(';', $sql_schema_processed)), function($query) {
                    return !empty($query) && strpos(trim($query), '--') !== 0; // Rimuovi query vuote e commenti SQL
                });

                mysqli_query($db_conn_install, "SET FOREIGN_KEY_CHECKS=0;");
                if ($is_reinstalling) {
                    installer_log_activity("Modalità Reinstallazione: Tento di eliminare tabelle esistenti con prefisso '{$db_table_prefix_to_use}'.");
                    $tables_to_drop_ordered = ["folder_permissions", "files", "folders", "site_settings", "users"]; 
                    foreach (array_reverse($tables_to_drop_ordered) as $table_base_name) { 
                        $table_name_drop = $db_table_prefix_to_use . $table_base_name;
                        if (!mysqli_query($db_conn_install, "DROP TABLE IF EXISTS `{$table_name_drop}`;")) {
                             installer_log_warning("Fallito DROP TABLE IF EXISTS `{$table_name_drop}`: " . mysqli_error($db_conn_install));
                        } else { installer_log_activity("DROP TABLE IF EXISTS `{$table_name_drop}` eseguito.");}
                    }
                }

                $table_creation_errors = [];
                foreach ($sql_commands as $command) {
                    if (!mysqli_query($db_conn_install, $command)) {
                        $error_detail_sql = mysqli_error($db_conn_install);
                        $table_creation_errors[] = "Query: " . htmlspecialchars(substr($command, 0, 150)) . "... -> Errore: " . htmlspecialchars($error_detail_sql);
                        installer_log_error("Finalizzazione SQL Error: " . $error_detail_sql . " -- Query: " . $command);
                    }
                }

                if (empty($table_creation_errors)) { installer_log_activity("Finalizzazione: Schema DB eseguito."); }
                else { $errors_finalize = array_merge($errors_finalize, ["Errori creazione tabelle/indici:"], $table_creation_errors); }
                mysqli_query($db_conn_install, "SET FOREIGN_KEY_CHECKS=1;");
            }

            // --- 3. Inserisci Impostazioni Sito ---
            if (empty($errors_finalize)) {
                $settings_to_insert = [
                    'site_name' => $site_conf['site_name'], 'site_logo_url' => $site_conf['logo_url'] ?? '',
                    'site_logo_max_height' => $site_conf['site_logo_max_height'] ?? '50',
                    'site_logo_max_width' => $site_conf['site_logo_max_width'] ?? '0',
                    'site_logo_alignment' => $site_conf['site_logo_alignment'] ?? 'center',
                    'footer_text' => $site_conf['footer_text'],
                    'theme_navbar_bg' => $site_conf['theme_navbar_bg'] ?? '#343a40',
                    'theme_navbar_text' => $site_conf['theme_navbar_text'] ?? '#ffffff',
                    'theme_navbar_text_hover' => $site_conf['theme_navbar_text_hover'] ?? '#f8f9fa',
                    'theme_footer_bg' => $site_conf['theme_footer_bg'] ?? '#f8f9fa',
                    'theme_footer_text_color' => $site_conf['theme_footer_text_color'] ?? '#6c757d',
                    'theme_accent_color' => $site_conf['theme_accent_color'] ?? '#007bff',
                    'theme_global_font_family' => $site_conf['theme_global_font_family'] ?? '',
                    'aging_enabled' => $site_conf['aging_enabled'] ?? '0',
                    'aging_delete_grace_period_days' => $site_conf['aging_delete_grace_period_days'] ?? '7',
                    'default_user_quota_bytes' => (string)(1024 * 1024 * 1024) 
                ];
                if (isset($site_conf['recaptcha_site_key'])) $settings_to_insert['recaptcha_site_key'] = $site_conf['recaptcha_site_key'];
                if (isset($site_conf['recaptcha_secret_key'])) $settings_to_insert['recaptcha_secret_key'] = $site_conf['recaptcha_secret_key'];

                $stmt_settings = $db_conn_install->prepare("INSERT INTO `{$db_table_prefix_to_use}site_settings` (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                if (!$stmt_settings) { $errors_finalize[] = "Errore DB (prep settings): " . $db_conn_install->error; installer_log_error("DB Error (prep site_settings): " . $db_conn_install->error); }
                else {
                    foreach ($settings_to_insert as $key => $value) { $stmt_settings->bind_param("ss", $key, $value); if (!$stmt_settings->execute()) { installer_log_error("DB Error (exec site_settings key {$key}): " . $stmt_settings->error); $errors_finalize[] = "Errore salvataggio impostazione: {$key}";}}
                    $stmt_settings->close(); installer_log_activity("Impostazioni sito salvate nel DB.");
                }
            }

            // --- 4. Crea Utente Admin ---
            if (empty($errors_finalize)) {
                $admin_pass_hash = password_hash($admin_conf['password_temp'], PASSWORD_DEFAULT);
                $admin_is_active = 1; $admin_req_admin_val = 0; $admin_req_pwd_change = 1; $admin_is_email_val = 1;
                $admin_role = 'Admin'; $admin_quota_default = $settings_to_insert['default_user_quota_bytes']; 
                
                if ($is_reinstalling) { 
                    $stmt_del_admin = $db_conn_install->prepare("DELETE FROM `{$db_table_prefix_to_use}users` WHERE username = ? OR email = ?");
                    if($stmt_del_admin){ $stmt_del_admin->bind_param("ss", $admin_conf['username'], $admin_conf['email']); $stmt_del_admin->execute(); $stmt_del_admin->close(); installer_log_activity("Reinstall: Tentativo rimozione admin esistente User: ".$admin_conf['username']);}
                }

                $stmt_admin = $db_conn_install->prepare("INSERT INTO `{$db_table_prefix_to_use}users` (username, email, password_hash, role, is_active, requires_admin_validation, requires_password_change, is_email_validated, quota_bytes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if (!$stmt_admin) { $errors_finalize[] = "Errore DB (prep admin): " . $db_conn_install->error; installer_log_error("DB Error (prep admin user): " . $db_conn_install->error); }
                else {
                    $admin_quota_db_val = (string)$admin_quota_default; // Tratta come stringa per bind_param 's' o 'd'
                    $stmt_admin->bind_param("ssssiiiis", $admin_conf['username'], $admin_conf['email'], $admin_pass_hash, $admin_role, $admin_is_active, $admin_req_admin_val, $admin_req_pwd_change, $admin_is_email_val, $admin_quota_db_val);
                    if ($stmt_admin->execute()) { $new_admin_id = $stmt_admin->insert_id; installer_log_activity("Admin creato ID: {$new_admin_id}, User: " . $admin_conf['username']); $_SESSION['installer_final_admin_details'] = ['username' => $admin_conf['username']]; }
                    else { $errors_finalize[] = "Errore DB (creazione admin): " . $stmt_admin->error . ". Username/Email potrebbero essere già in uso."; installer_log_error("DB Error (exec admin user): " . $stmt_admin->error); }
                    $stmt_admin->close();
                }
            }
            mysqli_close($db_conn_install);
        } 
    } 

    if (empty($errors_finalize)) {
        $_SESSION['installer_step'] = 6; 
        installer_log_activity("INSTALLAZIONE COMPLETATA CON SUCCESSO.");
        header("Location: step6_success.php" . $reinstall_param_php_query); exit;
    } else {
        $_SESSION['installer_error_message'] = "Si sono verificati uno o più errori durante la finalizzazione:<br>" . implode("<br>", array_map('htmlspecialchars', $errors_finalize));
        header("Location: step5_finalize.php" . $reinstall_param_php_query); exit;
    }
} 
// --- FINE LOGICA POST PASSO 5 ---
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione File Manager - Passo 5: Finalizzazione</title>
    <link rel="stylesheet" href="install_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="installer-container">
        <h1>Installazione File Manager <small style="font-size:0.5em; color:#777;">Passo 5 di 5</small></h1>
        <hr>

        <?php if ($error_message_flash): ?>
            <div class="alert alert-danger"><?php echo nl2br($error_message_flash); ?></div>
        <?php endif; ?>
        <?php if ($success_message_flash): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message_flash); ?></div>
        <?php endif; ?>

        <h2>Finalizzazione Installazione</h2>
        <p>Rivedi il riepilogo delle impostazioni. Cliccando "Completa Installazione", verranno eseguite le seguenti operazioni:</p>
        <ol style="padding-left: 20px;">
            <li>Scrittura del file <code>config.php</code> nella directory principale del progetto.</li>
            <li>Connessione al database '<strong><?php echo htmlspecialchars($db_conf['name'] ?? 'N/D'); ?></strong>'.</li>
            <li>Creazione delle tabelle necessarie (con prefisso '<strong><?php echo htmlspecialchars($db_conf['prefix'] ?? '(nessuno)'); ?></strong>'). <?php if($is_reinstalling) echo "<strong style='color:red;'>(Le tabelle esistenti con questo prefisso verranno prima eliminate!)</strong>"; ?></li>
            <li>Creazione dell'account amministratore principale: <strong><?php echo htmlspecialchars($admin_conf['username'] ?? 'N/D'); ?></strong>.</li>
            <li>Salvataggio delle impostazioni del sito nel database.</li>
        </ol>
        
        <h4>Riepilogo Dati Principali:</h4>
        <div class="card mb-3"><div class="card-body" style="font-size: 0.9em; background-color: #f9f9f9;">
            <p><strong>Database:</strong> Host: <code><?php echo htmlspecialchars($db_conf['host'] ?? 'N/D'); ?></code>, Nome: <code><?php echo htmlspecialchars($db_conf['name'] ?? 'N/D'); ?></code>, Utente: <code><?php echo htmlspecialchars($db_conf['user'] ?? 'N/D'); ?></code>, Prefisso: <code><?php echo htmlspecialchars($db_conf['prefix'] ?? '(nessuno)'); ?></code></p>
            <p><strong>Sito:</strong> Nome: <code><?php echo htmlspecialchars($site_conf['site_name'] ?? 'N/D'); ?></code>, URL: <code><?php echo htmlspecialchars($site_conf['site_url'] ?? 'N/D'); ?></code></p>
            <p><strong>Admin:</strong> Username: <code><?php echo htmlspecialchars($admin_conf['username'] ?? 'N/D'); ?></code>, Email: <code><?php echo htmlspecialchars($admin_conf['email'] ?? 'N/D'); ?></code></p>
        </div></div>
        
        <?php if ($is_reinstalling): ?>
        <div class="alert alert-danger"><strong>ATTENZIONE: MODALITÀ REINSTALLAZIONE ATTIVA!</strong><br>Assicurati di aver fatto un backup se necessario, poiché i dati nelle tabelle esistenti con il prefisso specificato verranno persi.</div>
        <?php endif; ?>
        
        <form action="step5_finalize.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" method="POST" 
              onsubmit="return confirm('Confermi di voler procedere con la finalizzazione dell\'installazione? <?php if($is_reinstalling) echo "ATTENZIONE: Sei in modalità reinstallazione. I dati esistenti (tabelle con prefisso \'".htmlspecialchars($db_conf['prefix'] ?? '')."\') potrebbero essere persi!"; ?>');">
            <?php echo $reinstall_hidden_field; ?>
            <input type="hidden" name="submit_finalize_installation" value="1"> 
            <div class="nav-buttons">
                 <a href="step4_options.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" class="btn btn-nav"><i class="fas fa-arrow-left"></i> Torna Impostazioni Opzionali</a>
                <button type="submit" class="btn btn-success" style="font-size:1.1em; padding: 10px 20px;">
                    <i class="fas fa-check-circle"></i> COMPLETA INSTALLAZIONE
                </button>
            </div>
        </form>
    </div>
</body>
</html>