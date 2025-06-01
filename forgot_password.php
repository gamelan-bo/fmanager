<?php
// /var/www/html/fm/forgot_password.php
$page_title = "Password Dimenticata";

// BLOCCO 1: Inclusioni e gestione sessione
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Per send_password_reset_link, verify_recaptcha
// functions_settings.php è incluso da functions_auth.php se necessario per get_site_setting

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

// Se l'utente è già loggato, non ha senso che sia qui
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$message_shown = false;

// BLOCCO 2: Gestione POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Riprova.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $email = trim($_POST['email'] ?? '');
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        $proceed_after_captcha = true;

        if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA') {
            if (!verify_recaptcha($recaptcha_response)) {
                $_SESSION['flash_message'] = "Verifica reCAPTCHA fallita. Riprova.";
                $_SESSION['flash_type'] = 'danger';
                $proceed_after_captcha = false;
            }
        }

        if ($proceed_after_captcha && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn = get_db_connection();
            $stmt = $conn->prepare("SELECT id, username, is_active FROM users WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user && $user['is_active']) { // Invia solo se l'utente esiste ed è attivo
                    $token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', time() + 3600); // Token valido per 1 ora

                    $stmt_update = $conn->prepare("UPDATE users SET password_reset_token = ?, password_reset_token_expires_at = ? WHERE id = ?");
                    if ($stmt_update) {
                        $stmt_update->bind_param("ssi", $token, $expires_at, $user['id']);
                        if ($stmt_update->execute()) {
                            send_password_reset_link($email, $user['username'], $token);
                            // Il messaggio di successo è generico per non rivelare se un'email esiste o meno
                        } else {
                            log_error("DB Error (update reset token): " . $stmt_update->error, __FILE__, __LINE__);
                            // Non mostrare errore specifico all'utente
                        }
                        $stmt_update->close();
                    } else {
                        log_error("DB Error (prepare update reset token): " . $conn->error, __FILE__, __LINE__);
                    }
                } else {
                    // Email non trovata o utente non attivo, non fare nulla che lo riveli
                    log_activity("Tentativo di reset password per email non trovata o utente non attivo: " . htmlspecialchars($email));
                }
                // Mostra sempre un messaggio generico per motivi di sicurezza (evitare enumerazione email)
                $_SESSION['flash_message'] = "Se un account con questa email esiste ed è attivo, riceverai un link per reimpostare la password. Controlla la tua casella di posta (anche lo spam).";
                $_SESSION['flash_type'] = 'info';
                $message_shown = true; // Per evitare di mostrare di nuovo il form

            } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                 $_SESSION['flash_message'] = "Per favore, inserisci un indirizzo email valido.";
                 $_SESSION['flash_type'] = 'danger';
            }
        }
        // Non è necessario un redirect qui se il messaggio flash viene mostrato sotto
        // Tuttavia, se $_SESSION['flash_message'] è impostato, header.php lo mostrerà.
        // Se vogliamo che il form scompaia dopo il submit, possiamo fare un redirect a se stesso
        // o usare $message_shown per nascondere il form.
        // Per ora, il flash message verrà mostrato da header.php e il form rimarrà.
        // Se si vuole nascondere il form dopo il messaggio, si aggiunge un redirect qui.
        // header("Location: forgot_password.php"); exit;
    }
    if (isset($_SESSION['flash_message'])) { // Se è stato impostato un messaggio (errore CSRF o reCAPTCHA, o il messaggio di info)
        header("Location: forgot_password.php"); // Ricarica per mostrare il flash e pulire POST
        exit;
    }
}

// BLOCCO 3: Inizio output HTML
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4">Password Dimenticata?</h2>
                
                <?php // I messaggi flash sono gestiti da header.php ?>

                <p class="text-muted text-center mb-4">Inserisci il tuo indirizzo email e ti invieremo un link per reimpostare la password.</p>
                
                <form action="forgot_password.php" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <div class="form-group">
                        <label for="email">Indirizzo Email:</label>
                        <input type="email" class="form-control form-control-lg" id="email" name="email" required 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
                    </div>

                    <?php 
                    if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA'): ?>
                    <div class="form-group d-flex justify-content-center my-3">
                        <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">Invia Link di Reset</button>
                </form>
                <div class="text-center mt-3">
                    <p><a href="login.php">Torna al Login</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>