<?php
// install/installer_functions.php

if (!defined('INSTALLER_PROJECT_ROOT')) {
    define('INSTALLER_PROJECT_ROOT', dirname(__DIR__)); 
}
// Definisci qui le costanti di percorso usate dall'installer
if (!defined('INSTALLER_LOG_DIR')) {
    define('INSTALLER_LOG_DIR', INSTALLER_PROJECT_ROOT . '/LOG/');
}
if (!defined('INSTALLER_CONFIG_PATH')) {
    define('INSTALLER_CONFIG_PATH', INSTALLER_PROJECT_ROOT . '/config.php');
}
if (!defined('INSTALLER_SYSTEMIMAGES_PATH')) {
    define('INSTALLER_SYSTEMIMAGES_PATH', INSTALLER_PROJECT_ROOT . '/SystemImages/');
}
if (!defined('INSTALLER_SCHEMA_TEMPLATE_PATH')) { // Path al tuo file schema
    define('INSTALLER_SCHEMA_TEMPLATE_PATH', __DIR__ . '/schema_template.sql');
}


if (!function_exists('installer_session_start')) {
    function installer_session_start() {
        if (session_status() == PHP_SESSION_NONE) {
            session_name('InstallerFM_SID'); 
            $cookie_path = dirname($_SERVER['SCRIPT_NAME']);
            if (substr($cookie_path, -1) !== '/') { $cookie_path .= '/'; }
            session_set_cookie_params(['path' => $cookie_path, 'httponly' => true, 'samesite' => 'Lax']);
            if(!session_start()) {
                error_log(date('[Y-m-d H:i:s]') . " INSTALLER CRITICAL: Impossibile avviare sessione." . PHP_EOL, 3, INSTALLER_LOG_DIR . 'install_critical_error.log');
                die("Errore critico: impossibile avviare la sessione dell'installer.");
            }
        }
        if (!isset($_SESSION['installer_step'])) $_SESSION['installer_step'] = 1;
        if (!isset($_SESSION['installer_config'])) $_SESSION['installer_config'] = ['db'=>[],'site'=>[],'admin'=>[]];
        if (!isset($_SESSION['installer_reinstall_mode'])) $_SESSION['installer_reinstall_mode'] = false;
    }
}

if (!function_exists('installer_log_error')) {
    function installer_log_error($message) {
        if (!is_dir(INSTALLER_LOG_DIR)) { if (!@mkdir(INSTALLER_LOG_DIR, 0755, true)) { error_log("FALLIMENTO CREAZIONE DIR LOG INSTALLER (error): " . INSTALLER_LOG_DIR); return; }}
        @error_log(date('[Y-m-d H:i:s]') . " INSTALLER ERROR: " . $message . PHP_EOL, 3, INSTALLER_LOG_DIR . 'install_error.log');
    }
}

if (!function_exists('installer_log_activity')) {
    function installer_log_activity($message) {
         if (!is_dir(INSTALLER_LOG_DIR)) { if (!@mkdir(INSTALLER_LOG_DIR, 0755, true)) { error_log("FALLIMENTO CREAZIONE DIR LOG INSTALLER (activity): " . INSTALLER_LOG_DIR); return; }}
        @error_log(date('[Y-m-d H:i:s]') . " INSTALLER ACTIVITY: " . $message . PHP_EOL, 3, INSTALLER_LOG_DIR . 'install_activity.log');
    }
}
?>