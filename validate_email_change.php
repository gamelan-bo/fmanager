<?php
// /var/www/html/fm/validate_email_change.php

// Inclusioni necessarie
require_once __DIR__ . '/config.php'; // Per generate_url() e altre costanti
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_auth.php'; // Contiene verify_email_change_token()

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

$token = $_GET['token'] ?? null;
$message = '';
$message_type = 'danger'; // Default a errore
$redirect_url_key = 'login'; // Default redirect a login in caso di errore grave o token mancante

if ($token) {
    $result = verify_email_change_token($token); // Questa funzione è in functions_auth.php
    
    if ($result['success']) {
        $message = $result['message'] ?: "Indirizzo email aggiornato con successo!";
        $message_type = 'success';
        // Se l'utente è loggato, reindirizza al profilo, altrimenti al login
        // (L'utente dovrebbe essere loggato per aver richiesto il cambio email,
        // ma il link di conferma potrebbe essere cliccato da una sessione diversa o scaduta)
        if (is_logged_in()) {
            $redirect_url_key = 'edit_profile';
        } else {
            $redirect_url_key = 'login';
        }
    } else {
        $message = $result['message'] ?: "Link per il cambio email non valido, scaduto o già utilizzato. Per favore, riprova la procedura di cambio email se necessario.";
        // $message_type rimane 'danger'
        // Se il token non è valido, reindirizza alla pagina del profilo se l'utente è loggato (così può riprovare),
        // altrimenti al login.
        if (is_logged_in()) {
            $redirect_url_key = 'edit_profile'; 
        } else {
            $redirect_url_key = 'login';
        }
    }
} else {
    $message = "Token per il cambio email mancante o richiesta non valida.";
    // $message_type rimane 'danger'
    // Se non c'è token, reindirizza al login o alla home
    $redirect_url_key = is_logged_in() ? 'home' : 'login';
}

$_SESSION['flash_message'] = $message;
$_SESSION['flash_type'] = $message_type;

// Esegui il redirect usando generate_url()
$final_redirect_url = 'index.php'; // Fallback estremo
if (function_exists('generate_url')) {
    $final_redirect_url = generate_url($redirect_url_key);
} else { // Fallback se generate_url non è disponibile
    if ($redirect_url_key === 'edit_profile') $final_redirect_url = 'edit_profile.php';
    elseif ($redirect_url_key === 'login') $final_redirect_url = 'login.php';
    else $final_redirect_url = 'index.php';
}

header("Location: " . $final_redirect_url);
exit;
// Questo script non dovrebbe produrre output HTML diretto.
?>