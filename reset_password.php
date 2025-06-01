<?php
// /var/www/html/fm/reset_password.php
$page_title = "Reimposta Password";

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Per verify_password_reset_token, update_user_password

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

// Se l'utente è già loggato, non ha senso che sia qui per un reset via token
if (is_logged_in() && !(isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true) ) {
    header('Location: index.php');
    exit;
}

$token = $_GET['token'] ?? null;
$user_id_from_token = null;
$username_for_form = null;
$token_is_valid_for_form = false;

if ($token) {
    $token_verification_result = verify_password_reset_token($token);
    if ($token_verification_result && isset($token_verification_result['user_id'])) {
        $user_id_from_token = $token_verification_result['user_id'];
        $username_for_form = $token_verification_result['username']; // Per un messaggio di benvenuto
        $token_is_valid_for_form = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$token_is_valid_for_form || !isset($_POST['token']) || $_POST['token'] !== $token || !$user_id_from_token) {
        // Il token originale da GET non era valido, o il token nel form è diverso/mancante.
        $_SESSION['flash_message'] = "Richiesta di reset password non valida o token manomesso.";
        $_SESSION['flash_type'] = 'danger';
        header('Location: login.php');
        exit;
    }
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Riprova.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_new_password = $_POST['confirm_new_password'] ?? '';

        if ($new_password !== $confirm_new_password) {
            $_SESSION['flash_message'] = "Le nuove password non coincidono.";
            $_SESSION['flash_type'] = 'danger';
        } elseif (strlen($new_password) < 8) {
            $_SESSION['flash_message'] = "La nuova password deve essere di almeno 8 caratteri.";
            $_SESSION['flash_type'] = 'danger';
        } else {
            // Token è già stato verificato per caricare il form, user_id_from_token è disponibile
            $result = update_user_password($user_id_from_token, $new_password); // Questa funzione pulisce tutti i token
            
            if ($result['success']) {
                $_SESSION['flash_message'] = "Password reimpostata con successo! Ora puoi accedere con la tua nuova password.";
                $_SESSION['flash_type'] = 'success';
                log_activity("Password resettata con successo per utente ID {$user_id_from_token} tramite token.");
                header('Location: login.php');
                exit;
            } else {
                $_SESSION['flash_message'] = $result['message'] ?: "Errore durante l'aggiornamento della password.";
                $_SESSION['flash_type'] = 'danger';
            }
        }
    }
    // Se ci sono errori, ricarica la pagina di reset con il token (per mostrare il form di nuovo)
    header("Location: reset_password.php?token=" . urlencode($token));
    exit;
}

// Se il token non è valido già dalla richiesta GET, reindirizza o mostra errore prima di includere header
if (!$token || !$token_is_valid_for_form) {
    if (session_status() == PHP_SESSION_NONE) session_start(); // Assicura sessione per flash message
    $_SESSION['flash_message'] = "Link di reset password non valido, scaduto o già utilizzato.";
    $_SESSION['flash_type'] = 'danger';
    if (!headers_sent()) { // Controlla se gli header sono già stati inviati
        header('Location: forgot_password.php');
        exit;
    } else {
        // Se gli header sono già stati inviati (improbabile qui), mostra un messaggio inline
        // Questo blocco è più una misura di sicurezza estrema.
        echo "<!DOCTYPE html><html><head><title>Errore</title></head><body>";
        echo "<p>Link di reset password non valido, scaduto o già utilizzato. <a href='forgot_password.php'>Richiedine un altro</a>.</p>";
        echo "</body></html>";
        exit;
    }
}

// Inizio output HTML
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4">Reimposta la Tua Password</h2>
                
                <?php // I messaggi flash sono gestiti da header.php ?>
                <p class="text-center text-muted">Ciao <?php echo htmlspecialchars($username_for_form); ?>, inserisci la tua nuova password.</p>

                <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="new_password">Nuova Password (min. 8 caratteri):</label>
                        <input type="password" class="form-control form-control-lg" id="new_password" name="new_password" required minlength="8" autofocus>
                    </div>
                    <div class="form-group">
                        <label for="confirm_new_password">Conferma Nuova Password:</label>
                        <input type="password" class="form-control form-control-lg" id="confirm_new_password" name="confirm_new_password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">Imposta Nuova Password</button>
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