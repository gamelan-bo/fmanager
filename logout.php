<?php
// logout.php
require_once __DIR__ . '/config.php'; // Per SESSION_NAME e funzioni di log
if (session_status() == PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

$username_logout = $_SESSION['username'] ?? 'UtenteSconosciuto';
$user_id_logout = $_SESSION['user_id'] ?? null;

// Rimuovi tutte le variabili di sessione
$_SESSION = array();

// Cancella il cookie di sessione
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Infine, distruggi la sessione.
session_destroy();

log_activity("Logout effettuato da: {$username_logout}", $user_id_logout);

// Imposta il flash message DOPO aver distrutto e ricreato una sessione "pulita" per il messaggio
// O, più semplicemente, passa il messaggio via GET poiché la sessione è andata
// Tuttavia, per coerenza con il sistema di flash, potremmo avviarne una nuova solo per il messaggio
session_name(SESSION_NAME); // Riavvia per il flash message
session_start();
$_SESSION['flash_message'] = 'Logout effettuato con successo.';
$_SESSION['flash_type'] = 'success';


header('Location: login.php');
exit;
?>