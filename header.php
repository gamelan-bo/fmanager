<?php
// includes/header.php
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/functions_settings.php';
require_once __DIR__ . '/functions_csrf.php';
if (!function_exists('adjust_brightness') && file_exists(__DIR__ . '/functions_utils.php')) {
    require_once __DIR__ . '/functions_utils.php';
} elseif (!function_exists('adjust_brightness')) {
    function adjust_brightness($hex, $steps) { $steps = max(-255, min(255, $steps)); $hex = str_replace('#', '', $hex); if (strlen($hex) == 3) { $hex = str_repeat(substr($hex,0,1), 2) . str_repeat(substr($hex,1,1), 2) . str_repeat(substr($hex,2,1), 2); } $r = max(0, min(255, hexdec(substr($hex,0,2)) + $steps)); $g = max(0, min(255, hexdec(substr($hex,2,2)) + $steps)); $b = max(0, min(255, hexdec(substr($hex,4,2)) + $steps)); return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);}
}

if (session_status() == PHP_SESSION_NONE) { if (defined('SESSION_NAME')) session_name(SESSION_NAME); session_start(); }
if (!isset($_SESSION['initiated'])) { session_regenerate_id(true); $_SESSION['initiated'] = true; }
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) { /* ... gestione timeout ... */ }
$_SESSION['last_activity'] = time();

// Recupero impostazioni base e logo
$db_site_name = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'File Share'));
$db_site_logo_url = get_site_setting('site_logo_url', ''); 
$db_site_logo_max_height = get_site_setting('site_logo_max_height', '50'); 
$db_site_logo_max_width = get_site_setting('site_logo_max_width', '0'); 
$db_site_logo_alignment = get_site_setting('site_logo_alignment', 'center'); 

// Recupero colori tema
$theme_navbar_bg = get_site_setting('theme_navbar_bg', '#343a40');
$theme_navbar_text = get_site_setting('theme_navbar_text', '#ffffff');
$theme_navbar_text_hover = get_site_setting('theme_navbar_text_hover', '#f8f9fa');
$theme_footer_bg = get_site_setting('theme_footer_bg', '#f8f9fa');
$theme_footer_text_color = get_site_setting('theme_footer_text_color', '#6c757d'); // NUOVO
$theme_accent_color = get_site_setting('theme_accent_color', '#007bff'); 
$theme_global_font_family = get_site_setting('theme_global_font_family', ''); // NUOVO

$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_role = $_SESSION['user_role'] ?? null;
$current_username = $_SESSION['username'] ?? '';

// Preparazione Stili Dinamici per il Tema
$dynamic_theme_styles = "<style id=\"custom-theme-dynamic-styles\">\n";

// Font Globale
if (!empty($theme_global_font_family) && $theme_global_font_family !== '') {
    $dynamic_theme_styles .= "  body, .btn, .form-control, input, select, textarea, h1, h2, h3, h4, h5, h6, .table {\n";
    $dynamic_theme_styles .= "    font-family: " . htmlspecialchars($theme_global_font_family) . " !important;\n  }\n";
}

// Navbar
if ($theme_navbar_bg) { $dynamic_theme_styles .= "  .navbar.custom-theme-navbar { background-color: " . htmlspecialchars($theme_navbar_bg) . " !important; }\n"; }
if ($theme_navbar_text && function_exists('adjust_brightness')) {
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .navbar-nav .nav-link,\n  .navbar-dark.custom-theme-navbar .navbar-brand,\n  .navbar-dark.custom-theme-navbar .navbar-brand span.site-name-text { color: " . htmlspecialchars($theme_navbar_text) . " !important; }\n";
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .dropdown-menu a.dropdown-item { color: #212529 !important; }\n"; 
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .dropdown-menu a.dropdown-item:hover, .navbar-dark.custom-theme-navbar .dropdown-menu a.dropdown-item:focus { color: #000 !important; background-color: #e9ecef !important; }\n";
    $navbar_hover_effective_color = !empty($theme_navbar_text_hover) ? $theme_navbar_text_hover : adjust_brightness($theme_navbar_text, 30);
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .navbar-nav .nav-link:hover, .navbar-dark.custom-theme-navbar .navbar-nav .nav-item.active .nav-link { color: " . htmlspecialchars($navbar_hover_effective_color) . " !important; }\n";
    $toggler_icon_color_urlencoded = urlencode(htmlspecialchars($theme_navbar_text));
    $dynamic_theme_styles .= "  .navbar-dark.custom-theme-navbar .navbar-toggler-icon { background-image: url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='30' height='30' viewBox='0 0 30 30'%3e%3cpath stroke='" . $toggler_icon_color_urlencoded . "' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e\") !important; }\n";
}
// Footer
if ($theme_footer_bg) { $dynamic_theme_styles .= "  .footer.custom-theme-footer { background-color: " . htmlspecialchars($theme_footer_bg) . " !important; }\n"; }
if ($theme_footer_text_color) { $dynamic_theme_styles .= "  .footer.custom-theme-footer, .footer.custom-theme-footer .text-muted, .footer.custom-theme-footer span { color: " . htmlspecialchars($theme_footer_text_color) . " !important; }\n"; }

// Accent Color
if ($theme_accent_color && function_exists('adjust_brightness')) {
    $accent_hover = adjust_brightness($theme_accent_color, -20); $accent_border = adjust_brightness($theme_accent_color, -20); $accent_border_hover = adjust_brightness($theme_accent_color, -40);
    $dynamic_theme_styles .= "  a:not(.nav-link):not(.btn):not(.dropdown-item):not(.page-link):not(.navbar-brand) { color: " . htmlspecialchars($theme_accent_color) . "; }\n";
    $dynamic_theme_styles .= "  a:not(.nav-link):not(.btn):not(.dropdown-item):not(.page-link):not(.navbar-brand):hover { color: " . htmlspecialchars($accent_hover) . "; }\n";
    $dynamic_theme_styles .= "  .btn-primary { background-color: " . htmlspecialchars($theme_accent_color) . " !important; border-color: " . htmlspecialchars($accent_border) . " !important; }\n";
    $dynamic_theme_styles .= "  .btn-primary:hover { background-color: " . htmlspecialchars($accent_hover) . " !important; border-color: " . htmlspecialchars($accent_border_hover) . " !important; }\n";
    $dynamic_theme_styles .= "  .pagination .page-item.active .page-link { background-color: " . htmlspecialchars($theme_accent_color) . "; border-color: " . htmlspecialchars($theme_accent_color) . "; }\n";
}
$dynamic_theme_styles .= "</style>\n";

// Logica per Google Fonts
$google_font_link_tag = '';
$google_fonts_map_for_header = [ // Duplico la mappa qui per non includere admin_settings.php
    '"Roboto", sans-serif' => 'Roboto:wght@400;700', '"Open Sans", sans-serif' => 'Open+Sans:wght@400;700',
    '"Lato", sans-serif' => 'Lato:wght@400;700', '"Montserrat", sans-serif' => 'Montserrat:wght@400;700',
    '"Noto Sans", sans-serif' => 'Noto+Sans:wght@400;700'
];
if (!empty($theme_global_font_family) && isset($google_fonts_map_for_header[$theme_global_font_family])) {
    $font_query = $google_fonts_map_for_header[$theme_global_font_family];
    $google_font_link_tag = '<link href="https://fonts.googleapis.com/css2?family=' . $font_query . '&display=swap" rel="stylesheet">' . "\n";
}

// Logica visualizzazione logo
$logo_display_url_header = ''; /* ... come prima ... */ $logo_container_style_attr = ''; /* ... come prima ... */ $logo_img_style_attr = ''; /* ... come prima ... */
if ($db_site_logo_url && defined('PROJECT_ROOT') && defined('SITE_URL')) { $physical_logo_path_on_server = PROJECT_ROOT . '/' . ltrim($db_site_logo_url, '/'); if (is_file($physical_logo_path_on_server)) { $logo_display_url_header = rtrim(SITE_URL, '/') . '/' . ltrim($db_site_logo_url, '/'); }}
$logo_container_style_attr = 'text-align: ' . htmlspecialchars($db_site_logo_alignment) . ';';
$logo_img_styles_array = ["width: auto", "height: auto"];
if (!empty($db_site_logo_max_height)&&(int)$db_site_logo_max_height>0) $logo_img_styles_array[]="max-height: ".htmlspecialchars($db_site_logo_max_height)."px";
if (!empty($db_site_logo_max_width)&&(int)$db_site_logo_max_width>0) $logo_img_styles_array[]="max-width: ".htmlspecialchars($db_site_logo_max_width)."px";
$logo_img_style_attr = implode('; ', $logo_img_styles_array) . ';';
?>
<!DOCTYPE html><html lang="it"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($db_site_name); ?> - <?php echo htmlspecialchars($page_title ?? 'Benvenuto'); ?></title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" referrerpolicy="no-referrer" />
    <?php echo $google_font_link_tag; // Inserisci il link al Google Font se necessario ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo $dynamic_theme_styles; ?>
    <?php if (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY && RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SITE_KEY !== 'LA_TUA_SITE_KEY_RECAPTCHA'): ?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
</head><body>
    <header>
        <?php if ($logo_display_url_header): ?>
        <div class="site-logo-header-container" style="<?php echo $logo_container_style_attr; ?>">
            <a href="index.php"><img src="<?php echo htmlspecialchars($logo_display_url_header); ?>?t=<?php echo time(); ?>" alt="Logo <?php echo htmlspecialchars($db_site_name); ?>" class="top-site-logo-img" style="<?php echo $logo_img_style_attr; ?>"></a>
        </div>
        <?php endif; ?>
        <nav class="navbar navbar-expand-lg navbar-dark custom-theme-navbar <?php if (!$logo_display_url_header && basename($_SERVER['PHP_SELF']) !== 'index.php') echo 'mb-3'; ?>">
            <a class="navbar-brand" href="index.php"><span class="site-name-text"><?php if (!$logo_display_url_header || true) { echo htmlspecialchars($db_site_name); } ?></span></a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse justify-content-center" id="navbarNav">
                <ul class="navbar-nav"> 
                    <?php if ($current_user_id): ?>
                        <li class="nav-item <?php if(basename($_SERVER['PHP_SELF'])=='index.php') echo 'active';?>"><a class="nav-link" href="index.php">Dashboard</a></li>
                        <li class="nav-item <?php if(basename($_SERVER['PHP_SELF'])=='my_files.php') echo 'active';?>"><a class="nav-link" href="my_files.php">I Miei File</a></li>
                        <?php if ($current_user_role === 'Admin'): $admin_pages = ['admin_pending_users.php','admin_users.php','admin_settings.php','admin_folders.php','admin_manage_folder.php','admin_files.php','admin.php']; $is_admin_page_active = in_array(basename($_SERVER['PHP_SELF']), $admin_pages);?>
                            <li class="nav-item dropdown <?php if($is_admin_page_active) echo 'active'; ?>"><a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-toggle="dropdown">Admin Panel</a>
                                <div class="dropdown-menu" aria-labelledby="adminDropdown">
                                    <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='admin_pending_users.php') echo 'active';?>" href="admin_pending_users.php"><i class="fas fa-user-check fa-fw mr-2"></i>Valida Utenti</a>
                                    <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='admin_users.php') echo 'active';?>" href="admin_users.php"><i class="fas fa-users-cog fa-fw mr-2"></i>Gestione Utenti</a>
                                    <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='admin_folders.php'||basename($_SERVER['PHP_SELF'])=='admin_manage_folder.php') echo 'active';?>" href="admin_folders.php"><i class="fas fa-folder-open fa-fw mr-2"></i>Gestione Cartelle</a>
                                    <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='admin_files.php') echo 'active';?>" href="admin_files.php"><i class="fas fa-archive fa-fw mr-2"></i>Gestione File</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='admin_settings.php') echo 'active';?>" href="admin_settings.php"><i class="fas fa-cogs fa-fw mr-2"></i>Impostazioni Sito</a>
                                </div></li>
                        <?php endif; ?>
                        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown"><i class="fas fa-user-circle fa-fw"></i> <?php echo htmlspecialchars($current_username); ?></a>
                            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                                <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='edit_profile.php') echo 'active';?>" href="edit_profile.php"><i class="fas fa-user-edit fa-fw mr-2"></i>Modifica Profilo</a>
                                <a class="dropdown-item <?php if(basename($_SERVER['PHP_SELF'])=='change_password.php') echo 'active';?>" href="change_password.php"><i class="fas fa-key fa-fw mr-2"></i>Cambia Password</a>
                                <div class="dropdown-divider"></div><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt fa-fw mr-2"></i>Logout</a>
                            </div></li>
                    <?php else: ?>
                        <li class="nav-item <?php if(basename($_SERVER['PHP_SELF'])=='login.php') echo 'active';?>"><a class="nav-link" href="login.php">Login</a></li>
                        <li class="nav-item <?php if(basename($_SERVER['PHP_SELF'])=='register.php') echo 'active';?>"><a class="nav-link" href="register.php">Registrati</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>
    </header>
    <main role="main" class="container mt-4">
        <?php if (isset($_SESSION['flash_message'])) { $flash_type = $_SESSION['flash_type'] ?? 'info'; echo '<div class="alert alert-' . htmlspecialchars($flash_type) . ' alert-dismissible fade show" role="alert">' . $_SESSION['flash_message'] . '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>'; unset($_SESSION['flash_message']); unset($_SESSION['flash_type']); } ?>