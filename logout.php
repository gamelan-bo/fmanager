<?php
// /var/www/html/fm/logout.php

require_once __DIR__ . '/config.php'; // Per generate_url() e SESSION_NAME

// Avvia la sessione per poterla distruggere correttamente
if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) {
        session_name(SESSION_NAME);
    }
    session_start();
}

// Salva un messaggio flash per la pagina di login, se vuoi
// $_SESSION['flash_message'] = "Logout effettuato con successo.";
// $_SESSION['flash_type'] = 'success'; 
// Nota: se fai unset e destroy subito dopo, il flash message non verrà mantenuto
// a meno che non lo imposti *dopo* aver riavviato la sessione se necessario, o lo passi in GET.
// Per semplicità, spesso il logout non mostra un messaggio flash diretto sulla pagina di login.

// Cancella tutte le variabili di sessione.
$_SESSION = array();

// Se si desidera distruggere completamente la sessione, cancellare anche il cookie di sessione.
// Nota: Questo distruggerà la sessione e non solo i dati di sessione!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Infine, distruggi la sessione.
session_destroy();

// Reindirizza alla pagina di login utilizzando l'URL "pulito"
// Assicurati che 'login' sia una rotta definita in functions_url.php
$login_url = 'login.php'; // Fallback
if (function_exists('generate_url')) {
    $login_url = generate_url('login');
}

header("Location: " . $login_url);
exit;
?>