<?php
// includes/functions_csrf.php

if (session_status() == PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token_to_check) {
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token_to_check)) {
        // Il token è valido, si può invalidare per il prossimo utilizzo se si desidera un token one-time per form
        // unset($_SESSION['csrf_token']); // Opzionale: per token one-time
        return true;
    }
    log_activity("Tentativo CSRF fallito.");
    return false;
}

function csrf_input_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}
?>