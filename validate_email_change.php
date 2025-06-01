<?php
// /var/www/html/fm/validate_email_change.php
$page_title = "Validazione Cambio Email";
require_once __DIR__ . '/includes/header.php'; // Gestisce sessione, config, csrf, settings
require_once __DIR__ . '/includes/functions_auth.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    $_SESSION['flash_message'] = "Token di validazione mancante o non valido.";
    $_SESSION['flash_type'] = 'danger';
} else {
    $result = verify_email_change_token($token);
    $_SESSION['flash_message'] = $result['message'];
    $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
}

// Se l'utente è loggato e il cambio email ha avuto successo,
// potrebbe essere necessario aggiornare l'email nella sessione se la memorizzi lì.
// Altrimenti, un reindirizzamento al login o alla dashboard è appropriato.
// Se il cambio email è avvenuto con successo, l'utente potrebbe voler vedere subito il cambiamento.
// Se l'utente NON è loggato quando clicca il link, reindirizzalo al login.
?>

<div class="row">
    <div class="col-md-8 offset-md-2 text-center">
        <?php if (isset($_SESSION['flash_type']) && $_SESSION['flash_type'] === 'success'): ?>
            <div class="alert alert-success mt-4">
                <h4><i class="fas fa-check-circle"></i> Validazione Completata!</h4>
                <p><?php echo htmlspecialchars($_SESSION['flash_message'] ?? 'Operazione completata.'); ?></p>
                <p>Puoi ora <a href="login.php" class="alert-link">accedere</a> con il tuo nuovo indirizzo email, se la modifica riguardava l'email di login.</p>
            </div>
        <?php elseif(isset($_SESSION['flash_type'])): // Mostra solo se flash_type è settato (per evitare il messaggio di default se non c'è token) ?>
             <div class="alert alert-danger mt-4">
                <h4><i class="fas fa-times-circle"></i> Validazione Fallita</h4>
                <p><?php echo htmlspecialchars($_SESSION['flash_message'] ?? 'Si è verificato un errore.'); ?></p>
                <p>Potrebbe essere necessario <a href="edit_profile.php" class="alert-link">richiedere nuovamente il cambio email</a> o <a href="login.php" class="alert-link">accedere</a> e riprovare.</p>
            </div>
        <?php else: // Caso in cui non c'è token e nessun flash message ancora impostato da questa pagina ?>
            <div class="alert alert-warning mt-4">
                <p>Link di validazione non specificato.</p>
            </div>
        <?php endif; ?>
        
        <p class="mt-3"><a href="index.php" class="btn btn-primary">Torna alla Home Page</a></p>
    </div>
</div>

<?php
// Il flash message è già stato impostato nella sessione, header.php lo visualizzerà se non lo facciamo qui.
// Ma per questa pagina specifica, mostriamo il messaggio direttamente nel corpo per maggiore chiarezza.
// È importante NON fare unset del flash message qui se vogliamo che header.php lo mostri nel redirect.
// Tuttavia, dato che non reindirizziamo da questa pagina subito, lo mostriamo qui.
if (isset($_SESSION['flash_message'])) {
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}
require_once __DIR__ . '/includes/footer.php';
?>