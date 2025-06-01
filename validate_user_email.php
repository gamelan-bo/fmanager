<?php
// /var/www/html/fm/validate_user_email.php
$page_title = "Validazione Email";

// È importante includere config.php per primo se contiene define() usate da altri include
require_once __DIR__ . '/config.php'; 
// header.php avvia la sessione e include altre dipendenze come functions_settings
require_once __DIR__ . '/includes/header.php'; 
require_once __DIR__ . '/includes/functions_auth.php'; // Contiene verify_user_email_validation_token

$token = $_GET['token'] ?? '';
$validation_result_message = '';
$validation_result_type = 'danger'; // Default a errore

if (empty($token)) {
    $validation_result_message = "Token di validazione mancante o non valido.";
} else {
    $result = verify_user_email_validation_token($token);
    $validation_result_message = $result['message'];
    $validation_result_type = $result['success'] ? 'success' : 'danger';
}

// Imposta il flash message per visualizzarlo tramite header.php se si fa un redirect
// Ma per questa pagina, mostriamo il messaggio direttamente.
// Se volessi fare un redirect, faresti:
// $_SESSION['flash_message'] = $validation_result_message;
// $_SESSION['flash_type'] = $validation_result_type;
// header('Location: login.php'); // o index.php
// exit;

?>

<div class="row justify-content-center">
    <div class="col-md-8 text-center">
        <div class="card mt-4">
            <div class="card-header">
                <h4>Stato Validazione Email</h4>
            </div>
            <div class="card-body">
                <?php if ($validation_result_type === 'success'): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle fa-2x mr-2 align-middle"></i>
                        <?php echo htmlspecialchars($validation_result_message); ?>
                    </div>
                    <p>Puoi chiudere questa pagina. Sarai informato quando un amministratore approverà il tuo account.</p>
                    <p><a href="login.php" class="btn btn-primary">Vai al Login</a></p>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle fa-2x mr-2 align-middle"></i>
                        <?php echo htmlspecialchars($validation_result_message); ?>
                    </div>
                    <p>Se il link è scaduto o non valido, potrebbe essere necessario contattare il supporto o attendere.</p>
                    <p><a href="index.php" class="btn btn-secondary">Torna alla Home</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>