<?php
// /var/www/html/fm/validate_user_email.php

require_once __DIR__ . '/config.php'; // Per generate_url() e altre costanti
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Contiene verify_user_email_validation_token()

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

$token = $_GET['token'] ?? null;
$message = '';
$message_type = 'danger'; // Default a errore

if ($token) {
    $result = verify_user_email_validation_token($token); // Questa funzione è in functions_auth.php
    if ($result['success']) {
        $message = $result['message'] ?: "Email validata con successo! Il tuo account è ora in attesa di approvazione da parte di un amministratore.";
        $message_type = 'success';
    } else {
        $message = $result['message'] ?: "Link di validazione non valido, scaduto o già utilizzato. Per favore, contatta il supporto se ritieni sia un errore.";
        // $message_type rimane 'danger'
    }
} else {
    $message = "Token di validazione mancante o richiesta non valida.";
    // $message_type rimane 'danger'
}

$_SESSION['flash_message'] = $message;
$_SESSION['flash_type'] = $message_type;

// Reindirizza sempre alla pagina di login
$login_url = 'login.php'; // Fallback
if (function_exists('generate_url')) {
    $login_url = generate_url('login');
}
header("Location: " . $login_url);
exit;
// Questo script non dovrebbe produrre output HTML diretto.
?>