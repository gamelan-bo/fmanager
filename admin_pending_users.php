 <?php
// /var/www/html/fm/admin_pending_users.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; // Per generate_url(), SITE_URL, ecc.
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Contiene get_pending_validation_users, admin_validate_user_account, ecc.

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_admin(); // Proteggi la pagina

// BLOCCO 2: Gestione della richiesta POST (approvazione/rigetto utenti)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $user_id_action = (int)$_POST['user_id'];
        $action_taken = false;

        if (isset($_POST['approve'])) {
            $assigned_role = $_POST['role'] ?? 'User';
            if ($assigned_role !== 'User' && $assigned_role !== 'Admin') {
                 $_SESSION['flash_message'] = "Ruolo specificato non valido.";
                 $_SESSION['flash_type'] = 'danger';
            } else {
                $result = admin_validate_user_account($user_id_action, $assigned_role, TRUE /* is_active_status */);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
                $action_taken = true;
            }
        } elseif (isset($_POST['reject'])) {
            $result = admin_reject_pending_user($user_id_action); 
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            $action_taken = true;
        }

        if (!$action_taken && !isset($_SESSION['flash_message'])) { 
            $_SESSION['flash_message'] = "Azione non riconosciuta.";
            $_SESSION['flash_type'] = 'warning';
        }
    }
    // Reindirizza sempre dopo un POST per mostrare il flash message e aggiornare la lista
    // Usa generate_url per il redirect
    $redirect_url = function_exists('generate_url') ? generate_url('admin_pending_users') : 'admin_pending_users.php';
    header("Location: " . $redirect_url); 
    exit; 
}

// BLOCCO 3: Recupero dati per la visualizzazione della pagina (se la richiesta è GET)
$pending_users = get_pending_validation_users(); 

// BLOCCO 4: Inizio output HTML
$page_title = "Utenti in Attesa di Validazione";
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-user-clock"></i> Utenti in Attesa di Validazione Admin</h2>
    <a href="<?php echo function_exists('generate_url') ? generate_url('admin_users_list') : 'admin_users.php'; ?>" class="btn btn-info"><i class="fas fa-users-cog"></i> Gestisci Tutti gli Utenti</a>
</div>

<?php // I messaggi flash sono ora gestiti da header.php ?>

<?php if (empty($pending_users)): ?>
    <div class="alert alert-info mt-3">Nessun utente in attesa di validazione al momento.</div>
<?php else: ?>
    <div class="table-responsive mt-3">
        <table class="table table-striped table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th class="text-center">Email Validata?</th>
                    <th>Data Registrazione</th>
                    <th>Azione da Intraprendere</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_users as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td class="text-center">
                        <?php if ($user['is_email_validated'] ?? false): ?>
                            <span class="badge badge-success p-2" style="font-size: 0.9em;"><i class="fas fa-check-circle"></i> Sì</span>
                        <?php else: ?>
                            <span class="badge badge-warning p-2" style="font-size: 0.9em;"><i class="fas fa-hourglass-half"></i> In Attesa</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d/m/Y H:i', strtotime($user['created_at'])); ?></td>
                    <td>
                        <form action="<?php echo function_exists('generate_url') ? generate_url('admin_pending_users') : 'admin_pending_users.php'; ?>" method="POST" class="d-inline-block mb-1">
                            <?php echo csrf_input_field(); ?>
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <div class="input-group input-group-sm" style="min-width: 220px;">
                                <select name="role" class="custom-select custom-select-sm">
                                    <option value="User" <?php echo (($user['role'] ?? 'User') === 'User') ? 'selected' : ''; ?>>User</option>
                                    <option value="Admin" <?php echo (($user['role'] ?? 'User') === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                                </select>
                                <div class="input-group-append">
                                    <button type="submit" name="approve" class="btn btn-success btn-sm" title="Approva e Attiva Utente">
                                        <i class="fas fa-user-check"></i> Approva
                                    </button>
                                </div>
                            </div>
                        </form>
                        <form action="<?php echo function_exists('generate_url') ? generate_url('admin_pending_users') : 'admin_pending_users.php'; ?>" method="POST" class="d-inline-block">
                            <?php echo csrf_input_field(); ?>
                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                            <button type="submit" name="reject" class="btn btn-danger btn-sm" title="Rigetta e Elimina Utente" onclick="return confirm('Sei sicuro di voler rigettare e eliminare questo utente? L\'azione è irreversibile.');">
                                <i class="fas fa-user-times"></i> Rigetta
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<p class="mt-4">
    <a href="<?php echo function_exists('generate_url') ? generate_url('admin_dashboard') : 'index.php'; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Torna alla Dashboard Admin</a>
</p>

<?php
require_once __DIR__ . '/includes/footer.php';
?>