<?php
// /var/www/html/fm/delete_file.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_file.php'; 

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_login(); 

$fallback_redirect_url = function_exists('generate_url') ? generate_url('my_files_root') : 'my_files.php';
// Se l'azione è da un contesto admin, il fallback dovrebbe essere la lista file admin
if (isset($_POST['admin_action']) && $_POST['admin_action'] == '1' && function_exists('generate_url')) {
    $fallback_redirect_url = generate_url('admin_all_files');
}
$redirect_to_final = $fallback_redirect_url; // Inizia con il fallback

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
    } else {
        $file_id_to_delete = isset($_POST['file_id']) ? (int)$_POST['file_id'] : null;
        $acting_user_id = $_SESSION['user_id'];
        
        if ($file_id_to_delete) {
            $result = soft_delete_file($file_id_to_delete, $acting_user_id);
            $_SESSION['flash_message'] = $result['message'];
            $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
        } else {
            $_SESSION['flash_message'] = "ID file mancante o non valido per l'eliminazione.";
            $_SESSION['flash_type'] = 'danger';
        }

        if (isset($_POST['redirect_to']) && !empty($_POST['redirect_to'])) {
            $posted_redirect_url = $_POST['redirect_to'];
            // Validazione di base: assicurati che sia un URL interno al nostro sito.
            // Questo previene vulnerabilità di "Open Redirect".
            $site_host = parse_url(SITE_URL, PHP_URL_HOST);
            $redirect_host = parse_url($posted_redirect_url, PHP_URL_HOST);
            if (defined('SITE_URL') && $site_host && $redirect_host && $redirect_host === $site_host) {
                // L'host dell'URL di redirect corrisponde all'host del nostro sito, quindi è sicuro.
                $redirect_to_final = $posted_redirect_url;
            } else {
                // Se il confronto host fallisce o uno degli URL è malformato, logga l'avviso e usa il fallback
                log_warning("Tentativo di redirect non valido (host non corrisponde) in delete_file.php: " . $posted_redirect_url, __FILE__, __LINE__);
            }
        }
    }
} else {
    $_SESSION['flash_message'] = "Azione non permessa (metodo non valido).";
    $_SESSION['flash_type'] = 'danger';
    $redirect_to_final = function_exists('generate_url') ? generate_url('home') : 'index.php';
}

header("Location: " . $redirect_to_final);
exit;
?>