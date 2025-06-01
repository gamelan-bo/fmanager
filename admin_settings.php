<?php
// /var/www/html/fm/admin_settings.php
$page_title = "Impostazioni Sito";

require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_settings.php'; 
require_once __DIR__ . '/includes/functions_utils.php'; 

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_admin(); 

// Lista dei font predefiniti disponibili
$available_fonts = [
    '' => 'Predefinito del Tema/Bootstrap', // Stringa vuota per fallback
    'Arial, Helvetica, sans-serif' => 'Arial, Helvetica (sans-serif)',
    '"Times New Roman", Times, serif' => 'Times New Roman (serif)',
    'Verdana, Geneva, sans-serif' => 'Verdana (sans-serif)',
    'Georgia, serif' => 'Georgia (serif)',
    'Consolas, "Courier New", monospace' => 'Consolas, Courier (monospace)',
    '"Roboto", sans-serif' => 'Roboto (Google Font)',
    '"Open Sans", sans-serif' => 'Open Sans (Google Font)',
    '"Lato", sans-serif' => 'Lato (Google Font)',
    '"Montserrat", sans-serif' => 'Montserrat (Google Font)',
    '"Noto Sans", sans-serif' => 'Noto Sans (Google Font)'
];
$google_fonts_map = [ // Mappa per caricare i Google Fonts
    '"Roboto", sans-serif' => 'Roboto:wght@400;700',
    '"Open Sans", sans-serif' => 'Open+Sans:wght@400;700',
    '"Lato", sans-serif' => 'Lato:wght@400;700',
    '"Montserrat", sans-serif' => 'Montserrat:wght@400;700',
    '"Noto Sans", sans-serif' => 'Noto+Sans:wght@400;700'
];


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore CSRF."; $_SESSION['flash_type'] = 'danger';
    } else {
        $all_saved = true; $flash_messages_array = []; 
        $current_settings_for_post_fallback = get_all_site_settings();
        
        $new_site_name = trim($_POST['site_name'] ?? ($current_settings_for_post_fallback['site_name'] ?? SITE_NAME));
        $new_footer_text = trim($_POST['footer_text'] ?? ($current_settings_for_post_fallback['footer_text'] ?? ''));
        $new_aging_enabled = isset($_POST['aging_enabled']) ? '1' : '0';
        $new_aging_delete_grace_period_days = (int)($_POST['aging_delete_grace_period_days'] ?? ($current_settings_for_post_fallback['aging_delete_grace_period_days'] ?? '7'));
        if ($new_aging_delete_grace_period_days < 0) $new_aging_delete_grace_period_days = 0;
        $new_site_logo_max_height = (int)($_POST['site_logo_max_height'] ?? ($current_settings_for_post_fallback['site_logo_max_height'] ?? '50'));
        if ($new_site_logo_max_height < 20 || $new_site_logo_max_height > 300) $new_site_logo_max_height = 50;
        $new_site_logo_max_width = (int)($_POST['site_logo_max_width'] ?? ($current_settings_for_post_fallback['site_logo_max_width'] ?? '0'));
        if ($new_site_logo_max_width < 0 || $new_site_logo_max_width > 500) $new_site_logo_max_width = 0;
        $new_site_logo_alignment = $_POST['site_logo_alignment'] ?? ($current_settings_for_post_fallback['site_logo_alignment'] ?? 'center');
        if (!in_array($new_site_logo_alignment, ['left', 'center', 'right'])) $new_site_logo_alignment = 'center';

        $new_theme_navbar_bg = trim($_POST['theme_navbar_bg'] ?? ($current_settings_for_post_fallback['theme_navbar_bg'] ?? '#343a40'));
        $new_theme_navbar_text = trim($_POST['theme_navbar_text'] ?? ($current_settings_for_post_fallback['theme_navbar_text'] ?? '#ffffff'));
        $new_theme_navbar_text_hover = trim($_POST['theme_navbar_text_hover'] ?? ($current_settings_for_post_fallback['theme_navbar_text_hover'] ?? '#f8f9fa'));
        $new_theme_footer_bg = trim($_POST['theme_footer_bg'] ?? ($current_settings_for_post_fallback['theme_footer_bg'] ?? '#f8f9fa'));
        $new_theme_footer_text_color = trim($_POST['theme_footer_text_color'] ?? ($current_settings_for_post_fallback['theme_footer_text_color'] ?? '#6c757d')); // NUOVO
        $new_theme_accent_color = trim($_POST['theme_accent_color'] ?? ($current_settings_for_post_fallback['theme_accent_color'] ?? '#007bff'));
        $new_theme_global_font_family = $_POST['theme_global_font_family'] ?? ($current_settings_for_post_fallback['theme_global_font_family'] ?? ''); // NUOVO
        if (!array_key_exists($new_theme_global_font_family, $available_fonts)) $new_theme_global_font_family = '';


        if (update_site_setting('site_name', $new_site_name) === false) $all_saved = false;
        if (update_site_setting('footer_text', $new_footer_text) === false) $all_saved = false;
        if (update_site_setting('aging_enabled', $new_aging_enabled) === false) $all_saved = false;
        if (update_site_setting('aging_delete_grace_period_days', (string)$new_aging_delete_grace_period_days) === false) $all_saved = false;
        if (update_site_setting('site_logo_max_height', (string)$new_site_logo_max_height) === false) $all_saved = false;
        if (update_site_setting('site_logo_max_width', (string)$new_site_logo_max_width) === false) $all_saved = false;
        if (update_site_setting('site_logo_alignment', $new_site_logo_alignment) === false) $all_saved = false;
        if (update_site_setting('theme_navbar_bg', $new_theme_navbar_bg) === false) $all_saved = false;
        if (update_site_setting('theme_navbar_text', $new_theme_navbar_text) === false) $all_saved = false;
        if (update_site_setting('theme_navbar_text_hover', $new_theme_navbar_text_hover) === false) $all_saved = false;
        if (update_site_setting('theme_footer_bg', $new_theme_footer_bg) === false) $all_saved = false;
        if (update_site_setting('theme_footer_text_color', $new_theme_footer_text_color) === false) $all_saved = false; // Salva nuovo
        if (update_site_setting('theme_accent_color', $new_theme_accent_color) === false) $all_saved = false;
        if (update_site_setting('theme_global_font_family', $new_theme_global_font_family) === false) $all_saved = false; // Salva nuovo

        // Gestione Upload/Rimozione Logo (come prima)
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] == UPLOAD_ERR_OK) { /* ... come prima ... */ }
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') { /* ... come prima ... */ }
        
        // Gestione messaggi flash (come prima)
        if (!empty($flash_messages_array)) { /* ... come prima ... */ }
        elseif ($all_saved) { $_SESSION['flash_message'] = "Impostazioni aggiornate."; $_SESSION['flash_type'] = 'success'; }
        else { $_SESSION['flash_message'] = "Errore salvataggio."; $_SESSION['flash_type'] = 'danger'; }
    }
    header("Location: admin_settings.php"); exit; 
}

$current_settings = get_all_site_settings();
$site_name_db = $current_settings['site_name'] ?? SITE_NAME;
$footer_text_db = $current_settings['footer_text'] ?? ('© ' . date('Y') . ' ' . htmlspecialchars($site_name_db));
$current_logo_url = $current_settings['site_logo_url'] ?? '';
$site_logo_max_height_db = $current_settings['site_logo_max_height'] ?? '50';
$site_logo_max_width_db = $current_settings['site_logo_max_width'] ?? '0'; 
$site_logo_alignment_db = $current_settings['site_logo_alignment'] ?? 'center';
$theme_navbar_bg_db = $current_settings['theme_navbar_bg'] ?? '#343a40';
$theme_navbar_text_db = $current_settings['theme_navbar_text'] ?? '#ffffff';
$theme_navbar_text_hover_db = $current_settings['theme_navbar_text_hover'] ?? '#f8f9fa';
$theme_footer_bg_db = $current_settings['theme_footer_bg'] ?? '#f8f9fa';
$theme_footer_text_color_db = $current_settings['theme_footer_text_color'] ?? '#6c757d'; // NUOVO
$theme_accent_color_db = $current_settings['theme_accent_color'] ?? '#007bff';
$theme_global_font_family_db = $current_settings['theme_global_font_family'] ?? ''; // NUOVO
$aging_enabled_db = $current_settings['aging_enabled'] ?? '0'; 
$aging_delete_grace_period_days_db = $current_settings['aging_delete_grace_period_days'] ?? '7'; 

$page_title = "Impostazioni Sito";
require_once __DIR__ . '/includes/header.php'; 
?>
<h2><i class="fas fa-cogs"></i> Impostazioni Generali del Sito</h2>
<form action="admin_settings.php" method="POST" class="needs-validation mt-3" novalidate enctype="multipart/form-data">
    <?php echo csrf_input_field(); ?>

    <fieldset class="mb-4 p-3 border rounded">
        <legend class="w-auto px-2 h5"><i class="fas fa-id-card"></i> Identità del Sito</legend>
        <div class="form-group">
            <label for="site_name">Nome del Sito:</label>
            <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo htmlspecialchars($site_name_db); ?>" required maxlength="100">
        </div>
        <div class="form-group">
            <label for="site_logo">Logo del Sito (opzionale):</label>
            <input type="file" class="form-control-file" id="site_logo" name="site_logo" accept="image/jpeg,image/png,image/gif,image/svg+xml">
            <small class="form-text text-muted">Consigliato PNG trasparente o SVG. Max 2MB.</small>
            <?php  $logo_preview_display_url = ''; if ($current_logo_url && defined('PROJECT_ROOT') && defined('SITE_URL')) { $physical_logo_preview_path = PROJECT_ROOT . '/' . ltrim($current_logo_url, '/'); if (is_file($physical_logo_preview_path)) { $logo_preview_display_url = rtrim(SITE_URL, '/') . '/' . ltrim($current_logo_url, '/'); }}
            if ($logo_preview_display_url): ?>
                <div class="mt-2"><p class="mb-1">Logo attuale:</p><img src="<?php echo htmlspecialchars($logo_preview_display_url); ?>?t=<?php echo time(); ?>" alt="Logo Attuale" style="max-height: <?php echo htmlspecialchars($site_logo_max_height_db); ?>px; <?php if (!empty($site_logo_max_width_db) && (int)$site_logo_max_width_db > 0): ?>max-width: <?php echo htmlspecialchars($site_logo_max_width_db); ?>px;<?php endif; ?> width: auto; height: auto; background-color: #eee; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo_check"><label class="form-check-label" for="remove_logo_check">Rimuovi logo</label></div></div>
            <?php elseif ($current_logo_url): ?><p class="text-danger mt-2"><small>Logo impostato (<?php echo htmlspecialchars($current_logo_url);?>) non trovato.</small></p><?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4"><label for="site_logo_max_height">Altezza Max Logo (px):</label><input type="number" class="form-control form-control-sm" id="site_logo_max_height" name="site_logo_max_height" value="<?php echo htmlspecialchars($site_logo_max_height_db); ?>" min="20" max="300"></div>
            <div class="form-group col-md-4"><label for="site_logo_max_width">Larghezza Max Logo (px):</label><input type="number" class="form-control form-control-sm" id="site_logo_max_width" name="site_logo_max_width" value="<?php echo htmlspecialchars($site_logo_max_width_db); ?>" min="0" max="500"><small class="form-text text-muted">0 per auto.</small></div>
            <div class="form-group col-md-4"><label for="site_logo_alignment">Allineamento Logo:</label><select class="form-control form-control-sm" id="site_logo_alignment" name="site_logo_alignment"><option value="left" <?php if($site_logo_alignment_db === 'left') echo 'selected'; ?>>Sinistra</option><option value="center" <?php if($site_logo_alignment_db === 'center') echo 'selected'; ?>>Centro</option><option value="right" <?php if($site_logo_alignment_db === 'right') echo 'selected'; ?>>Destra</option></select></div>
        </div>
    </fieldset>

    <fieldset class="mb-4 p-3 border rounded">
        <legend class="w-auto px-2 h5"><i class="fas fa-palette"></i> Personalizzazione Tema</legend>
        <div class="form-group">
            <label for="theme_global_font_family">Carattere Principale del Sito:</label>
            <select class="form-control form-control-sm" id="theme_global_font_family" name="theme_global_font_family">
                <?php foreach ($available_fonts as $font_stack => $font_name): ?>
                    <option value="<?php echo htmlspecialchars($font_stack); ?>" <?php if ($theme_global_font_family_db === $font_stack) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($font_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">Alcuni caratteri (es. Google Fonts) verranno caricati dinamicamente se selezionati.</small>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6 col-lg-3"><label for="theme_navbar_bg">Sfondo Navbar:</label><input type="color" class="form-control" id="theme_navbar_bg" name="theme_navbar_bg" value="<?php echo htmlspecialchars($theme_navbar_bg_db); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_navbar_text">Testo Navbar:</label><input type="color" class="form-control" id="theme_navbar_text" name="theme_navbar_text" value="<?php echo htmlspecialchars($theme_navbar_text_db); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_navbar_text_hover">Testo Navbar (Hover):</label><input type="color" class="form-control" id="theme_navbar_text_hover" name="theme_navbar_text_hover" value="<?php echo htmlspecialchars($theme_navbar_text_hover_db); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_accent_color">Accento Principale:</label><input type="color" class="form-control" id="theme_accent_color" name="theme_accent_color" value="<?php echo htmlspecialchars($theme_accent_color_db); ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6 col-lg-3"><label for="theme_footer_bg">Sfondo Footer:</label><input type="color" class="form-control" id="theme_footer_bg" name="theme_footer_bg" value="<?php echo htmlspecialchars($theme_footer_bg_db); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_footer_text_color">Testo Footer:</label><input type="color" class="form-control" id="theme_footer_text_color" name="theme_footer_text_color" value="<?php echo htmlspecialchars($theme_footer_text_color_db); ?>"></div>
        </div>
    </fieldset>

    <fieldset class="mb-4 p-3 border rounded"><legend class="w-auto px-2 h5"><i class="fas fa-shoe-prints"></i> Footer</legend><div class="form-group"><label for="footer_text">Testo del Footer:</label><textarea class="form-control" id="footer_text" name="footer_text" rows="3" maxlength="500"><?php echo htmlspecialchars($footer_text_db); ?></textarea></div></fieldset>
    <fieldset class="mb-4 p-3 border rounded"><legend class="w-auto px-2 h5"><i class="fas fa-history"></i> Policy File Aging</legend><div class="form-group"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="aging_enabled" name="aging_enabled" value="1" <?php if($aging_enabled_db === '1') echo 'checked'; ?>><label class="custom-control-label" for="aging_enabled">Abilita cancellazione automatica</label></div><small class="form-text text-muted">Richiede script cron.</small></div> <div class="form-group"><label for="aging_delete_grace_period_days">Periodo grazia file scaduti e non scaricati (giorni):</label><input type="number" class="form-control form-control-sm" style="max-width:120px;" id="aging_delete_grace_period_days" name="aging_delete_grace_period_days" value="<?php echo htmlspecialchars($aging_delete_grace_period_days_db); ?>" min="0" max="3650"><small class="form-text text-muted">Se file ha `expiry_date` passata E `download_count=0`, eliminato dopo X giorni da `expiry_date`.</small></div></fieldset>
    
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salva Impostazioni</button>
    <a href="index.php" class="btn btn-secondary ml-2">Torna Dashboard Admin</a>
</form>
<?php require_once __DIR__ . '/includes/footer.php'; ?>