<?php
// /var/www/html/fm/delete_file.php

// Inclusioni necessarie per la logica e la sessione
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   // Per require_login(), $_SESSION['user_id'], is_admin()
require_once __DIR__ . '/includes/functions_file.php'; // Per soft_delete_file()

// Avvia la sessione se non già fatto
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

require_login(); // Assicura che l'utente sia loggato

$redirect_to = 'my_files.php'; // Default redirect

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $file_id_to_delete = isset($_POST['file_id']) ? (int)$_POST['file_id'] : null;
        $acting_user_id = $_SESSION['user_id'];
        // $is_system_action è false perché questa è un'azione utente/admin, non cron
        
        if ($file_id_to_delete) {
            // La funzione soft_delete_file ha al suo interno il controllo dei permessi (proprietario o admin)
            $result = soft_delete_file($file_id_to_delete, $acting_user_id, false);
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        } else {
            $_SESSION['flash_message'] = "ID file mancante o non valido per l'eliminazione.";
            $_SESSION['flash_type'] = 'danger';
        }
    }
    // Gestisci il reindirizzamento specificato dal form, se presente e valido
    if (isset($_POST['redirect_to']) && !empty($_POST['redirect_to'])) {
        // Semplice validazione per evitare open redirect con URL esterni
        // Permetti solo redirect a script noti all'interno dell'applicazione.
        $allowed_redirect_bases = ['my_files.php', 'admin_files.php'];
        $redirect_base = basename(parse_url($_POST['redirect_to'], PHP_URL_PATH));
        if (in_array($redirect_base, $allowed_redirect_bases)) {
            $redirect_to = $_POST['redirect_to'];
        } else {
            // Fallback se redirect_to non è sicuro
            log_warning("Tentativo di redirect non valido in delete_file.php: " . $_POST['redirect_to']);
            $redirect_to = 'my_files.php'; 
        }
    }
} else {
    // Se si accede a questo script via GET, è un errore o un tentativo malevolo
    $_SESSION['flash_message'] = "Azione non permessa (metodo di richiesta non valido).";
    $_SESSION['flash_type'] = 'danger';
    $redirect_to = 'index.php'; // Reindirizza alla pagina principale o a un errore generico
}

header("Location: " . $redirect_to);
exit;
// Nessun output HTML qui
?>