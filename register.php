<?php
// /var/www/html/fm/register.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; // functions_url.php è incluso da config.php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Per is_logged_in(), register_user(), ecc.

// Avvia la sessione se non già fatto (config.php potrebbe già farlo)
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

// BLOCCO 2: Logica di redirect se utente già loggato o gestione POST
// Questa logica DEVE venire prima di qualsiasi output HTML

// Redirect se l'utente è già loggato
if (is_logged_in()) {
    header('Location: ' . generate_url('home')); // Usa la rotta 'home' definita in functions_url.php
    exit;
}

// Gestione della richiesta POST per la registrazione
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registration_error_occurred = false;

    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Ricarica la pagina e riprova.";
        $_SESSION['flash_type'] = 'danger';
        $registration_error_occurred = true;
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        $recaptcha_active = defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA';
        
        $proceed_after_captcha = true;
        if ($recaptcha_active) {
            if (!verify_recaptcha($recaptcha_response)) {
                $_SESSION['flash_message'] = "Verifica reCAPTCHA fallita. Per favore, riprova.";
                $_SESSION['flash_type'] = 'danger';
                $proceed_after_captcha = false;
                $registration_error_occurred = true;
            }
        }
        
        if ($proceed_after_captcha) { 
            $username = trim($_POST['username'] ?? ''); 
            $email = trim($_POST['email'] ?? '');
            // La password non viene più passata a register_user in questa fase
            $result = register_user($username, $email); 

            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'info'; // Info o success per il messaggio di controllo email

            if ($result['success']) {
                // Dopo la registrazione, l'utente deve validare l'email e poi essere approvato.
                // Reindirizzalo alla pagina di login con un messaggio informativo.
                $_SESSION['flash_message'] = $result['message']; // Messaggio da register_user
                $_SESSION['flash_type'] = 'success';
                header('Location: ' . generate_url('login')); 
                exit;
            } else {
                // Errore di registrazione (es. username/email già in uso, input non validi)
                $registration_error_occurred = true;
            }
        } else { 
            $registration_error_occurred = true; // Errore reCAPTCHA
        }
    }
    // Se c'è stato un errore (CSRF, reCAPTCHA, errore di validazione da register_user) 
    // e non è stato fatto un redirect specifico, ricarica la pagina di registrazione 
    // per mostrare il flash message e preservare i dati del form (se lo facesse).
    if ($registration_error_occurred) { 
        header("Location: " . generate_url('register')); 
        exit;
    }
}

// BLOCCO 3: Preparazione per la visualizzazione della pagina (se la richiesta è GET)
$page_title = "Registrazione Nuovo Utente";
require_once __DIR__ . '/includes/header.php'; // Ora header.php è incluso DOPO la logica POST e i redirect
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4">Registra un Nuovo Account</h2>
                
                <?php // I messaggi flash sono gestiti da header.php ?>

                <form action="<?php echo generate_url('register'); ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" class="form-control form-control-lg" id="username" name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required minlength="3" maxlength="50">
                        <small class="form-text text-muted">Minimo 3 caratteri. Solo lettere, numeri e underscore (_).</small>
                    </div>
                    <div class="form-group">
                        <label for="email">Indirizzo Email:</label>
                        <input type="email" class="form-control form-control-lg" id="email" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                    
                    <?php 
                    // La password non viene chiesta qui
                    ?>

                    <?php 
                    if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA'): ?>
                    <div class="form-group d-flex justify-content-center my-3">
                        <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">Registrati</button>
                </form>
                <div class="text-center mt-3">
                    <p>Hai già un account? <a href="<?php echo generate_url('login'); ?>">Accedi qui</a>.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>