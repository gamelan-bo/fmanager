<?php
// /var/www/html/fm/register.php
$page_title = "Registrazione";
require_once __DIR__ . '/includes/header.php'; 
require_once __DIR__ . '/includes/functions_auth.php'; 

if (is_logged_in()) {
    header('Location: index.php'); 
    exit;
}

$registration_message_display = ''; // Per il messaggio di successo finale da mostrare sotto il titolo
$form_error_message = ''; // Per errori specifici del form

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registration_action_taken = true; // Indica che un tentativo di POST è stato fatto
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Ricarica la pagina e riprova.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        // Controlla reCAPTCHA solo se è configurato attivamente (non solo definito, ma con una chiave reale)
        if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA') {
            if (!verify_recaptcha($recaptcha_response)) {
                 $_SESSION['flash_message'] = "Verifica reCAPTCHA fallita. Per favore, riprova.";
                 $_SESSION['flash_type'] = 'danger';
            }
        }
        
        if (!isset($_SESSION['flash_message'])) { // Prosegui solo se non ci sono errori precedenti
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');

            // Chiamiamo register_user passando null per la password,
            // la funzione è stata aggiornata per gestire questo.
            $result = register_user($username, $email, null); 

            if ($result['success']) {
                // Messaggio di successo specifico per questa pagina, non usare flash per questo
                $registration_message_display = $result['message'] . "<br>Riceverai un'email per validare il tuo indirizzo. Successivamente, un amministratore dovrà approvare il tuo account. Una volta approvato, al tuo primo login ti verrà chiesto di impostare una password.";
                // Non fare redirect qui, mostra il messaggio di successo e nascondi il form
            } else {
                // Errore specifico del form, non usare flash se vogliamo che l'utente corregga i dati
                $form_error_message = $result['message'];
            }
        }
    }
    // Se c'è stato un errore flash (CSRF, reCAPTCHA) e non un errore di form, fai redirect
    if (isset($_SESSION['flash_message']) && empty($form_error_message) && empty($registration_message_display)) {
        header("Location: register.php"); 
        exit;
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <h2>Registrati</h2>

        <?php 
        // Visualizza il messaggio di errore del form, se presente
        if (!empty($form_error_message)): ?>
            <div class="alert alert-danger"><?php echo $form_error_message; ?></div>
        <?php endif; ?>

        <?php 
        // Visualizza il messaggio di successo specifico della registrazione, se presente
        // Questo messaggio sostituisce il form.
        if (!empty($registration_message_display)): ?>
            <div class="alert alert-success">
                <?php echo $registration_message_display; // Questo messaggio contiene già HTML se necessario ?>
            </div>
            <p><a href="login.php" class="btn btn-primary">Torna al Login</a></p>
        <?php else: 
            // Mostra il form solo se non c'è stato un successo di registrazione
            // I messaggi flash per CSRF/reCAPTCHA verranno mostrati da header.php se si fa redirect
        ?>
            <form action="register.php" method="POST" novalidate>
                <?php echo csrf_input_field(); ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required minlength="3" maxlength="50" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <?php if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA'): ?>
                <div class="form-group">
                    <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                </div>
                <?php elseif (defined('RECAPTCHA_SITE_KEY') && (RECAPTCHA_SITE_KEY === '' || RECAPTCHA_SITE_KEY === 'LA_TUA_SITE_KEY_RECAPTCHA')): ?>
                    <?php endif; ?>
                <button type="submit" class="btn btn-primary">Registrati</button>
            </form>
            <p class="mt-3">Hai già un account? <a href="login.php">Accedi qui</a>.</p>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>