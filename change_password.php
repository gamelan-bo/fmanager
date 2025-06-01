<?php
// /var/www/html/fm/change_password.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php'; 

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}

$user_id_for_password_change = null;
$username_for_display = '';
$is_forced_change = false; 
$is_initial_setup_via_token = false; 

if (isset($_GET['action']) && $_GET['action'] === 'setup' && isset($_GET['token'])) {
    $token = $_GET['token'];
    $user_data_from_token = verify_initial_password_setup_token($token);
    if ($user_data_from_token) {
        if (session_status() == PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user_id'] = $user_data_from_token['id'];
        $_SESSION['username'] = $user_data_from_token['username'];
        $_SESSION['user_role'] = $user_data_from_token['role'];
        $_SESSION['force_password_change'] = true;    
        $_SESSION['is_setting_initial_password'] = true; 
        $_SESSION['initiated'] = true; $_SESSION['last_activity'] = time(); 
        $user_id_for_password_change = $user_data_from_token['id'];
        $username_for_display = $user_data_from_token['username'];
        $is_forced_change = true; $is_initial_setup_via_token = true;
    } else {
        $_SESSION['flash_message'] = "Link per impostazione password non valido, scaduto o già usato. Prova ad accedere.";
        $_SESSION['flash_type'] = 'danger'; header('Location: login.php'); exit;
    }
} else {
    require_login();
    $user_id_for_password_change = $_SESSION['user_id'];
    $username_for_display = $_SESSION['username'];
    $is_forced_change = (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] === true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore CSRF."; $_SESSION['flash_type'] = 'danger';
    } else {
        if (!isset($_SESSION['user_id'])) { 
             $_SESSION['flash_message'] = "Sessione non valida."; $_SESSION['flash_type'] = 'danger';
             header('Location: login.php'); exit;
        }
        $user_id_to_update = $_SESSION['user_id']; 
        $current_password_input = $_POST['current_password'] ?? null;
        $new_password = $_POST['new_password'] ?? '';
        $confirm_new_password = $_POST['confirm_new_password'] ?? '';
        $proceed_with_update = false;

        if ($new_password !== $confirm_new_password) { $_SESSION['flash_message'] = "Le password non coincidono."; $_SESSION['flash_type'] = 'danger'; }
        elseif (strlen($new_password) < 8) { $_SESSION['flash_message'] = "Password min. 8 caratteri."; $_SESSION['flash_type'] = 'danger'; }
        else {
            if ($is_forced_change || $is_initial_setup_via_token) { $proceed_with_update = true; }
            else {
                if (empty($current_password_input)) { $_SESSION['flash_message'] = "Password corrente richiesta."; $_SESSION['flash_type'] = 'danger'; }
                else {
                    $conn_pwd_check = get_db_connection(); $stmt_pwd = $conn_pwd_check->prepare("SELECT password_hash FROM users WHERE id = ?");
                    if ($stmt_pwd) { $stmt_pwd->bind_param("i", $user_id_to_update); $stmt_pwd->execute(); $user_pwd_data = $stmt_pwd->get_result()->fetch_assoc(); $stmt_pwd->close();
                        if ($user_pwd_data && password_verify($current_password_input, $user_pwd_data['password_hash'])) { $proceed_with_update = true; }
                        else { $_SESSION['flash_message'] = "Password corrente errata."; $_SESSION['flash_type'] = 'danger'; }
                    } else { $_SESSION['flash_message'] = "Errore DB."; $_SESSION['flash_type'] = 'danger'; }
                }
            }
            if ($proceed_with_update) {
                $result = update_user_password($user_id_to_update, $new_password);
                $_SESSION['flash_message'] = $result['message']; $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
                if ($result['success']) {
                    $_SESSION['flash_message'] = "Password impostata/aggiornata! Puoi accedere."; $_SESSION['flash_type'] = 'success';
                    header('Location: index.php'); exit;
                }
            }
        }
    }
    $redirect_query_string = '';
    if ($is_forced_change && !$is_initial_setup_via_token && isset($_GET['forced'])) $redirect_query_string = '?forced=1';
    header("Location: change_password.php" . $redirect_query_string); exit;
}

$page_title = ($is_initial_setup_via_token || (isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'])) ? "Imposta la Tua Password" : "Cambia Password";
require_once __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center"><div class="col-md-6">
    <h2><?php echo $page_title; ?></h2>
    <?php if ($is_initial_setup_via_token && $user_id_for_password_change): ?>
        <div class="alert alert-info">Benvenuto/a <?php echo htmlspecialchars($username_for_display); ?>! Imposta la tua nuova password.</div>
    <?php elseif ($is_forced_change): ?>
         <div class="alert alert-warning">Devi impostare una nuova password.</div>
    <?php endif; ?>
    <form action="change_password.php<?php if ($is_forced_change && !$is_initial_setup_via_token && isset($_GET['forced'])) echo '?forced=1'; ?>" method="POST" novalidate>
        <?php echo csrf_input_field(); ?>
        <?php if (!$is_forced_change && !$is_initial_setup_via_token): ?>
        <div class="form-group"><label for="current_password">Password Corrente:</label><input type="password" class="form-control" id="current_password" name="current_password" required></div>
        <?php endif; ?>
        <div class="form-group"><label for="new_password">Nuova Password (min. 8):</label><input type="password" class="form-control" id="new_password" name="new_password" required minlength="8"></div>
        <div class="form-group"><label for="confirm_new_password">Conferma Password:</label><input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" required minlength="8"></div>
        <button type="submit" class="btn btn-primary"><?php echo ($is_initial_setup_via_token || (isset($_SESSION['is_setting_initial_password']) && $_SESSION['is_setting_initial_password'])) ? 'Imposta e Accedi' : 'Cambia Password'; ?></button>
    </form>
</div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>