<?php
// /var/www/html/fm/login.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
// Includi config.php per primo se header.php non lo fa o se servono costanti prima
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php'; // Necessario se functions_auth lo usa direttamente
require_once __DIR__ . '/includes/functions_csrf.php';   // Per verify_csrf_token()
require_once __DIR__ . '/includes/functions_auth.php'; // Per is_logged_in(), login_user(), ecc.

// Avvia la sessione se non già fatto (config.php potrebbe già farlo)
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    }
    session_start();
}

// BLOCCO 2: Logica di redirect se utente già loggato o gestione POST
// Questa logica DEVE venire prima di qualsiasi output HTML (cioè prima di includere header.php)

// Redirect se l'utente è già loggato
if (is_logged_in()) {
    if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true) {
        header('Location: change_password.php?forced=1');
        exit;
    }
    header('Location: index.php'); // Riga 14 circa, o comunque una delle header()
    exit;
}

// Gestione della richiesta POST per il login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_action_processed = false; // Flag per gestire il redirect finale

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Ricarica la pagina e riprova.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        $recaptcha_active = defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA';
        
        $proceed_after_captcha = true;
        if ($recaptcha_active) {
            if (!verify_recaptcha($recaptcha_response)) {
                $_SESSION['flash_message'] = "Verifica reCAPTCHA fallita. Per favore, riprova.";
                $_SESSION['flash_type'] = 'danger';
                $proceed_after_captcha = false;
            }
        }
        
        if ($proceed_after_captcha) { 
            $identifier = trim($_POST['identifier'] ?? ''); 
            $password = $_POST['password'] ?? ''; // login_user gestirà il caso di password non necessaria per primo accesso

            $result = login_user($identifier, $password); 

            if ($result['success']) {
                // Non impostare flash qui se login_user lo fa, altrimenti fallo
                if (!isset($_SESSION['flash_message'])) { // Imposta solo se non già fatto da login_user
                     $_SESSION['flash_message'] = $result['message'] ?? "Login effettuato con successo.";
                     $_SESSION['flash_type'] = 'success';
                }

                if (isset($result['force_password_change']) && $result['force_password_change'] === true) {
                    header('Location: change_password.php?forced=1');
                    exit;
                } else {
                    $redirect_to = $_SESSION['redirect_after_login'] ?? 'index.php';
                    unset($_SESSION['redirect_after_login']);
                    header('Location: ' . $redirect_to);
                    exit;
                }
            } else { // Login fallito
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    // Se c'è stato un errore (CSRF, reCAPTCHA, login fallito) e non è stato fatto un redirect specifico,
    // ricarica la pagina per mostrare il flash message.
    header("Location: login.php");
    exit;
}

// BLOCCO 3: Preparazione per la visualizzazione della pagina (se la richiesta è GET)
$page_title = "Login";
require_once __DIR__ . '/includes/header.php'; // Ora header.php viene incluso DOPO la logica POST e i redirect
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4">Login Utente</h2>
                
                <?php // I messaggi flash sono gestiti da header.php, che è stato appena incluso ?>

                <form action="login.php" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <div class="form-group">
                        <label for="identifier">Username o Email:</label>
                        <input type="text" class="form-control form-control-lg" id="identifier" name="identifier" required value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" class="form-control form-control-lg" id="password" name="password">
                        <small class="form-text text-muted">Se è il tuo primo accesso dopo l'approvazione, devi settare la password attraverso il link inviato via mail</small>
                    </div>

                    <?php 
                    if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA'): ?>
                    <div class="form-group d-flex justify-content-center my-3">
                        <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">Accedi</button>
                </form>
                <div class="text-center mt-3">
                    <p class="mb-1"><a href="forgot_password.php">Password dimenticata?</a></p>
                    <p>Non hai un account? <a href="register.php">Registrati qui</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>