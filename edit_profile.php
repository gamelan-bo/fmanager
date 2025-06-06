<?php
// /var/www/html/fm/edit_profile.php

// BLOCCO 1: Inclusioni e Setup Sessione
require_once __DIR__ . '/config.php'; // Per generate_url(), SITE_URL, ecc.
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Per require_login(), request_email_change()

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_login(); // Solo utenti loggati

$user_id = $_SESSION['user_id'];

// BLOCCO 2: Gestione della richiesta di cambio email (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email_submit'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Riprova.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $new_email = trim($_POST['new_email'] ?? '');
        $current_password_for_email = $_POST['current_password_for_email'] ?? '';

        if (empty($new_email) || empty($current_password_for_email)) {
            $_SESSION['flash_message'] = "Per favore, inserisci il nuovo indirizzo email e la tua password corrente.";
            $_SESSION['flash_type'] = 'warning';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_message'] = "Il nuovo indirizzo email non è valido.";
            $_SESSION['flash_type'] = 'warning';
        } else {
            // Chiama la funzione per richiedere il cambio email
            $result = request_email_change($user_id, $new_email, $current_password_for_email);
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        }
    }
    // Reindirizza per mostrare il flash message e resettare il form
    // Usa generate_url per il redirect
    header("Location: " . (function_exists('generate_url') ? generate_url('edit_profile') : 'edit_profile.php'));
    exit;
}

// BLOCCO 3: Recupero dati per la visualizzazione (GET request)
$conn = get_db_connection();
$stmt_current_email = $conn->prepare("SELECT email, new_email_pending_validation FROM users WHERE id = ?");
$current_email_display = "N/D";
$pending_new_email_display = null;

if ($stmt_current_email) {
    $stmt_current_email->bind_param("i", $user_id);
    $stmt_current_email->execute();
    $result_current_email = $stmt_current_email->get_result();
    $user_email_data = $result_current_email->fetch_assoc();
    if ($user_email_data) {
        $current_email_display = htmlspecialchars($user_email_data['email']);
        if (!empty($user_email_data['new_email_pending_validation'])) {
            $pending_new_email_display = htmlspecialchars($user_email_data['new_email_pending_validation']);
        }
    }
    $stmt_current_email->close();
} else {
    log_error("DB Error (prepare select email in edit_profile): " . $conn->error, __FILE__, __LINE__);
    // Potresti voler mostrare un errore generico all'utente o gestire diversamente
}

// BLOCCO 4: Inizio output HTML
$page_title = "Modifica Profilo";
require_once __DIR__ . '/includes/header.php'; // header.php ora usa generate_url()
?>

<div class="row">
    <div class="col-md-8 offset-md-2">
        <h2><i class="fas fa-user-edit"></i> Modifica Profilo Utente</h2>
        <hr>

        <?php // I messaggi flash sono gestiti da header.php ?>

        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-envelope"></i> Modifica Indirizzo Email
            </div>
            <div class="card-body">
                <p>Email corrente: <strong><?php echo $current_email_display; ?></strong></p>
                <?php if ($pending_new_email_display): ?>
                    <div class="alert alert-info">
                        Hai una richiesta di cambio email in sospeso per: <strong><?php echo $pending_new_email_display; ?></strong>.
                        <br>Controlla la casella di posta di questo nuovo indirizzo per il link di validazione (potrebbe essere necessario attendere qualche minuto o controllare la cartella spam).
                        Il link di validazione scadrà a breve.
                    </div>
                <?php else: ?>
                    <form action="<?php echo function_exists('generate_url') ? generate_url('edit_profile') : 'edit_profile.php'; ?>" method="POST" novalidate>
                        <?php echo csrf_input_field(); ?>
                        <div class="form-group">
                            <label for="new_email">Nuovo Indirizzo Email:</label>
                            <input type="email" class="form-control" id="new_email" name="new_email" required>
                        </div>
                        <div class="form-group">
                            <label for="current_password_for_email">Password Corrente (per conferma):</label>
                            <input type="password" class="form-control" id="current_password_for_email" name="current_password_for_email" required>
                        </div>
                        <button type="submit" name="change_email_submit" class="btn btn-primary">Richiedi Cambio Email</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-key"></i> Modifica Password
            </div>
            <div class="card-body">
                <p>Per modificare la tua password, puoi utilizzare la pagina dedicata.</p>
                <a href="<?php echo function_exists('generate_url') ? generate_url('change_password') : 'change_password.php'; ?>" class="btn btn-warning">Vai a Modifica Password</a>
            </div>
        </div>
        
        <p class="mt-4"><a href="<?php echo function_exists('generate_url') ? generate_url('home') : 'index.php'; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Dashboard</a></p>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>