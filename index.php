<?php
// /var/www/html/fm/index.php
$page_title = "Dashboard";

// BLOCCO 1: Inclusioni e Setup Sessione
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_auth.php';
require_once __DIR__ . '/includes/functions_utils.php'; // Per format_file_size()

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_login(); // Assicura che l'utente sia loggato per accedere alla dashboard

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$user_role = $_SESSION['user_role'];

// Recupero informazioni sulla quota per l'utente loggato
$quota_info_html_for_index = "";
$conn = get_db_connection(); // Usa la connessione al DB già disponibile
$stmt_quota_idx = $conn->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?");
if ($stmt_quota_idx) {
    $stmt_quota_idx->bind_param("i", $user_id);
    $stmt_quota_idx->execute();
    $user_quota_data_idx = $stmt_quota_idx->get_result()->fetch_assoc();
    $stmt_quota_idx->close();

    if ($user_quota_data_idx) {
        $used_bytes_idx = (float)$user_quota_data_idx['used_space_bytes'];
        $total_bytes_idx = (float)$user_quota_data_idx['quota_bytes'];
        $used_mb_idx = round($used_bytes_idx / (1024*1024), 2);
        $total_mb_idx = round($total_bytes_idx / (1024*1024), 2);
        
        $percentage_used_idx = 0;
        if ($total_bytes_idx > 0) {
            $percentage_used_idx = round(($used_bytes_idx / $total_bytes_idx) * 100, 1);
        } elseif ($used_bytes_idx > 0 && $total_bytes_idx == 0) {
            $percentage_used_idx = 100; 
        }

        $pb_class_idx = 'bg-success';
        if ($percentage_used_idx >= 50 && $percentage_used_idx < 80) $pb_class_idx = 'bg-info';
        if ($percentage_used_idx >= 80 && $percentage_used_idx < 95) $pb_class_idx = 'bg-warning';
        if ($percentage_used_idx >= 95) $pb_class_idx = 'bg-danger';
        
        $q_text_idx = "";
        if ($total_mb_idx == 0 && $used_mb_idx > 0) $q_text_idx = "{$used_mb_idx} MB utilizzati (Quota 0 MB)";
        elseif ($total_mb_idx == 0 && $used_mb_idx == 0) $q_text_idx = "Nessuna quota impostata";
        else $q_text_idx = "{$used_mb_idx} MB / {$total_mb_idx} MB ({$percentage_used_idx}%)";
        
        $quota_info_html_for_index = "<div class='card shadow-sm h-100'><div class='card-body'><h5 class='card-title'><i class='fas fa-hdd mr-2'></i>Utilizzo Spazio</h5><p class='card-text mb-1'>{$q_text_idx}</p>";
        if ($total_mb_idx > 0) $quota_info_html_for_index .="<div class='progress mt-1' style='height:12px;'><div class='progress-bar {$pb_class_idx}' role='progressbar' style='width:{$percentage_used_idx}%;font-size:0.7rem;line-height:12px;' aria-valuenow='{$percentage_used_idx}' aria-valuemin='0' aria-valuemax='100'>{$percentage_used_idx}%</div></div>";
        $quota_info_html_for_index .="</div></div>";
    } else {
        log_error("Impossibile recuperare i dati della quota per l'utente ID: {$user_id} in index.php", __FILE__, __LINE__);
    }
} else {
    log_error("Errore prepare statement per quota utente ID: {$user_id} in index.php - " . $conn->error, __FILE__, __LINE__);
}

// BLOCCO HTML
require_once __DIR__ . '/includes/header.php'; 
?>

<div class="container mt-4">
    <?php // I messaggi flash sono gestiti da header.php ?>

    <div class="row mb-4">
        <div class="col-md-7"> <?php // Colonna più larga per il benvenuto ?>
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h3 class="card-title">Benvenuto/a, <?php echo htmlspecialchars($username); ?>!</h3>
                    <p class="card-text">Il tuo ruolo è: <strong><?php echo htmlspecialchars($user_role); ?></strong>.</p>
                    <?php if (isset($_SESSION['last_login_at']) && !empty($_SESSION['last_login_at']) && $_SESSION['last_login_at'] !== 'Mai'): // 'Mai' non dovrebbe essere in sessione, ma per sicurezza ?>
                        <p class="text-muted small">Ultimo accesso registrato: <?php echo date('d/m/Y H:i', strtotime($_SESSION['last_login_at'])); ?></p>
                    <?php elseif (isset($user_data_idx['last_login_at']) && $user_data_idx['last_login_at']): // Fallback se non in sessione ma nel DB ?>
                        <p class="text-muted small">Ultimo accesso registrato: <?php echo date('d/m/Y H:i', strtotime($user_data_idx['last_login_at'])); ?></p>
                    <?php endif; ?>
                    <hr>
                    <p>Da questa dashboard puoi accedere rapidamente alle principali funzionalità:</p>
                    <ul>
                        <li><a href="my_files.php">Gestisci i tuoi file</a></li>
                        <li><a href="edit_profile.php">Modifica il tuo profilo</a></li>
                        <li><a href="change_password.php">Cambia la tua password</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-5"> <?php // Colonna per la quota ?>
            <?php echo $quota_info_html_for_index; ?>
        </div>
    </div>


    <?php if (is_admin()): ?>
    <div class="mt-4 p-4 bg-light border rounded shadow-sm">
        <h4><i class="fas fa-user-shield"></i> Pannello Amministrativo</h4>
        <p>Come amministratore, hai accesso a funzionalità aggiuntive:</p>
        <div class="list-group">
            <a href="admin_pending_users.php" class="list-group-item list-group-item-action"><i class="fas fa-user-check fa-fw mr-2"></i>Valida Utenti in Attesa</a>
            <a href="admin_users.php" class="list-group-item list-group-item-action"><i class="fas fa-users-cog fa-fw mr-2"></i>Gestione Utenti Registrati</a>
            <a href="admin_folders.php" class="list-group-item list-group-item-action"><i class="fas fa-folder-open fa-fw mr-2"></i>Gestione Cartelle</a>
            <a href="admin_files.php" class="list-group-item list-group-item-action"><i class="fas fa-archive fa-fw mr-2"></i>Gestione Globale File</a>
            <a href="admin_settings.php" class="list-group-item list-group-item-action"><i class="fas fa-cogs fa-fw mr-2"></i>Impostazioni del Sito</a>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>