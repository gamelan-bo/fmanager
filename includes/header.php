<?php
// includes/header.php

// config.php dovrebbe essere già stato incluso dal file chiamante (es. index.php, login.php ecc.)
// e config.php ora include functions_url.php
if (!defined('PROJECT_ROOT') && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php'; 
}

// Questi require dovrebbero essere già nel tuo file, li mantengo per completezza
if (!file_exists(__DIR__ . '/db_connection.php')) { die('Errore: db_connection.php mancante'); }
require_once __DIR__ . '/db_connection.php';

if (!file_exists(__DIR__ . '/functions_settings.php')) { die('Errore: functions_settings.php mancante'); }
require_once __DIR__ . '/functions_settings.php';

if (!file_exists(__DIR__ . '/functions_csrf.php')) { die('Errore: functions_csrf.php mancante'); }
require_once __DIR__ . '/functions_csrf.php';

// functions_utils.php per adjust_brightness (e format_file_size)
if (file_exists(__DIR__ . '/functions_utils.php')) {
    require_once __DIR__ . '/functions_utils.php';
} elseif (!function_exists('adjust_brightness')) {
    // Fallback di emergenza per adjust_brightness se functions_utils non è caricato
    function adjust_brightness($hex, $steps) { $steps = max(-255, min(255, $steps)); $hex = str_replace('#', '', $hex); if (strlen($hex) == 3) { $hex = str_repeat(substr($hex,0,1),2).str_repeat(substr($hex,1,1),2).str_repeat(substr($hex,2,1),2); } $r=max(0,min(255,hexdec(substr($hex,0,2))+$steps)); $g=max(0,min(255,hexdec(substr($hex,2,2))+$steps)); $b=max(0,min(255,hexdec(substr($hex,4,2))+$steps)); return '#'.str_pad(dechex($r),2,'0',STR_PAD_LEFT).str_pad(dechex($g),2,'0',STR_PAD_LEFT).str_pad(dechex($b),2,'0',STR_PAD_LEFT); }
}


// La sessione dovrebbe essere già stata avviata da config.php
if (session_status() == PHP_SESSION_NONE) { 
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    if (!session_start()) { 
        if(function_exists('log_error')) log_error("Tentativo di avviare sessione in header.php fallito.", __FILE__, __LINE__); 
    }
}

if (!isset($_SESSION['initiated'])) { 
    $_SESSION['initiated'] = true; 
    if (session_status() == PHP_SESSION_ACTIVE) session_regenerate_id(true); 
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset(); 
    session_destroy(); 
    if (defined('SESSION_NAME')) session_name(SESSION_NAME); 
    if (session_status() == PHP_SESSION_NONE) session_start();
    $_SESSION['flash_message'] = 'Sessione scaduta per inattività. Effettua nuovamente il login.';
    $_SESSION['flash_type'] = 'warning';
    // Assicurati che generate_url sia disponibile prima di usarla
    if (function_exists('generate_url')) { 
        header('Location: ' . generate_url('login')); 
    } else { 
        // Fallback se generate_url non è disponibile per qualche motivo (improbabile se config.php è corretto)
        $fallback_login_url = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/login.php';
        header('Location: ' . $fallback_login_url); 
    }
    exit;
}
$_SESSION['last_activity'] = time();

// Recupero Impostazioni Sito
$db_site_name = function_exists('get_site_setting') ? get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'File Share')) : (defined('SITE_NAME') ? SITE_NAME : 'File Share');
$db_site_logo_url_path = function_exists('get_site_setting') ? get_site_setting('site_logo_url', '') : ''; // Questo è il path relativo dal DB
$db_site_logo_max_height = function_exists('get_site_setting') ? get_site_setting('site_logo_max_height', '50') : '50'; 
$db_site_logo_max_width = function_exists('get_site_setting') ? get_site_setting('site_logo_max_width', '0') : '0'; 
$db_site_logo_alignment = function_exists('get_site_setting') ? get_site_setting('site_logo_alignment', 'center') : 'center'; 

// Recupero Colori Tema e Font
$theme_navbar_bg = function_exists('get_site_setting') ? get_site_setting('theme_navbar_bg', '#343a40') : '#343a40';
$theme_navbar_text = function_exists('get_site_setting') ? get_site_setting('theme_navbar_text', '#ffffff') : '#ffffff';
$theme_navbar_text_hover = function_exists('get_site_setting') ? get_site_setting('theme_navbar_text_hover', '#f8f9fa') : '#f8f9fa';
$theme_footer_bg = function_exists('get_site_setting') ? get_site_setting('theme_footer_bg', '#f8f9fa') : '#f8f9fa';
$theme_footer_text_color = function_exists('get_site_setting') ? get_site_setting('theme_footer_text_color', '#6c757d') : '#6c757d';
$theme_accent_color = function_exists('get_site_setting') ? get_site_setting('theme_accent_color', '#007bff') : '#007bff'; 
$theme_global_font_family = function_exists('get_site_setting') ? get_site_setting('theme_global_font_family', '') : ''; 

$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_role = $_SESSION['user_role'] ?? null;
$current_username = $_SESSION['username'] ?? '';

// Preparazione Stili Dinamici (codice completo come nell'ultima versione funzionante per i temi)
$dynamic_theme_styles = "<style id=\"custom-theme-dynamic-styles\">\n";
if (!empty($theme_global_font_family) && $theme_global_font_family !== '') { $dynamic_theme_styles .= "  body, .btn, .form-control, input, select, textarea, h1, h2, h3, h4, h5, h6, .table { font-family: " . htmlspecialchars($theme_global_font_family) . " !important; }\n"; }
if ($theme_navbar_bg) { $dynamic_theme_styles .= "  .navbar.custom-theme-navbar { background-color: " . htmlspecialchars($theme_navbar_bg) . " !important; }\n"; }
if ($theme_navbar_text && function_exists('adjust_brightness')) {
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .navbar-nav .nav-link, .navbar-dark.custom-theme-navbar .navbar-brand, .navbar-dark.custom-theme-navbar .navbar-brand span.site-name-text { color: " . htmlspecialchars($theme_navbar_text) . " !important; }\n";
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .dropdown-menu a.dropdown-item { color: #212529 !important; }\n"; 
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .dropdown-menu a.dropdown-item:hover, .navbar-dark.custom-theme-navbar .dropdown-menu a.dropdown-item:focus { color: #000 !important; background-color: #e9ecef !important; }\n";
    $navbar_hover_effective_color = !empty($theme_navbar_text_hover) ? $theme_navbar_text_hover : adjust_brightness($theme_navbar_text, 30);
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .navbar-nav .nav-link:hover, .navbar-dark.custom-theme-navbar .navbar-nav .nav-item.active .nav-link { color: " . htmlspecialchars($navbar_hover_effective_color) . " !important; }\n";
    $toggler_icon_color_urlencoded = urlencode(htmlspecialchars($theme_navbar_text));
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .navbar-toggler-icon { background-image: url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='30' height='30' viewBox='0 0 30 30'%3e%3cpath stroke='" . $toggler_icon_color_urlencoded . "' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e\") !important; }\n";
}
if ($theme_footer_bg) { $dynamic_theme_styles .= "  .footer.custom-theme-footer { background-color: " . htmlspecialchars($theme_footer_bg) . " !important; }\n"; }
if ($theme_footer_text_color) { $dynamic_theme_styles .= "  .footer.custom-theme-footer, .footer.custom-theme-footer .text-muted, .footer.custom-theme-footer span { color: " . htmlspecialchars($theme_footer_text_color) . " !important; }\n"; }
if ($theme_accent_color && function_exists('adjust_brightness')) {
    $accent_hover = adjust_brightness($theme_accent_color, -20); $accent_border = adjust_brightness($theme_accent_color, -20); $accent_border_hover = adjust_brightness($theme_accent_color, -40);
    $dynamic_theme_styles .= "  a:not(.nav-link):not(.btn):not(.dropdown-item):not(.page-link):not(.navbar-brand) { color: " . htmlspecialchars($theme_accent_color) . "; }\n";
    $dynamic_theme_styles .= "  a:not(.nav-link):not(.btn):not(.dropdown-item):not(.page-link):not(.navbar-brand):hover { color: " . htmlspecialchars($accent_hover) . "; }\n";
    $dynamic_theme_styles .= "  .btn-primary { background-color: " . htmlspecialchars($theme_accent_color) . " !important; border-color: " . htmlspecialchars($accent_border) . " !important; }\n";
    $dynamic_theme_styles .= "  .btn-primary:hover { background-color: " . htmlspecialchars($accent_hover) . " !important; border-color: " . htmlspecialchars($accent_border_hover) . " !important; }\n";
    $dynamic_theme_styles .= "  .pagination .page-item.active .page-link { background-color: " . htmlspecialchars($theme_accent_color) . "; border-color: " . htmlspecialchars($theme_accent_color) . "; }\n";
}
$dynamic_theme_styles .= "</style>\n";

// Logica Google Fonts
$google_font_link_tag = '';
$google_fonts_map_for_header = [ '"Roboto", sans-serif' => 'Roboto:wght@400;700', '"Open Sans", sans-serif' => 'Open+Sans:wght@400;700', '"Lato", sans-serif' => 'Lato:wght@400;700', '"Montserrat", sans-serif' => 'Montserrat:wght@400;700', '"Noto Sans", sans-serif' => 'Noto+Sans:wght@400;700' ];
if (!empty($theme_global_font_family) && isset($google_fonts_map_for_header[$theme_global_font_family])) {
    $font_query = $google_fonts_map_for_header[$theme_global_font_family];
    $google_font_link_tag = '<link href="https://fonts.googleapis.com/css2?family=' . $font_query . '&display=swap" rel="stylesheet">' . "\n";
}

// Logica visualizzazione logo
$logo_display_url_header = ''; $logo_container_style_attr = ''; $logo_img_style_attr = '';
if ($db_site_logo_url_path && defined('PROJECT_ROOT') && defined('SITE_URL')) { 
    // $db_site_logo_url_path è il path relativo memorizzato nel DB, es: SystemImages/nomefile.png
    // Non iniziare con '/' se è relativo a PROJECT_ROOT
    $physical_logo_path_on_server = PROJECT_ROOT . '/' . ltrim($db_site_logo_url_path, '/'); 
    if (is_file($physical_logo_path_on_server)) { 
        // Per l'URL del logo, usiamo SITE_URL e il path relativo.
        // SITE_URL è http://10.1.1.16/fm
        // $db_site_logo_url_path è es. SystemImages/logo.png
        // Risultato: http://10.1.1.16/fm/SystemImages/logo.png
        $logo_display_url_header = rtrim(SITE_URL, '/') . '/' . ltrim($db_site_logo_url_path, '/'); 
    } else {
        // if(function_exists('log_warning')) log_warning("File logo non trovato su server: " . htmlspecialchars($physical_logo_path_on_server) . " (path da DB: " . htmlspecialchars($db_site_logo_url_path) . ")");
    }
}
$logo_container_style_attr = 'text-align: ' . htmlspecialchars($db_site_logo_alignment) . ';';
$logo_img_styles_array = ["width: auto", "height: auto"];
if (!empty($db_site_logo_max_height)&&(int)$db_site_logo_max_height>0) $logo_img_styles_array[]="max-height: ".htmlspecialchars($db_site_logo_max_height)."px";
if (!empty($db_site_logo_max_width)&&(int)$db_site_logo_max_width>0) $logo_img_styles_array[]="max-width: ".htmlspecialchars($db_site_logo_max_width)."px";
$logo_img_style_attr = implode('; ', $logo_img_styles_array) . ';';

// Controllo se la funzione generate_url() è disponibile
$can_generate_urls = function_exists('generate_url');

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (defined('SITE_URL')): // Aggiungi tag <base> solo se SITE_URL è definito ?>
        <base href="<?php echo rtrim(SITE_URL, '/') . '/'; ?>">  <?php // TAG <BASE> AGGIUNTO/CONFERMATO ?>
    <?php endif; ?>
    <title><?php echo htmlspecialchars($db_site_name); ?> - <?php echo htmlspecialchars($page_title ?? 'Benvenuto'); ?></title>
    
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
    <?php echo $google_font_link_tag; // Link per Google Fonts ?>
    <link rel="stylesheet" href="assets/css/style.css"> 
    
    <?php echo $dynamic_theme_styles; // Stili CSS dinamici ?>

    <?php if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA'): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
    <header>
        <?php if ($logo_display_url_header): ?>
        <div class="site-logo-header-container" style="<?php echo $logo_container_style_attr; ?>">
            <a href="<?php echo $can_generate_urls ? generate_url('home') : (defined('SITE_URL') ? SITE_URL.'/index.php' : 'index.php'); ?>">
                <img src="<?php echo htmlspecialchars($logo_display_url_header); ?>?t=<?php echo time(); // Cache buster ?>" 
                     alt="Logo <?php echo htmlspecialchars($db_site_name); ?>" 
                     class="top-site-logo-img"
                     style="<?php echo $logo_img_style_attr; ?>">
            </a>
        </div>
        <?php endif; ?>

        <nav class="navbar navbar-expand-lg navbar-dark custom-theme-navbar <?php if (!$logo_display_url_header && function_exists('generate_url') && $_SERVER['REQUEST_URI'] !== generate_url('home', [], false) ) echo 'mb-3'; ?>">
            <a class="navbar-brand" href="<?php echo $can_generate_urls ? generate_url('home') : (defined('SITE_URL') ? SITE_URL.'/index.php' : 'index.php'); ?>">
                <span class="site-name-text"><?php 
                    if (!$logo_display_url_header || true) { 
                        echo htmlspecialchars($db_site_name); 
                    } 
                ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                <ul class="navbar-nav"> 
                    <?php if ($current_user_id && $can_generate_urls): ?>
                        <li class="nav-item <?php if(strpos($_SERVER['REQUEST_URI'], generate_url('home', [], false)) === 0 && (generate_url('home',[],false) === $_SERVER['REQUEST_URI'] || generate_url('home',[],false).'/' === $_SERVER['REQUEST_URI'] || basename($_SERVER['PHP_SELF'])=='index.php') ) echo 'active';?>">
                            <a class="nav-link" href="<?php echo generate_url('home'); ?>">Dashboard</a>
                        </li>
                        <li class="nav-item <?php if(strpos($_SERVER['REQUEST_URI'], generate_url('my_files_root', [], false)) === 0) echo 'active';?>">
                            <a class="nav-link" href="<?php echo generate_url('my_files_root'); ?>">I Miei File</a>
                        </li>
                        
                        <?php if ($current_user_role === 'Admin'): 
                            $admin_panel_links_active = [
                                generate_url('admin_pending_users', [], false),
                                generate_url('admin_users_list', [], false),
                                generate_url('admin_folders_list', [], false),
                                generate_url('admin_all_files', [], false),
                                generate_url('admin_settings', [], false),
                                generate_url('admin_folder_manage', ['id'=>'0'], false), // Per il pattern base
                                generate_url('admin_user_edit', ['id'=>'0'], false)      // Per il pattern base
                            ];
                            $is_admin_page_active = false;
                            foreach($admin_panel_links_active as $link_pattern){
                                // Semplifichiamo il controllo: se REQUEST_URI inizia con /admin/ (dopo /fm/) è una pagina admin
                                if (strpos($_SERVER['REQUEST_URI'], rtrim(SITE_URL,'/').'/admin/') === 0) {
                                   $is_admin_page_active = true; break;
                                }
                            }
                        ?>
                            <li class="nav-item dropdown <?php if($is_admin_page_active) echo 'active'; ?>">
                                <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Admin Panel</a>
                                <div class="dropdown-menu" aria-labelledby="adminDropdown">
                                    <a class="dropdown-item" href="<?php echo generate_url('admin_pending_users'); ?>"><i class="fas fa-user-check fa-fw mr-2"></i>Valida Utenti</a>
                                    <a class="dropdown-item" href="<?php echo generate_url('admin_users_list'); ?>"><i class="fas fa-users-cog fa-fw mr-2"></i>Gestione Utenti</a>
                                    <a class="dropdown-item" href="<?php echo generate_url('admin_folders_list'); ?>"><i class="fas fa-folder-open fa-fw mr-2"></i>Gestione Cartelle</a>
                                    <a class="dropdown-item" href="<?php echo generate_url('admin_all_files'); ?>"><i class="fas fa-archive fa-fw mr-2"></i>Gestione File</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="<?php echo generate_url('admin_settings'); ?>"><i class="fas fa-cogs fa-fw mr-2"></i>Impostazioni Sito</a>
                                </div>
                            </li>
                        <?php endif; ?>
                        
                        <li class="nav-item dropdown">
                             <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-user-circle fa-fw"></i> <?php echo htmlspecialchars($current_username); ?>
                            </a>
                            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="<?php echo generate_url('edit_profile'); ?>"><i class="fas fa-user-edit fa-fw mr-2"></i>Modifica Profilo</a>
                                <a class="dropdown-item" href="<?php echo generate_url('change_password'); ?>"><i class="fas fa-key fa-fw mr-2"></i>Cambia Password</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="<?php echo generate_url('logout'); ?>"><i class="fas fa-sign-out-alt fa-fw mr-2"></i>Logout</a>
                            </div>
                        </li>
                    <?php elseif($can_generate_urls): // Utente non loggato ?>
                        <li class="nav-item <?php if(strpos($_SERVER['REQUEST_URI'], generate_url('login', [], false)) === 0) echo 'active';?>">
                            <a class="nav-link" href="<?php echo generate_url('login'); ?>">Login</a>
                        </li>
                        <li class="nav-item <?php if(strpos($_SERVER['REQUEST_URI'], generate_url('register', [], false)) === 0) echo 'active';?>">
                            <a class="nav-link" href="<?php echo generate_url('register'); ?>">Registrati</a>
                        </li>
                    <?php else: // Fallback se generate_url non è disponibile ?>
                        <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                        <li class="nav-item"><a class="nav-link" href="register.php">Registrati</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
    </header>
    <main role="main" class="container mt-4">
        <?php 
        // Visualizzazione Flash Messages
        if (isset($_SESSION['flash_message'])) {
            $flash_type = $_SESSION['flash_type'] ?? 'info';
            $allowed_flash_types = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
            if (!in_array($flash_type, $allowed_flash_types)) { $flash_type = 'info'; }
            echo '<div class="alert alert-' . htmlspecialchars($flash_type) . ' alert-dismissible fade show" role="alert">'
                 . $_SESSION['flash_message'] 
                 . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>'
                 . '</div>';
            unset($_SESSION['flash_message']);
            unset($_SESSION['flash_type']);
        }
        ?>