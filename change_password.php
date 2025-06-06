<?php
// /var/www/html/fm/change_password.php
$page_title = "Gestisci Password"; // Titolo più generico inizialmente

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; 

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

$user_id_for_password_change = null;
$username_for_display = '';
$is_forced_change = false; 
$is_initial_setup_via_token = false; 
$page_params_for_self_redirect = []; 

log_activity("[ChangePwd] Pagina caricata. Metodo: ".$_SERVER['REQUEST_METHOD']);

if (isset($_GET['action']) && $_GET['action'] === 'setup' && isset($_GET['token'])) {
    $token = $_GET['token'];
    log_activity("[ChangePwd] Rilevato action=setup con token: ".$token);
    $page_params_for_self_redirect['action'] = 'setup'; // Non serve più ripassare il token, la sessione guiderà
    
    $user_data_from_token = verify_initial_password_setup_token($token);

    if ($user_data_from_token) {
        log_activity("[ChangePwd] Token valido per UserID: ".$user_data_from_token['id']);
        if (session_status() == PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user_id'] = $user_data_from_token['id'];
        $_SESSION['username'] = $user_data_from_token['username'];
        $_SESSION['user_role'] = $user_data_from_token['role']; 
        $_SESSION['force_password_change'] = true;    
        $_SESSION['is_setting_initial_password'] = true; 
        $_SESSION['initiated'] = true; $_SESSION['last_activity'] = time(); 

        $user_id_for_password_change = $user_data_from_token['id'];
        $username_for_display = $user_data_from_token['username'];
        $is_forced_change = true; $is_initial_setup_via_token = true;
        
        // Non impostare flash qui, il messaggio di benvenuto sarà nel corpo
    } else {
        log_warning("[ChangePwd] Token setup non valido o scaduto: ".$token);
        $_SESSION['flash_message'] = "Link per l'impostazione della password non valido, scaduto o già utilizzato. Prova ad accedere tramite la pagina di login.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . generate_url('login'));
        exit;
    }
} else {
    log_activity("[ChangePwd] Nessun action=setup o token in GET. Chiamo require_login().");
    require_login(); // Gestisce redirect a se stesso se force_password_change è true
    $user_id_for_password_change = $_SESSION['user_id'];
    $username_for_display = $_SESSION['username'];
    $is_forced_change = (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true);
    if ($is_forced_change && isset($_GET['forced'])) { 
        $page_params_for_self_redirect['forced'] = '1';
    }
    log_activity("[ChangePwd] Dopo require_login. UserID: {$user_id_for_password_change}, Forced: ".($is_forced_change?'Sì':'No'));
}

// Gestione POST per il cambio/impostazione password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    log_activity("[ChangePwd POST] Richiesta POST ricevuta.");
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Riprova.";
        $_SESSION['flash_type'] = 'danger';
        log_error("[ChangePwd POST] Errore CSRF.");
    } else {
        log_activity("[ChangePwd POST] CSRF OK.");
        if (!isset($_SESSION['user_id'])) { 
             $_SESSION['flash_message'] = "Sessione non valida o scaduta. Effettua nuovamente il login.";
             $_SESSION['flash_type'] = 'danger';
             log_error("[ChangePwd POST] UserID non in sessione.");
             header('Location: ' . generate_url('login')); exit;
        }
        $user_id_to_update = $_SESSION['user_id']; 
        log_activity("[ChangePwd POST] UserID da aggiornare: {$user_id_to_update}");

        $current_password_input = $_POST['current_password'] ?? null;
        $new_password = $_POST['new_password'] ?? '';
        $confirm_new_password = $_POST['confirm_new_password'] ?? '';
        $proceed_with_update = false;

        log_activity("[ChangePwd POST] Nuova Pwd: " . (empty($new_password)?'VUOTA':(strlen($new_password)." caratteri")) . ", Conferma: " . (empty($confirm_new_password)?'VUOTA':(strlen($confirm_new_password)." caratteri")));
        log_activity("[ChangePwd POST] Check flags: is_forced_change: ".($is_forced_change?'Sì':'No').", is_initial_setup_via_token: ".($is_initial_setup_via_token?'Sì':'No').", SESSION[is_setting_initial_password]: ".( (isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'] === true) ?'Sì':'No') );


        if ($new_password !== $confirm_new_password) {
            $_SESSION['flash_message'] = "Le nuove password non coincidono.";
            $_SESSION['flash_type'] = 'danger';
            log_warning("[ChangePwd POST] Nuove password non coincidono per UserID: {$user_id_to_update}");
        } elseif (strlen($new_password) < 8) {
            $_SESSION['flash_message'] = "La nuova password deve essere di almeno 8 caratteri.";
            $_SESSION['flash_type'] = 'danger';
            log_warning("[ChangePwd POST] Nuova password troppo corta per UserID: {$user_id_to_update}");
        } else {
            log_activity("[ChangePwd POST] Validazione base nuova password OK.");
            // Determina se chiedere la password corrente
            $should_check_current_password = true;
            if ($is_forced_change || $is_initial_setup_via_token || (isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'] === true)) {
                $should_check_current_password = false;
                log_activity("[ChangePwd POST] Cambio forzato o setup iniziale, non controllo password corrente.");
            }

            if (!$should_check_current_password) {
                $proceed_with_update = true;
            } else { 
                log_activity("[ChangePwd POST] Controllo password corrente per UserID: {$user_id_to_update}");
                if (empty($current_password_input)) {
                    $_SESSION['flash_message'] = "Password corrente richiesta per il cambio.";
                    $_SESSION['flash_type'] = 'danger';
                    log_warning("[ChangePwd POST] Password corrente mancante per UserID: {$user_id_to_update}");
                } else {
                    $conn_pwd_check = get_db_connection();
                    $stmt_pwd = $conn_pwd_check->prepare("SELECT password_hash FROM users WHERE id = ?");
                    if ($stmt_pwd) {
                        $stmt_pwd->bind_param("i", $user_id_to_update);
                        $stmt_pwd->execute();
                        $user_pwd_data = $stmt_pwd->get_result()->fetch_assoc();
                        $stmt_pwd->close();
                        if ($user_pwd_data && password_verify($current_password_input, $user_pwd_data['password_hash'])) {
                            log_activity("[ChangePwd POST] Password corrente VERIFICATA per UserID: {$user_id_to_update}");
                            $proceed_with_update = true;
                        } else {
                            $_SESSION['flash_message'] = "Password corrente errata.";
                            $_SESSION['flash_type'] = 'danger';
                            log_warning("[ChangePwd POST] Password corrente ERRATA per UserID: {$user_id_to_update}");
                        }
                    } else { 
                        $_SESSION['flash_message'] = "Errore database durante la verifica della password.";
                        $_SESSION['flash_type'] = 'danger'; 
                        log_error("[ChangePwd POST] Errore DB prepare per verifica password corrente UserID: {$user_id_to_update}");
                    }
                }
            }

            log_activity("[ChangePwd POST] proceed_with_update = " . ($proceed_with_update ? 'true' : 'false'));
            if ($proceed_with_update) {
                log_activity("[ChangePwd POST] Chiamata a update_user_password per UserID: {$user_id_to_update}");
                $result = update_user_password($user_id_to_update, $new_password); 
                
                if ($result['success']) {
                    $_SESSION['flash_message'] = $result['message'] ?: "Password impostata/aggiornata con successo! Puoi ora utilizzare tutte le funzionalità del sito."; // Usa messaggio da funzione o uno generico
                    $_SESSION['flash_type'] = 'success';
                    log_activity("[ChangePwd POST] update_user_password ha avuto successo. Redirect a home per UserID: {$user_id_to_update}");
                    header('Location: ' . generate_url('home')); 
                    exit;
                } else {
                    $_SESSION['flash_message'] = $result['message'] ?: "Errore durante l'aggiornamento della password.";
                    $_SESSION['flash_type'] = 'danger';
                    log_error("[ChangePwd POST] update_user_password fallito per UserID: {$user_id_to_update}. Messaggio: {$result['message']}");
                }
            }
        }
    }
    // Se ci sono stati errori (CSRF, validazione password, fallimento update_user_password), 
    // ricarica la pagina di cambio password per mostrare il flash message.
    log_activity("[ChangePwd POST] Errore nel POST, redirect a se stesso. Parametri GET per redirect: " . print_r($page_params_for_self_redirect, true));
    header("Location: " . generate_url('change_password', $page_params_for_self_redirect));
    exit;
}


// BLOCCO 4: Inizio output HTML
$page_title = ($is_initial_setup_via_token || (isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'] === true)) 
                ? "Imposta la Tua Password" 
                : "Cambia Password";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="row justify-content-center mt-4">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4"><?php echo $page_title; ?></h2>
                
                <?php // I messaggi flash sono gestiti da header.php ?>

                <?php if ($is_initial_setup_via_token && $user_id_for_password_change): ?>
                    <div class="alert alert-info">Benvenuto/a <?php echo htmlspecialchars($username_for_display); ?>! Per favore, imposta la tua nuova password per completare l'attivazione del tuo account.</div>
                <?php elseif ($is_forced_change): // Questo copre anche il caso di $_SESSION['is_setting_initial_password'] se il token non è più nell'URL ?>
                     <div class="alert alert-warning">È necessario impostare una nuova password per continuare ad utilizzare il servizio.</div>
                <?php endif; ?>

                <form action="<?php echo generate_url('change_password', $page_params_for_self_redirect); ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>

                    <?php 
                    $show_current_password_field = !$is_forced_change && 
                                                 !$is_initial_setup_via_token &&
                                                 !(isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'] === true);
                    if ($show_current_password_field): 
                    ?>
                    <div class="form-group">
                        <label for="current_password">Password Corrente:</label>
                        <input type="password" class="form-control form-control-lg" id="current_password" name="current_password" required>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="new_password">Nuova Password (min. 8 caratteri):</label>
                        <input type="password" class="form-control form-control-lg" id="new_password" name="new_password" required minlength="8" autofocus>
                    </div>
                    <div class="form-group">
                        <label for="confirm_new_password">Conferma Nuova Password:</label>
                        <input type="password" class="form-control form-control-lg" id="confirm_new_password" name="confirm_new_password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                        <?php echo ($is_initial_setup_via_token || (isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'] === true)) ? 'Imposta Password e Accedi' : 'Cambia Password'; ?>
                    </button>
                </form>
                <?php if (!$is_forced_change && !$is_initial_setup_via_token): ?>
                <div class="text-center mt-3">
                    <p><a href="<?php echo generate_url('home'); ?>">Torna alla Dashboard</a></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>