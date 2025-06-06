<?php
// /var/www/html/fm/reset_password.php
$page_title = "Reimposta Password";

// BLOCCO 1: Inclusioni e Setup Sessione
require_once __DIR__ . '/config.php'; // Per generate_url() e altre costanti
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Per verify_password_reset_token, update_user_password

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

// Se l'utente è già loggato E non è in un flusso di cambio password forzato, 
// non dovrebbe essere qui per un reset via token.
if (is_logged_in() && !(isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true) ) {
    header('Location: ' . generate_url('home'));
    exit;
}

$token_from_url = $_GET['token'] ?? null;
$user_id_from_token = null;
$username_for_form = null;
$token_is_valid_for_form_display = false; // Flag per mostrare il form

// BLOCCO 2: Verifica Token (per richiesta GET) e Gestione POST
if ($token_from_url) {
    $token_verification_result = verify_password_reset_token($token_from_url);
    if ($token_verification_result && isset($token_verification_result['user_id'])) {
        $user_id_from_token = $token_verification_result['user_id'];
        $username_for_form = $token_verification_result['username'];
        $token_is_valid_for_form_display = true; // Token valido, possiamo mostrare il form
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recupera il token dal campo hidden del form POST, non da GET per il POST
    $token_from_post = $_POST['token'] ?? '';
    $user_id_for_update = null;
    $proceed_with_password_update = false;

    if (empty($token_from_post)) {
        $_SESSION['flash_message'] = "Richiesta di reset password non valida (token mancante nel form).";
        $_SESSION['flash_type'] = 'danger';
    } elseif (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Riprova.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        // Riconvalida il token inviato con il form
        $token_post_verification_result = verify_password_reset_token($token_from_post);
        if ($token_post_verification_result && isset($token_post_verification_result['user_id'])) {
            $user_id_for_update = $token_post_verification_result['user_id'];
            $proceed_with_password_update = true;
        } else {
            $_SESSION['flash_message'] = "Token di reset password non valido o scaduto. Riprova a richiedere un nuovo link.";
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . generate_url('forgot_password')); // Reindirizza se il token POST è cattivo
            exit;
        }

        if ($proceed_with_password_update) {
            $new_password = $_POST['new_password'] ?? '';
            $confirm_new_password = $_POST['confirm_new_password'] ?? '';

            if ($new_password !== $confirm_new_password) {
                $_SESSION['flash_message'] = "Le nuove password non coincidono.";
                $_SESSION['flash_type'] = 'danger';
            } elseif (strlen($new_password) < 8) {
                $_SESSION['flash_message'] = "La nuova password deve essere di almeno 8 caratteri.";
                $_SESSION['flash_type'] = 'danger';
            } else {
                // user_id_for_update è stato validato dal token
                $result = update_user_password($user_id_for_update, $new_password); 
                
                if ($result['success']) {
                    $_SESSION['flash_message'] = "Password reimpostata con successo! Ora puoi accedere con la tua nuova password.";
                    $_SESSION['flash_type'] = 'success';
                    log_activity("Password resettata con successo per utente ID {$user_id_for_update} tramite token reset.");
                    header('Location: ' . generate_url('login'));
                    exit;
                } else {
                    $_SESSION['flash_message'] = $result['message'] ?: "Errore durante l'aggiornamento della password.";
                    $_SESSION['flash_type'] = 'danger';
                }
            }
        }
    }
    // Se ci sono stati errori (CSRF, password non corrispondenti, ecc.) e non è un problema di token già gestito,
    // ricarica la pagina di reset con il token originale (da GET) per mostrare il form di nuovo.
    // Il token nel form POST è $token_from_post. Il token originale da URL è $token_from_url.
    // È meglio usare quello che era valido per caricare la pagina, ovvero $token_from_url (o $token se erano uguali).
    header("Location: " . generate_url('reset_password', ['token' => $token_from_url])); // Usa il token originale dalla URL
    exit;
}


// BLOCCO 3: Controlli per la visualizzazione GET e inizio output HTML
if (!$token_from_url || !$token_is_valid_for_form_display) {
    // Se si arriva qui con una richiesta GET e il token non è valido,
    // mostra messaggio e link per richiedere un nuovo token.
    if (session_status() == PHP_SESSION_NONE) session_start(); // Assicura sessione per flash message
    $_SESSION['flash_message'] = "Link di reset password non valido, scaduto o già utilizzato.";
    $_SESSION['flash_type'] = 'danger';
    if (!headers_sent()) {
        header('Location: ' . generate_url('forgot_password'));
        exit;
    } else {
        // Questo blocco è un fallback estremo se gli header sono già inviati
        // (dovrebbe essere gestito dalla struttura corretta con logica PHP in alto)
        $page_title_error = "Errore Link Reset";
        include __DIR__ . '/includes/header.php'; // Includi header per struttura minima
        echo '<div class="container mt-4"><div class="alert alert-danger">Link di reset password non valido, scaduto o già utilizzato. Per favore, <a href="' . generate_url('forgot_password') . '">richiedine uno nuovo</a>.</div></div>';
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

// Se il token è valido, si procede a mostrare il form
$page_title = "Reimposta Password";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h2 class="text-center mb-4">Reimposta la Tua Password</h2>
                
                <?php // I messaggi flash sono gestiti da header.php ?>
                <p class="text-center text-muted">Ciao <?php echo htmlspecialchars($username_for_form); ?>, inserisci la tua nuova password qui sotto.</p>

                <form action="<?php echo generate_url('reset_password', ['token' => $token_from_url]); ?>" method="POST" novalidate>
                    <?php echo csrf_input_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token_from_url); ?>">
                    
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
                    <p><a href="<?php echo generate_url('login'); ?>">Torna al Login</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>