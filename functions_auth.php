<?php
// includes/functions_auth.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/functions_csrf.php';
if (!function_exists('get_site_setting')) { 
    require_once __DIR__ . '/functions_settings.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (defined('PHPMAILER_PATH')) {
    $phpMailerPath = rtrim(PHPMAILER_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (file_exists($phpMailerPath . 'PHPMailer.php')) require_once $phpMailerPath . 'PHPMailer.php';
    if (file_exists($phpMailerPath . 'SMTP.php')) require_once $phpMailerPath . 'SMTP.php';
    if (file_exists($phpMailerPath . 'Exception.php')) require_once $phpMailerPath . 'Exception.php';
}

/**
 * Verifica la risposta di Google reCAPTCHA.
 */
function verify_recaptcha($recaptcha_response) {
    if (!defined('RECAPTCHA_SECRET_KEY') || RECAPTCHA_SECRET_KEY === '' || RECAPTCHA_SECRET_KEY === 'LA_TUA_SECRET_KEY_RECAPTCHA') return true; 
    if (empty($recaptcha_response)) return false;
    $params = ['secret' => RECAPTCHA_SECRET_KEY, 'response' => $recaptcha_response, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null];
    $options = ['http' => ['header'  => "Content-type: application/x-www-form-urlencoded\r\n", 'method'  => 'POST', 'content' => http_build_query($params), 'timeout' => 5]];
    $context  = stream_context_create($options);
    $result_json = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    if ($result_json === FALSE) { log_error("Errore comunicazione server reCAPTCHA."); return false; }
    $response_data = json_decode($result_json);
    if ($response_data && $response_data->success) return true;
    if (isset($response_data->{'error-codes'})) log_error("Errore reCAPTCHA: " . implode(', ', $response_data->{'error-codes'})); else log_error("Errore reCAPTCHA: risposta non valida.");
    return false;
}

/**
 * Registra un nuovo utente.
 */
function register_user($username, $email, $password_param_placeholder = null) {
    $conn = get_db_connection(); $errors = [];
    if (empty($username) || strlen($username) < 3 || strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) $errors[] = "Username non valido.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email non valida.";
    if (!empty($errors)) return ['success' => false, 'message' => implode("<br>", $errors)];
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    if (!$stmt_check) { log_error("DB Error (prep user check): " . $conn->error); return ['success' => false, 'message' => "Errore DB."]; }
    $stmt_check->bind_param("ss", $username, $email); $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) { $stmt_check->close(); return ['success' => false, 'message' => "Username o email già in uso."]; }
    $stmt_check->close();
    $password_hash = ''; $role = 'User'; $is_active = FALSE; $requires_admin_validation = TRUE; 
    $requires_password_change = TRUE; $is_email_validated = FALSE;
    $validation_token = bin2hex(random_bytes(32));
    $validation_token_expires_at = date('Y-m-d H:i:s', time() + (24 * 3600));
    $default_quota = defined('DEFAULT_USER_QUOTA_BYTES') ? DEFAULT_USER_QUOTA_BYTES : 1073741824; $default_used_space = 0;
    $stmt_insert = $conn->prepare("INSERT INTO users (username, email, password_hash, role, is_active, requires_admin_validation, requires_password_change, is_email_validated, validation_token, validation_token_expires_at, initial_password_setup_token, initial_password_setup_token_expires_at, quota_bytes, used_space_bytes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)");
    if (!$stmt_insert) { log_error("DB Error (prep user insert): " . $conn->error); return ['success' => false, 'message' => "Errore DB."]; }
    $stmt_insert->bind_param("ssssiiiissii", $username, $email, $password_hash, $role, $is_active, $requires_admin_validation, $requires_password_change, $is_email_validated, $validation_token, $validation_token_expires_at, $default_quota, $default_used_space);
    if ($stmt_insert->execute()) {
        $user_id = $stmt_insert->insert_id; $stmt_insert->close();
        log_activity("Nuovo utente (ID: {$user_id}, {$username}) attesa valid. email/admin, PWD da impostare.", $user_id);
        send_user_email_validation_link($email, $username, $validation_token);
        if (defined('ADMIN_NOTIFICATION_EMAIL') && filter_var(ADMIN_NOTIFICATION_EMAIL, FILTER_VALIDATE_EMAIL)) { send_admin_new_user_notification(ADMIN_NOTIFICATION_EMAIL, $username, $email); } else { log_warning("ADMIN_NOTIFICATION_EMAIL non definita o non valida."); }
        return ['success' => true, 'message' => "Registrazione quasi completata! Controlla la tua email ({$email}) per validare il tuo indirizzo. Dopo l'approvazione dell'admin, dovrai impostare una password.", 'user_id' => $user_id];
    } else { log_error("DB Error (exec user insert): " . $stmt_insert->error); $stmt_insert->close(); return ['success' => false, 'message' => "Errore DB."]; }
}

/**
 * Esegue il login dell'utente.
 */
function login_user($identifier, $password_attempt) {
    $conn = get_db_connection();
    if (empty($identifier)) return ['success' => false, 'message' => "Username/Email richiesti."];
    $stmt = $conn->prepare("SELECT id, username, password_hash, role, is_active, requires_password_change FROM users WHERE (username = ? OR email = ?) LIMIT 1");
    if (!$stmt) { log_error("DB Error (prep login): " . $conn->error); return ['success' => false, 'message' => "Errore login."]; }
    $stmt->bind_param("ss", $identifier, $identifier); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($user) {
        if (!$user['is_active']) { if ($user['password_hash'] === '' && $user['requires_password_change'] == TRUE) return ['success' => false, 'message' => "Account in attesa di approvazione. Valida anche la tua email."]; return ['success' => false, 'message' => "Account non attivo o sospeso."]; }
        if ($user['requires_password_change'] == TRUE && $user['password_hash'] === '') { session_regenerate_id(true); $_SESSION['user_id'] = $user['id']; $_SESSION['username'] = $user['username']; $_SESSION['user_role'] = $user['role']; $_SESSION['last_activity'] = time(); $_SESSION['initiated'] = true; $_SESSION['force_password_change'] = true; $_SESSION['is_setting_initial_password'] = true; log_activity("Login per impostazione PWD iniziale (via login form): {$user['username']} (ID: {$user['id']})", $user['id']); return ['success' => true, 'message' => "Benvenuto! Devi impostare una password.", 'force_password_change' => true]; }
        if (empty($password_attempt) && $user['password_hash'] !== '') return ['success' => false, 'message' => "Password richiesta."];
        if (password_verify($password_attempt, $user['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id'] = $user['id']; $_SESSION['username'] = $user['username']; $_SESSION['user_role'] = $user['role']; $_SESSION['last_activity'] = time(); $_SESSION['initiated'] = true; unset($_SESSION['is_setting_initial_password']); $conn->query("UPDATE users SET last_login_at = NOW() WHERE id = " . (int)$user['id']); log_activity("Login: {$user['username']} (ID: {$user['id']})", $user['id']); if ($user['requires_password_change'] == TRUE) { $_SESSION['force_password_change'] = true; return ['success' => true, 'message' => "Login successo. Devi cambiare password.", 'force_password_change' => true]; } return ['success' => true, 'message' => "Login effettuato con successo."]; }
    }
    log_activity("Tentativo login fallito per: {$identifier}"); return ['success' => false, 'message' => "Credenziali non valide."];
}

/**
 * Aggiorna la password di un utente e pulisce tutti i token rilevanti.
 */
function update_user_password($user_id, $new_password) {
    $conn = get_db_connection();
    if (empty($new_password) || strlen($new_password) < 8) {
        return ['success' => false, 'message' => "La nuova password deve essere di almeno 8 caratteri."];
    }
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    if ($new_password_hash === false) { 
        log_error("Errore durante l'hashing della nuova password per utente ID: {$user_id}", __FILE__, __LINE__); 
        return ['success' => false, 'message' => "Errore di sicurezza durante l'impostazione della password."]; 
    }
    
    $stmt = $conn->prepare("UPDATE users SET 
                                password_hash = ?, 
                                requires_password_change = FALSE, 
                                validation_token = NULL,                 
                                validation_token_expires_at = NULL,
                                initial_password_setup_token = NULL, 
                                initial_password_setup_token_expires_at = NULL,
                                password_reset_token = NULL,                
                                password_reset_token_expires_at = NULL      
                            WHERE id = ?");
    if (!$stmt) { 
        log_error("DB Error (prepare update_user_password): " . $conn->error, __FILE__, __LINE__); 
        return ['success' => false, 'message' => "Errore database."]; 
    }
    $stmt->bind_param("si", $new_password_hash, $user_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            log_activity("Password aggiornata/impostata e token resettati per utente ID: {$user_id}", $user_id);
        } else {
            log_activity("Tentativo di aggiornamento password per utente ID: {$user_id}, nessuna riga affetta (possibile password identica o flag già corretti, token resettati comunque se query eseguita).", $user_id);
        }
        $stmt->close(); 
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) { 
            unset($_SESSION['force_password_change']); 
            unset($_SESSION['is_setting_initial_password']); 
        } 
        return ['success' => true, 'message' => "Password aggiornata con successo."];
    } else { 
        log_error("DB Error (execute update_user_password): " . $stmt->error, __FILE__, __LINE__); 
        $stmt->close(); 
        return ['success' => false, 'message' => "Errore database durante l'aggiornamento della password."]; 
    }
}

/**
 * Gestisce la richiesta di cambio email.
 */
function request_email_change($user_id, $new_email, $current_password) {
    $conn = get_db_connection();
    $stmt_user = $conn->prepare("SELECT password_hash, email FROM users WHERE id = ?");
    if (!$stmt_user) { log_error("DB Err (req_email_change user): ".$conn->error); return ['success'=>false,'message'=>'Errore server (RECUPERO).'];}
    $stmt_user->bind_param("i", $user_id); $stmt_user->execute(); $user_data = $stmt_user->get_result()->fetch_assoc(); $stmt_user->close();
    if (!$user_data || !password_verify($current_password, $user_data['password_hash'])) return ['success' => false, 'message' => 'Password corrente errata.'];
    if (strtolower($new_email) === strtolower($user_data['email'])) return ['success' => false, 'message' => 'La nuova email è uguale a quella attuale.'];
    $stmt_email_exists = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    if (!$stmt_email_exists) { log_error("DB Err (req_email_change exists): ".$conn->error); return ['success'=>false,'message'=>'Errore server (CHECK_EMAIL).'];}
    $stmt_email_exists->bind_param("si", $new_email, $user_id); $stmt_email_exists->execute();
    if ($stmt_email_exists->get_result()->num_rows > 0) { $stmt_email_exists->close(); return ['success' => false, 'message' => 'Email già in uso da un altro account.']; }
    $stmt_email_exists->close(); $token = bin2hex(random_bytes(32)); $expires_at = date('Y-m-d H:i:s', time() + 3600);
    $stmt_update = $conn->prepare("UPDATE users SET new_email_pending_validation = ?, email_change_token = ?, email_change_token_expires_at = ? WHERE id = ?");
    if (!$stmt_update) { log_error("DB Err (req_email_change update): ".$conn->error); return ['success'=>false,'message'=>'Errore server (UPDATE_TOKEN).'];}
    $stmt_update->bind_param("sssi", $new_email, $token, $expires_at, $user_id);
    if ($stmt_update->execute()) {
        $stmt_update->close(); log_activity("Richiesta cambio email per utente ID {$user_id} al nuovo indirizzo {$new_email}", $user_id);
        $validation_link = rtrim(SITE_URL,'/')."/validate_email_change.php?token=".urlencode($token);
        $site_name = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Nostro Servizio'));
        $subject = "Conferma il tuo nuovo indirizzo email su ".$site_name;
        $body_html = "<p>Ciao,</p><p>Hai richiesto di cambiare il tuo indirizzo email su ".htmlspecialchars($site_name)." con il seguente: ".htmlspecialchars($new_email).".</p><p>Per confermare questa modifica, per favore clicca sul link qui sotto (o copialo e incollalo nel tuo browser):</p><p><a href='{$validation_link}'>{$validation_link}</a></p><p>Questo link di conferma è valido per 1 ora. Se non hai richiesto tu questa modifica, puoi ignorare questa email.</p>";
        if(function_exists('send_custom_email') && send_custom_email($new_email, $subject, $body_html)){
            return ['success'=>true, 'message'=>"Richiesta di cambio email inviata! Controlla la casella di posta di ".htmlspecialchars($new_email)." per il link di conferma."];
        } else { log_error("Fallito invio email di conferma cambio email a {$new_email} per utente ID {$user_id}"); return ['success'=>false, 'message'=>'La richiesta è stata registrata, ma si è verificato un errore nell\'invio dell\'email di conferma. Contatta il supporto.'];}
    } else { log_error("DB Err (req_email_change exec): ".$stmt_update->error); $stmt_update->close(); return ['success'=>false,'message'=>'Errore server durante l\'aggiornamento dei dati per il cambio email.'];}
}

/**
 * Verifica un token per il cambio email e finalizza.
 */
function verify_email_change_token($token) {
    $conn = get_db_connection(); $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id, email, new_email_pending_validation FROM users WHERE email_change_token = ? AND email_change_token_expires_at > ?");
    if (!$stmt) { log_error("DB Err (verify_email_tk prep): ".$conn->error); return ['success'=>false,'message'=>'Errore server.'];}
    $stmt->bind_param("ss", $token, $now); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user || empty($user['new_email_pending_validation'])) { $stmt_invalidate = $conn->prepare("UPDATE users SET email_change_token=NULL, new_email_pending_validation=NULL, email_change_token_expires_at=NULL WHERE email_change_token = ?"); if($stmt_invalidate){ $stmt_invalidate->bind_param("s", $token); $stmt_invalidate->execute(); $stmt_invalidate->close(); } return ['success' => false, 'message' => 'Link di validazione non valido, scaduto o già utilizzato.'];}
    $new_email = $user['new_email_pending_validation']; $user_id = $user['id']; $old_email = $user['email'];
    $stmt_update = $conn->prepare("UPDATE users SET email = ?, new_email_pending_validation = NULL, email_change_token = NULL, email_change_token_expires_at = NULL WHERE id = ?");
    if (!$stmt_update) { log_error("DB Err (verify_email_tk update): ".$conn->error); return ['success'=>false,'message'=>'Errore server.'];}
    $stmt_update->bind_param("si", $new_email, $user_id);
    if ($stmt_update->execute() && $stmt_update->affected_rows > 0) { log_activity("Indirizzo email cambiato con successo da {$old_email} a {$new_email} per utente ID {$user_id}", $user_id); $stmt_update->close(); return ['success' => true, 'message' => 'Indirizzo email aggiornato con successo a ' . htmlspecialchars($new_email) . '!'];}
    else { $db_error = $stmt_update->error; $stmt_update->close(); log_error("DB Err (verify_email_tk exec): ".$db_error); return ['success'=>false,'message'=>'Errore durante la finalizzazione del cambio email. Il link potrebbe essere già stato utilizzato.'];}
}

/**
 * Invia l'email di validazione all'utente appena registrato.
 */
function send_user_email_validation_link($user_email, $username, $token) {
    log_activity("[EmailDebug] Chiamata a send_user_email_validation_link per {$user_email} con token {$token}.");
    if (!function_exists('send_custom_email')) { log_error("send_custom_email non disponibile per validazione utente."); return false; }
    $validation_link = rtrim(SITE_URL, '/') . "/validate_user_email.php?token=" . urlencode($token);
    $site_name = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Nostro Servizio'));
    $subject = "Valida il tuo indirizzo email su " . $site_name;
    $body_html = "<p>Ciao " . htmlspecialchars($username) . ",</p><p>Grazie per esserti registrato su " . htmlspecialchars($site_name) . ".</p><p>Clicca sul seguente link per validare il tuo indirizzo email:</p><p><a href='{$validation_link}'>{$validation_link}</a></p><p>Questo link scadrà tra 24 ore.</p>";
    $sent = send_custom_email($user_email, $subject, $body_html);
    log_activity("[EmailDebug] Risultato send_user_email_validation_link per {$user_email}: " . ($sent ? 'Successo' : 'Fallimento'));
    return $sent;
}

/**
 * Invia email di notifica all'admin per un nuovo utente.
 */
function send_admin_new_user_notification($admin_email, $new_username, $new_user_email) {
    log_activity("[EmailDebug] Chiamata a send_admin_new_user_notification per admin {$admin_email}, nuovo utente {$new_username}.");
    if (!function_exists('send_custom_email')) { log_error("send_custom_email non disponibile per notifica admin."); return false; }
    $site_name = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Tuo Sito'));
    $subject = "[{$site_name}] Nuova Registrazione Utente in Attesa: " . $new_username;
    $body_html = "<p>Nuovo utente registrato:</p><ul><li>Username: ".htmlspecialchars($new_username)."</li><li>Email: ".htmlspecialchars($new_user_email)."</li></ul><p>Approva nel pannello admin: <a href='".rtrim(SITE_URL, '/')."/admin_pending_users.php'>Valida Utenti</a></p>";
    $sent = send_custom_email($admin_email, $subject, $body_html);
    log_activity("[EmailDebug] Risultato send_admin_new_user_notification per {$admin_email}: " . ($sent ? 'Successo' : 'Fallimento'));
    return $sent;
}

/**
 * Invia un'email con il link per il reset della password.
 */
function send_password_reset_link($user_email, $username, $token) {
    log_activity("[PwdReset] Preparazione email di reset password per {$user_email} con token {$token}.");
    if (!function_exists('send_custom_email')) {
        log_error("send_custom_email non disponibile per send_password_reset_link.", __FILE__, __LINE__);
        return false;
    }
    $reset_link = rtrim(SITE_URL, '/') . "/reset_password.php?token=" . urlencode($token);
    $site_name = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Nostro Servizio'));
    $subject = "Richiesta di Reset Password su " . $site_name;
    $body_html = "<p>Ciao " . htmlspecialchars($username) . ",</p>";
    $body_html .= "<p>Abbiamo ricevuto una richiesta di reset della password per il tuo account su " . htmlspecialchars($site_name) . ".</p>";
    $body_html .= "<p>Se non hai richiesto tu questa operazione, puoi ignorare questa email.</p>";
    $body_html .= "<p>Altrimenti, per reimpostare la tua password, clicca sul seguente link (o copialo e incollalo nel tuo browser):</p>";
    $body_html .= "<p><a href='{$reset_link}'>{$reset_link}</a></p>";
    $body_html .= "<p>Questo link è valido per 1 ora.</p>";
    $body_html .= "<p>Grazie,<br>Il Team di " . htmlspecialchars($site_name) . "</p>";
    $sent = send_custom_email($user_email, $subject, $body_html);
    if ($sent) {
        log_activity("[PwdReset] Email di reset password inviata (o accodata) a {$user_email}.");
    } else {
        log_error("[PwdReset] Fallito invio effettivo email di reset password a {$user_email}.", __FILE__, __LINE__);
    }
    return $sent;
}

/**
 * Verifica un token per la validazione dell'email dell'utente.
 */
function verify_user_email_validation_token($token) {
    $conn = get_db_connection(); $current_time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE validation_token = ? AND validation_token_expires_at > ? AND is_email_validated = FALSE");
    if (!$stmt) { log_error("DB Err (prep verify email token): " . $conn->error); return ['success' => false, 'message' => 'Errore server.']; }
    $stmt->bind_param("ss", $token, $current_time); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user) { $stmt_check = $conn->prepare("SELECT is_email_validated, validation_token_expires_at FROM users WHERE validation_token = ?"); if($stmt_check){ $stmt_check->bind_param("s", $token); $stmt_check->execute(); $d = $stmt_check->get_result()->fetch_assoc(); $stmt_check->close(); if ($d) { if ($d['is_email_validated']) return ['success' => false, 'message' => 'Email già validata.']; if ($d['validation_token_expires_at'] <= $current_time) return ['success' => false, 'message' => 'Link scaduto.']; }} return ['success' => false, 'message' => 'Link non valido o già usato.'];}
    $user_id = $user['id'];
    $stmt_update = $conn->prepare("UPDATE users SET is_email_validated = TRUE, validation_token = NULL, validation_token_expires_at = NULL WHERE id = ?");
    if (!$stmt_update) { log_error("DB Err (prep update email val): " . $conn->error); return ['success' => false, 'message' => 'Errore server.']; }
    $stmt_update->bind_param("i", $user_id);
    if ($stmt_update->execute()) {
        if ($stmt_update->affected_rows > 0) { $stmt_update->close(); log_activity("Email validata per ID {$user_id}, user: " . $user['username'], $user_id); return ['success' => true, 'message' => 'Email validata! Account in attesa di approvazione admin.'];}
        else { $stmt_update->close(); log_warning("Nessuna riga aggiornata validazione email ID {$user_id}."); return ['success' => false, 'message' => 'Email già validata o errore.'];}
    } else { log_error("DB Err (exec update email val): " . $stmt_update->error); $stmt_update->close(); return ['success' => false, 'message' => 'Errore server.'];}
}

/**
 * Amministratore valida un utente. Se necessario, genera token per setup password iniziale.
 */
function admin_validate_user_account($user_id_to_validate, $assigned_role = 'User', $is_active_status = TRUE) {
    $conn = get_db_connection();
    log_activity("[AdminValidate] Avvio per UserID: {$user_id_to_validate}, Ruolo: {$assigned_role}, Attivo: " . ($is_active_status ? 'Sì' : 'No'), $_SESSION['user_id'] ?? 'Sistema');
    if ($assigned_role !== 'Admin' && $assigned_role !== 'User') { log_error("[AdminValidate] Ruolo non valido: {$assigned_role}"); return ['success' => false, 'message' => "Ruolo non valido."]; }
    $stmt_user_check = $conn->prepare("SELECT password_hash, requires_password_change, is_email_validated, email, username FROM users WHERE id = ?");
    if (!$stmt_user_check) { log_error("[AdminValidate] DB Err (prep user check): ".$conn->error); return ['success'=>false,'message'=>'Errore DB.']; }
    $stmt_user_check->bind_param("i", $user_id_to_validate); $stmt_user_check->execute();
    $user_current_data = $stmt_user_check->get_result()->fetch_assoc(); $stmt_user_check->close();
    if (!$user_current_data) { log_error("[AdminValidate] Utente non trovato: {$user_id_to_validate}"); return ['success' => false, 'message' => 'Utente non trovato.']; }
    log_activity("[AdminValidate] Dati UserID {$user_id_to_validate}: EmailVal=".($user_current_data['is_email_validated']?'Sì':'No').", PwdHash='".($user_current_data['password_hash']===''?'VUOTO':'IMPOSTATO')."', ReqPwdChg=".($user_current_data['requires_password_change']?'Sì':'No'));
    if ($assigned_role === 'User' && $is_active_status === TRUE && !($user_current_data['is_email_validated'] ?? false)) { log_warning("[AdminValidate] Attivazione UserID {$user_id_to_validate} fallita: email non validata."); return ['success' => false, 'message' => "Email non validata. Impossibile attivare come 'User'."]; }
    $initial_password_setup_token = NULL; $initial_password_setup_token_expires_at = NULL;
    $needs_initial_password_setup = ($is_active_status === TRUE && ($user_current_data['password_hash'] === '' || $user_current_data['password_hash'] === NULL) && ($user_current_data['requires_password_change'] == TRUE));
    log_activity("[AdminValidate] UserID {$user_id_to_validate}: NeedsPwdSetup? " . ($needs_initial_password_setup ? 'Sì' : 'No'));
    if ($needs_initial_password_setup) { $initial_password_setup_token = bin2hex(random_bytes(32)); $initial_password_setup_token_expires_at = date('Y-m-d H:i:s', time() + (48 * 3600)); log_activity("[AdminValidate] UserID {$user_id_to_validate}: Token setup generato: " . $initial_password_setup_token); }
    $sql_update = "UPDATE users SET is_active = ?, requires_admin_validation = FALSE, role = ?, initial_password_setup_token = ?, initial_password_setup_token_expires_at = ? WHERE id = ? AND requires_admin_validation = TRUE";
    $stmt = $conn->prepare($sql_update);
    if (!$stmt) { log_error("[AdminValidate] DB Err (prep update): " . $conn->error . " SQL: ".$sql_update); return ['success' => false, 'message' => "Errore DB (prep)."]; }
    $active_int = $is_active_status ? 1 : 0; $types_string = "isssi"; 
    $stmt->bind_param($types_string, $active_int, $assigned_role, $initial_password_setup_token, $initial_password_setup_token_expires_at, $user_id_to_validate);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) { $stmt->close(); $admin_id_acting = $_SESSION['user_id'] ?? 'Sistema'; log_activity("[AdminValidate] Validato UserID: {$user_id_to_validate} da AdminID: {$admin_id_acting}, Ruolo: {$assigned_role}, Attivo: {$is_active_status}"); if ($is_active_status && function_exists('send_account_activated_email')) { log_activity("[AdminValidate] Chiamata send_account_activated_email per UserID: {$user_id_to_validate}, Email: {$user_current_data['email']}, Token: ".($initial_password_setup_token ?? 'Nessuno')); $email_sent_result = send_account_activated_email($user_current_data['email'], $user_current_data['username'], $initial_password_setup_token); log_activity("[AdminValidate] Risultato invio email attivazione per UserID {$user_id_to_validate}: " . ($email_sent_result ? 'Successo' : 'Fallimento')); } return ['success' => true, 'message' => "Utente validato."]; }
        else { $stmt->close(); log_warning("[AdminValidate] Nessun utente aggiornato per UserID: {$user_id_to_validate}. Già validato o email non verificata?"); return ['success' => false, 'message' => "Nessun utente aggiornato (già validato o email non verificata?)."]; }
    } else { log_error("[AdminValidate] DB Err (exec update): " . $stmt->error); $stmt->close(); return ['success' => false, 'message' => "Errore DB (exec)."]; }
}

/**
 * Invia email quando un account è stato attivato, con link per setup password se necessario.
 */
function send_account_activated_email($user_email, $username, $initial_password_token = null) {
    log_activity("[SendActivatedEmail] Preparazione email per {$user_email}. Username: {$username}. Token setup fornito: " . ($initial_password_token ? $initial_password_token : 'NO_TOKEN'));
    if (!function_exists('send_custom_email')) { log_error("[SendActivatedEmail] send_custom_email non disponibile per {$user_email}."); return false; }
    $site_name = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Nostro Servizio'));
    $subject = "Il tuo account su " . $site_name . " è stato attivato!";
    $body_html = "<p>Ciao " . htmlspecialchars($username) . ",</p>";
    $body_html .= "<p>Siamo felici di comunicarti che il tuo account su " . htmlspecialchars($site_name) . " è stato attivato da un amministratore.</p>";
    if ($initial_password_token) { $setup_link = rtrim(SITE_URL, '/') . "/change_password.php?action=setup&token=" . urlencode($initial_password_token); $body_html .= "<p>Per completare l'attivazione e accedere, per favore imposta la tua password cliccando sul seguente link (valido per 48 ore):</p><p><a href='{$setup_link}'>{$setup_link}</a></p>"; log_activity("[SendActivatedEmail] Email per {$user_email} includerà il link di setup password: {$setup_link}"); }
    else { $login_link = rtrim(SITE_URL, '/') . "/login.php"; $body_html .= "<p>Ora puoi accedere al sito: <a href='{$login_link}'>{$login_link}</a></p>"; log_activity("[SendActivatedEmail] Email per {$user_email} NON includerà un link di setup password."); }
    $body_html .= "<p>Grazie,<br>Il Team di " . htmlspecialchars($site_name) . "</p>";
    $email_sent = send_custom_email($user_email, $subject, $body_html);
    if (!$email_sent) { log_error("[SendActivatedEmail] Fallito invio effettivo email di attivazione a {$user_email}."); } else { log_activity("[SendActivatedEmail] Email di attivazione (send_custom_email ha restituito ".($email_sent ? 'true':'false').") inviata a {$user_email}."); }
    return $email_sent;
}

/**
 * Verifica un token per l'impostazione iniziale della password.
 */
function verify_initial_password_setup_token($token) {
    $conn = get_db_connection(); $current_time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id, username, role, password_hash, requires_password_change FROM users WHERE initial_password_setup_token = ? AND initial_password_setup_token_expires_at > ? AND is_active = TRUE");
    if (!$stmt) { log_error("DB Err (verify_init_pwd_token prep): ".$conn->error); return false; }
    $stmt->bind_param("ss", $token, $current_time); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user) { $stmt_invalidate = $conn->prepare("UPDATE users SET initial_password_setup_token = NULL, initial_password_setup_token_expires_at = NULL WHERE initial_password_setup_token = ?"); if($stmt_invalidate){ $stmt_invalidate->bind_param("s", $token); $stmt_invalidate->execute(); $stmt_invalidate->close(); } return false; }
    if ($user['password_hash'] === '' && $user['requires_password_change'] == TRUE) { return ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]; }
    else { log_warning("Tentativo di usare token setup pwd per UserID {$user['id']} che non ne necessita."); $stmt_invalidate_used = $conn->prepare("UPDATE users SET initial_password_setup_token = NULL, initial_password_setup_token_expires_at = NULL WHERE id = ?"); if($stmt_invalidate_used) { $stmt_invalidate_used->bind_param("i", $user['id']); $stmt_invalidate_used->execute(); $stmt_invalidate_used->close(); } return false; }
}

/**
 * Verifica un token per il reset della password.
 */
function verify_password_reset_token($token) {
    $conn = get_db_connection(); $current_time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE password_reset_token = ? AND password_reset_token_expires_at > ? AND is_active = TRUE");
    if (!$stmt) { log_error("DB Error (prep verify_password_reset_token): " . $conn->error); return false; }
    $stmt->bind_param("ss", $token, $current_time); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($user) { return ['user_id' => $user['id'], 'username' => $user['username']]; }
    else { $stmt_invalidate = $conn->prepare("UPDATE users SET password_reset_token = NULL, password_reset_token_expires_at = NULL WHERE password_reset_token = ?"); if($stmt_invalidate){ $stmt_invalidate->bind_param("s", $token); $stmt_invalidate->execute(); $stmt_invalidate->close(); } return false; }
}

/**
 * Ottiene la lista degli utenti in attesa di validazione admin.
 */
function get_pending_validation_users() {
    $conn = get_db_connection(); $users = [];
    $sql = "SELECT id, username, email, role, created_at, is_email_validated FROM users WHERE requires_admin_validation = TRUE AND is_active = FALSE ORDER BY created_at ASC";
    $result = $conn->query($sql);
    if ($result) { while ($row = $result->fetch_assoc()) $users[] = $row; $result->free(); } else log_error("Errore get_pending: " . $conn->error);
    return $users;
}

/**
 * Admin rigetta/elimina un utente in attesa.
 */
function admin_reject_pending_user($user_id_to_reject) {
    $conn=get_db_connection();$s=$conn->prepare("DELETE FROM users WHERE id=? AND requires_admin_validation=TRUE AND is_active=FALSE");
    if(!$s){log_error("DB Err(prep admin rej): ".$conn->error);return['success'=>false,'message'=>"Err DB."];}
    $s->bind_param("i",$user_id_to_reject);
    if($s->execute()){ if($s->affected_rows>0){$s->close();$aid=$_SESSION['user_id']??'Sistema';log_activity("Admin(ID:{$aid})rigettato ID:{$user_id_to_reject}",$aid);return['success'=>true,'message'=>"Utente rigettato."];} else{$s->close();return['success'=>false,'message'=>"Nessun utente eliminato."];}}
    else{log_error("DB Err(exec admin rej): ".$s->error);$s->close();return['success'=>false,'message'=>"Err DB."];}
}

// --- Funzioni Helper Sessione ---
function is_logged_in() { return isset($_SESSION['user_id']); }
function require_login($page_to_redirect_to = 'login.php') { if(!is_logged_in()){$_SESSION['flash_message']='Devi loggarti.';$_SESSION['flash_type']='warning';if(basename($_SERVER['PHP_SELF'])!=='login.php')$_SESSION['redirect_after_login']=$_SERVER['REQUEST_URI'];header('Location: '.$page_to_redirect_to);exit;}if(isset($_SESSION['force_password_change'])&&$_SESSION['force_password_change']===true&&basename($_SERVER['PHP_SELF'])!=='change_password.php'){$_SESSION['flash_message']='Devi cambiare password.';$_SESSION['flash_type']='warning';header('Location: change_password.php?forced=1');exit;}}
function is_admin() { return (is_logged_in() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin'); }
function require_admin() { require_login(); if (!is_admin()) { $_SESSION['flash_message'] = 'Accesso negato.'; $_SESSION['flash_type'] = 'danger'; header('Location: index.php'); exit; } }

/* --- Funzioni Email (Configurata per mail() di PHP) --- */
function send_custom_email($to, $subject, $body_html, $body_text = '') {
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) { log_error("PHPMailer non caricato. Email a {$to} non inviata."); return false; }
    $mail = new PHPMailer(true); 
    try {
        $mail->isMail(); 
        $site_name_email = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Tuo Servizio'));
        $from_email = 'noreply@tuodominiofallback.com'; 
        if (defined('SITE_EMAIL_FROM') && filter_var(SITE_EMAIL_FROM, FILTER_VALIDATE_EMAIL)) { $from_email = SITE_EMAIL_FROM; }
        elseif (isset($_SERVER['SERVER_NAME'])) {
            if (filter_var('test@' . $_SERVER['SERVER_NAME'], FILTER_VALIDATE_EMAIL, FILTER_FLAG_HOSTNAME)) { $from_email = 'noreply@' . $_SERVER['SERVER_NAME']; }
            else { $from_email = 'noreply@localhost.localdomain'; log_warning("SERVER_NAME ('{$_SERVER['SERVER_NAME']}') non valido per FROM, usando fallback: {$from_email}");}
        }
        if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) { log_error("Indirizzo FROM non valido: {$from_email}. Email non inviata."); return false; }
        $mail->setFrom($from_email, $site_name_email); $mail->addAddress($to);
        $mail->isHTML(true); $mail->CharSet = 'UTF-8'; $mail->Subject = $subject; 
        $mail->Body = $body_html; $mail->AltBody = $body_text ?: strip_tags($body_html);
        if ($mail->send()) { log_activity("Email (mail()) inviata a {$to}, ogg: {$subject}"); return true; }
        else { log_error("Errore mail() inviando a {$to}: {$mail->ErrorInfo}"); return false; }
    } catch (Exception $e) { log_error("PHPMailer Exc (mail()) a {$to}: {$mail->ErrorInfo}"); return false; }
}
?>