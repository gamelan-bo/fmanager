<?php
// includes/functions_url.php

if (!defined('SITE_URL')) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    } else {
        die('Errore critico: SITE_URL non definita e config.php non trovato per functions_url.php');
    }
}
if (!function_exists('log_error')) { 
    function log_error($message, $script_name = '', $line_number = '') { @error_log(date('[Y-m-d H:i:s]') . " Error: {$message} in {$script_name} on line {$line_number}\n", 3, defined('ERROR_LOG') ? ERROR_LOG : (__DIR__.'/../LOG/error.log')); }
}
if (!function_exists('log_activity')) { // Aggiungo anche log_activity per coerenza
    function log_activity($message, $user_id = null) { @error_log(date('[Y-m-d H:i:s]') . ($user_id !== null ? " [User ID: {$user_id}]" : "") . " - Activity: {$message}\n", 3, defined('ACTIVITY_LOG') ? ACTIVITY_LOG : (__DIR__.'/../LOG/activity.log')); }
}


if (!function_exists('generate_url')) {
    /**
     * Genera un URL.
     * Se $include_base_url è true, restituisce l'URL assoluto completo (http://...).
     * Se $include_base_url è false, restituisce il path "pulito" relativo alla RewriteBase.
     */
    function generate_url($routeName, $params = [], $include_base_url = true) {
        static $routes = [
            'login' => 'login', 'register' => 'registrati', 'logout' => 'logout',
            'change_password' => 'cambia-password',
            'validate_user_email' => 'valida-email',      
            'validate_email_change' => 'valida-cambio-email', 
            'forgot_password' => 'password-dimenticata',
            'reset_password' => 'reset-password',          
            'edit_profile' => 'modifica-profilo', 
            'my_files_root' => 'i-miei-file',             
            'folder_view' => 'cartella/{id}',             
            'file_edit' => 'file/modifica/{id}',          
            'file_download' => 'download/file/{id}',      
            'public_download' => 'download/pubblico/{token}',
            'upload_to_root' => 'upload', 'upload_to_folder' => 'upload/in/cartella/{id}', 
            'delete_file_action' => 'file/elimina',       
            'admin_dashboard' => 'admin', 'admin_pending_users' => 'admin/utenti-pendenti',
            'admin_users_list' => 'admin/utenti', 'admin_user_edit' => 'admin/utenti/modifica/{id}',
            'admin_folders_list' => 'admin/cartelle', 'admin_folder_manage' => 'admin/cartelle/gestisci/{id}',
            'admin_all_files' => 'admin/file-manager', 'admin_settings' => 'admin/impostazioni',
            'home' => '' 
        ];

        $path_template = $routes[$routeName] ?? null;
        $query_string_params = []; // Array per i parametri che andranno nella query string

        if ($path_template === null) {
            log_error("Tentativo di generare URL per rotta non definita: '" . htmlspecialchars($routeName) . "'", __FILE__, __LINE__);
            if (strpos($routeName, '.php') !== false) { 
                $path_template = $routeName;
                $query_string_params = $params; // Tutti i parametri vanno in query string se è un fallback a script .php
            } else {
                $error_path_segment = '#ERRORE_ROTTA_NON_DEFINITA_' . htmlspecialchars($routeName);
                return $include_base_url ? (rtrim(SITE_URL, '/') . '/' . ltrim($error_path_segment, '/')) : ltrim($error_path_segment, '/');
            }
        } else {
            // Sostituisci i placeholder nel path_template
            $temp_path_template = $path_template; // Lavora su una copia
            $params_used_in_path = [];

            foreach ($params as $key => $value) {
                $placeholder = '{' . $key . '}';
                if (strpos($temp_path_template, $placeholder) !== false) {
                    $temp_path_template = str_replace($placeholder, urlencode((string)$value), $temp_path_template);
                    $params_used_in_path[$key] = true; // Segna questo parametro come usato nel path
                }
            }
            $path_template = $temp_path_template; // Aggiorna il path template con i valori sostituiti

            // I parametri rimanenti (non usati nei placeholder) vanno nella query string
            foreach ($params as $key => $value) {
                if (!isset($params_used_in_path[$key])) {
                    $query_string_params[$key] = $value;
                }
            }
        }
        
        $url_path_part = ltrim($path_template, '/');

        if ($include_base_url) {
            $final_url = rtrim(SITE_URL, '/') . '/' . $url_path_part;
        } else {
            $final_url = $url_path_part;
        }

        if (!empty($query_string_params)) {
            $final_url .= '?' . http_build_query($query_string_params);
        }
        
        return $final_url;
    }
}
?>