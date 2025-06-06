<?php
// install/step2_db_config.php - Installer - Step 2: Database Configuration

if (!defined('INSTALLER_PROJECT_ROOT')) { define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); }
require_once __DIR__ . '/installer_functions.php'; 

installer_session_start(); 

if (!isset($_SESSION['installer_step']) || $_SESSION['installer_step'] < 2) {
    if (!($_SESSION['installer_step'] == 2 && isset($_GET['from_step1_ok']))) { // from_step1_ok è un esempio, non usato
        if (!($_SESSION['installer_step'] == 2 && $_SERVER['REQUEST_METHOD'] === 'GET')) {
             installer_log_warning("Accesso non valido allo Step 2. Riporto al Passo 1. Current session step: " . ($_SESSION['installer_step'] ?? 'Non impostato'));
            header('Location: index.php' . (($_SESSION['installer_reinstall_mode'] ?? false) ? '?reinstall=true' : ''));
            exit;
        }
    }
}
$_SESSION['installer_step'] = 2;
$current_step = 2;

$error_message_flash = $_SESSION['installer_error_message'] ?? null; unset($_SESSION['installer_error_message']);
$success_message_flash = $_SESSION['installer_success_message'] ?? null; unset($_SESSION['installer_success_message']);
$is_reinstalling = $_SESSION['installer_reinstall_mode'] ?? false;
$reinstall_param_php_query = $is_reinstalling ? "?reinstall=true" : "";
$reinstall_hidden_field = $is_reinstalling ? '<input type="hidden" name="reinstall_flag" value="true">' : '';

$db_config_session = $_SESSION['installer_config']['db'] ?? [];
$db_host_form = $_POST['db_host'] ?? ($db_config_session['host'] ?? 'srv-sql'); 
$db_name_form = $_POST['db_name'] ?? ($db_config_session['name'] ?? 'fm');
$db_user_form = $_POST['db_user'] ?? ($db_config_session['user'] ?? 'iz4wnp');
// $db_prefix_form non serve più, il prefisso sarà sempre vuoto

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_db_config'])) {
    $db_host = trim($_POST['db_host']);
    $db_name = trim($_POST['db_name']);
    $db_user = trim($_POST['db_user']);
    $db_pass_input = $_POST['db_pass']; 
    $db_prefix = ''; // FORZA PREFISSO VUOTO

    $db_host_form = $db_host; $db_name_form = $db_name; $db_user_form = $db_user; 

    if (empty($db_host) || empty($db_name) || empty($db_user)) {
        $_SESSION['installer_error_message'] = "Host, Nome Database e Utente Database sono campi richiesti.";
    } else {
        $conn_test = @mysqli_connect($db_host, $db_user, $db_pass_input);
        if (!$conn_test) {
            $_SESSION['installer_error_message'] = "Connessione al server MySQL fallita: " . mysqli_connect_error() . ". Verifica Host, Utente e Password.";
            installer_log_error("Test connessione DB fallito: " . mysqli_connect_error());
        } else {
            installer_log_activity("Test connessione DB a {$db_host} per utente {$db_user} riuscito.");
            if (@mysqli_select_db($conn_test, $db_name)) {
                installer_log_activity("Database '{$db_name}' esistente e selezionato con successo.");
                $_SESSION['installer_config']['db'] = [
                    'host' => $db_host, 'name' => $db_name,
                    'user' => $db_user, 'pass' => $db_pass_input,
                    'prefix' => $db_prefix // Sarà una stringa vuota
                ];
                $_SESSION['installer_step'] = 3;
                mysqli_close($conn_test);
                header("Location: step3_site_admin.php" . $reinstall_param_php_query);
                exit;
            } else {
                $mysql_error_select = mysqli_error($conn_test);
                $_SESSION['installer_error_message'] = "Impossibile selezionare il database '{$db_name}': " . $mysql_error_select . ".<br>Assicurati che il database esista e che l'utente '{$db_user}' abbia i permessi necessari su di esso. Se il database non esiste, per favore crealo manualmente e concedi i permessi all'utente, poi riprova.";
                installer_log_error("Selezione DB '{$db_name}' fallita: " . $mysql_error_select);
            }
            mysqli_close($conn_test);
        }
    }
    header("Location: step2_db_config.php" . $reinstall_param_php_query); 
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione File Manager - Passo 2: Database</title>
    <link rel="stylesheet" href="install_style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
</head>
<body>
    <div class="installer-container">
        <h1>Installazione File Manager <small style="font-size:0.5em; color:#777;">Passo 2 di 5</small></h1>
        <hr>

        <?php if ($error_message_flash): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error_message_flash)); ?></div>
        <?php endif; ?>
        <?php if ($success_message_flash): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message_flash); ?></div>
        <?php endif; ?>

        <h2>Configurazione Database</h2>
        <p>Inserisci i dettagli per la connessione al tuo database MySQL. Assicurati che il database specificato esista già e che l'utente fornito abbia i permessi necessari su di esso (per creare tabelle, leggere, scrivere, ecc.). Le tabelle verranno create senza prefisso.</p>
        
        <form action="step2_db_config.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" method="POST" novalidate>
            <?php echo $reinstall_hidden_field; ?>
            <input type="hidden" name="db_prefix" value=""> <div class="form-group">
                <label for="db_host">Host Database:</label>
                <input type="text" id="db_host" name="db_host" class="form-control" value="<?php echo htmlspecialchars($db_host_form); ?>" required>
                <small class="form-text text-muted">Es. localhost, 127.0.0.1, o l'hostname/IP del tuo server DB.</small>
            </div>
            <div class="form-group">
                <label for="db_name">Nome Database:</label>
                <input type="text" id="db_name" name="db_name" class="form-control" value="<?php echo htmlspecialchars($db_name_form); ?>" required>
                <small class="form-text text-muted">Il database deve esistere già sul server MySQL.</small>
            </div>
            <div class="form-group">
                <label for="db_user">Utente Database:</label>
                <input type="text" id="db_user" name="db_user" class="form-control" value="<?php echo htmlspecialchars($db_user_form); ?>" required>
            </div>
            <div class="form-group">
                <label for="db_pass">Password Database:</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control" value="">
            </div>
            <div class="nav-buttons">
                <a href="index.php<?php if($is_reinstalling) echo "?reinstall=true"; ?>" class="btn btn-nav"><i class="fas fa-arrow-left"></i> Torna ai Controlli</a>
                <button type="submit" name="submit_db_config" class="btn">Testa Connessione e Procedi <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>
    </div>
</body>
</html>