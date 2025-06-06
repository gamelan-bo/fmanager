<?php
// /var/www/html/fm/login.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; // functions_url.php è incluso da config.php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Per is_logged_in(), login_user(), ecc.

// Avvia la sessione se non già fatto (config.php potrebbe già farlo)
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

// BLOCCO 2: Logica di redirect se utente già loggato o gestione POST
// Questa logica DEVE venire prima di qualsiasi output HTML

// Redirect se l'utente è già loggato
if (is_logged_in()) {
    if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true) {
        header('Location: ' . generate_url('change_password', ['forced' => 1]));
        exit;
    }
    header('Location: ' . generate_url('home')); 
    exit;
}

// Gestione della richiesta POST per il login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_error_occurred = false; 

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Ricarica la pagina e riprova.";
        $_SESSION['flash_type'] = 'danger';
        $login_error_occurred = true;
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        $recaptcha_active = defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA';
        
        $proceed_after_captcha = true;
        if ($recaptcha_active) {
            if (!verify_recaptcha($recaptcha_response)) {
                $_SESSION['flash_message'] = "Verifica reCAPTCHA fallita. Per favore, riprova.";
                $_SESSION['flash_type'] = 'danger';
                $proceed_after_captcha = false;
                $login_error_occurred = true;
            }
        }
        
        if ($proceed_after_captcha) { 
            $identifier = trim($_POST['identifier'] ?? ''); 
            $password = $_POST['password'] ?? '';

            $result = login_user($identifier, $password); 

            if ($result['success']) {
                if (!isset($_SESSION['flash_message'])) { 
                     $_SESSION['flash_message'] = $result['message'] ?? "Login effettuato con successo.";
                     $_SESSION['flash_type'] = 'success';
                }

                if (isset($result['force_password_change']) && $result['force_password_change'] === true) {
                    header('Location: ' . generate_url('change_password', ['forced' => 1]));
                    exit;
                } else {
                    // $_SESSION['redirect_after_login'] conterrà l'URL "pulito" se l'utente
                    // è stato reindirizzato al login da una pagina protetta.
                    $redirect_to = $_SESSION['redirect_after_login'] ?? generate_url('home');
                    unset($_SESSION['redirect_after_login']);
                    header('Location: ' . $redirect_to); // $redirect_to dovrebbe essere già un URL completo o un path "pulito"
                    exit;
                }
            } else { 
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = 'danger';
                $login_error_occurred = true;
            }
        } else { 
            // $login_error_occurred è già true se reCAPTCHA fallisce
        }
    }
    // Se c'è stato un errore (CSRF, reCAPTCHA, login fallito) e non è stato fatto un redirect specifico,
    // ricarica la pagina di login per mostrare il flash message.
    if ($login_error_occurred) { 
        header("Location: " . generate_url('login')); 
        exit;
    }
}

// BLOCCO 3: Preparazione per la visualizzazione della pagina (se la richiesta è GET)
$page_title = "Login";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4">Login Utente</h2>
                
                <?php // I messaggi flash sono gestiti da header.php, che è stato appena incluso ?>

                <form action="<?php echo generate_url('login'); ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <div class="form-group">
                        <label for="identifier">Username o Email:</label>
                        <input type="text" class="form-control form-control-lg" id="identifier" name="identifier" required value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>" autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" class="form-control form-control-lg" id="password" name="password">
                        <small class="form-text text-muted">Se è il tuo primo accesso dopo l'approvazione e devi ancora impostare una password, potresti essere reindirizzato.</small>
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
                    <p class="mb-1"><a href="<?php echo generate_url('forgot_password'); ?>">Password dimenticata?</a></p>
                    <p>Non hai un account? <a href="<?php echo generate_url('register'); ?>">Registrati qui</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>