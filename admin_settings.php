<?php
// /var/www/html/fm-new/admin_settings.php

// BLOCCO 1: Inclusioni PHP per logica, sessione, autenticazione
require_once __DIR__ . '/config.php'; 
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions_csrf.php';
require_once __DIR__ . '/includes/functions_auth.php';   
require_once __DIR__ . '/includes/functions_settings.php'; 
// functions_utils.php è incluso da header.php, non serve qui direttamente

if (session_status() == PHP_SESSION_NONE) {
    if (defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
require_admin(); 
$current_admin_id = $_SESSION['user_id'];

// Lista dei font (deve essere qui per il form)
$available_fonts = [
    '' => 'Predefinito del Tema/Bootstrap', 
    'Arial, Helvetica, sans-serif' => 'Arial, Helvetica (sans-serif)',
    '"Times New Roman", Times, serif' => 'Times New Roman (serif)',
    '"Montserrat", sans-serif' => 'Montserrat (Google Font)',
    // Aggiungi altri se necessario
];

// BLOCCO 2: Gestione della richiesta POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    log_activity("Admin (ID:{$current_admin_id}) ha inviato il form di admin_settings.php.");
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Errore di sicurezza (CSRF). Azione annullata.";
        $_SESSION['flash_type'] = 'danger';
        log_error("CSRF token validation failed in admin_settings.php");
    } else {
        $all_saved_successfully = true; 
        
        $settings_to_update = [
            'site_name' => trim($_POST['site_name'] ?? ''), 'footer_text' => trim($_POST['footer_text'] ?? ''),
            'site_logo_max_height' => (int)($_POST['site_logo_max_height'] ?? 50), 'site_logo_max_width' => (int)($_POST['site_logo_max_width'] ?? 0),
            'site_logo_alignment' => $_POST['site_logo_alignment'] ?? 'center', 'theme_navbar_bg' => trim($_POST['theme_navbar_bg'] ?? '#343a40'),
            'theme_navbar_text' => trim($_POST['theme_navbar_text'] ?? '#ffffff'), 'theme_navbar_text_hover' => trim($_POST['theme_navbar_text_hover'] ?? '#f8f9fa'),
            'theme_footer_bg' => trim($_POST['theme_footer_bg'] ?? '#f8f9fa'), 'theme_footer_text_color' => trim($_POST['theme_footer_text_color'] ?? '#6c757d'),
            'theme_accent_color' => trim($_POST['theme_accent_color'] ?? '#007bff'), 'theme_global_font_family' => $_POST['theme_global_font_family'] ?? '',
            'aging_enabled' => isset($_POST['aging_enabled']) ? '1' : '0',
            'aging_delete_grace_period_days' => (string)((int)($_POST['aging_delete_grace_period_days'] ?? 7))
        ];
        
        // ... (Validazioni per i valori, come prima) ...

        foreach ($settings_to_update as $key => $value) {
            if (update_site_setting($key, $value) === false) { $all_saved_successfully = false; log_error("Fallito salvataggio impostazione: {$key}"); }
        }

        // --- GESTIONE LOGO CON LOGGING DETTAGLIATO ---
        $logo_upload_dir = PROJECT_ROOT . '/SystemImages/';
        log_activity("[LogoDebug] Cartella di destinazione logo: {$logo_upload_dir}");

        if (!is_dir($logo_upload_dir)) { @mkdir($logo_upload_dir, 0755, true); }
        
        if (!is_writable($logo_upload_dir)) {
            log_error("[LogoDebug] ERRORE: La cartella SystemImages/ non è scrivibile: {$logo_upload_dir}");
            $_SESSION['flash_message'] = ($_SESSION['flash_message'] ?? '') . "<br>Errore Critico: La cartella SystemImages/ non è scrivibile. Impossibile gestire il logo.";
            $_SESSION['flash_type'] = 'danger';
            $all_saved_successfully = false;
        } else {
            log_activity("[LogoDebug] La cartella SystemImages/ è scrivibile.");
            // 1. Gestione Rimozione Logo
            if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
                log_activity("[LogoDebug] Rilevata richiesta di rimozione logo.");
                $current_logo_path_db = get_site_setting('site_logo_url', '');
                if (!empty($current_logo_path_db)) {
                    $physical_logo_to_remove = PROJECT_ROOT . '/' . ltrim($current_logo_path_db, '/');
                    if (is_file($physical_logo_to_remove)) {
                        if (@unlink($physical_logo_to_remove)) {
                            log_activity("[LogoDebug] Logo rimosso fisicamente: " . $physical_logo_to_remove);
                            if(!update_site_setting('site_logo_url', '')) { $all_saved_successfully = false; log_error("[LogoDebug] Fallito nel pulire site_logo_url nel DB dopo l'unlink."); }
                        } else {
                            log_error("[LogoDebug] Errore durante l'unlink del file logo fisico: ".$physical_logo_to_remove);
                            $_SESSION['flash_message'] = ($_SESSION['flash_message'] ?? '') . "<br>Errore: Impossibile rimuovere il file logo fisico."; $_SESSION['flash_type'] = 'danger'; $all_saved_successfully = false;
                        }
                    } else { 
                         log_warning("[LogoDebug] Path logo nel DB ('{$current_logo_path_db}') ma file non trovato su disco: {$physical_logo_to_remove}. Pulisco DB.");
                         update_site_setting('site_logo_url', ''); 
                    }
                }
            // 2. Gestione Upload Nuovo Logo
            } elseif (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] == UPLOAD_ERR_OK) {
                log_activity("[LogoDebug] Rilevato file in upload: " . print_r($_FILES['site_logo'], true));
                $logo_file = $_FILES['site_logo'];
                $logo_file_type = function_exists('mime_content_type') ? mime_content_type($logo_file['tmp_name']) : $logo_file['type'];
                log_activity("[LogoDebug] Tipo MIME rilevato: {$logo_file_type}");

                $allowed_logo_types = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
                if ($logo_file['size'] > (2 * 1024 * 1024)) {
                    $_SESSION['flash_message'] = "Errore: Logo troppo grande (max 2MB)."; $_SESSION['flash_type'] = 'danger'; $all_saved_successfully = false;
                    log_warning("[LogoDebug] Upload fallito: file troppo grande (".$logo_file['size']." bytes).");
                } elseif (!in_array($logo_file_type, $allowed_logo_types)) {
                    $_SESSION['flash_message'] = "Errore: Tipo file logo non consentito."; $_SESSION['flash_type'] = 'danger'; $all_saved_successfully = false;
                    log_warning("[LogoDebug] Upload fallito: tipo file non consentito ({$logo_file_type}).");
                } else {
                    $extension = strtolower(pathinfo($logo_file['name'], PATHINFO_EXTENSION));
                    $new_logo_filename = 'site_logo.' . $extension; 
                    $logo_destination_abs = $logo_upload_dir . $new_logo_filename;
                    $logo_db_path = 'SystemImages/' . $new_logo_filename; 
                    log_activity("[LogoDebug] Preparazione spostamento file a: {$logo_destination_abs}");
                    
                    if (move_uploaded_file($logo_file['tmp_name'], $logo_destination_abs)) {
                        log_activity("[LogoDebug] move_uploaded_file() ha avuto SUCCESSO. File spostato in: {$logo_destination_abs}");
                        log_activity("[LogoDebug] Ora tento di salvare '{$logo_db_path}' nel DB con chiave 'site_logo_url'.");
                        if(update_site_setting('site_logo_url', $logo_db_path)) {
                            log_activity("[LogoDebug] Path logo '{$logo_db_path}' salvato con successo nel database.");
                        } else {
                            log_error("[LogoDebug] FALLITO salvataggio path logo '{$logo_db_path}' nel database.");
                            $all_saved_successfully = false;
                        }
                    } else { 
                        $_SESSION['flash_message'] = "Errore critico: Impossibile spostare il file logo caricato. Controlla i permessi e la configurazione PHP."; $_SESSION['flash_type'] = 'danger'; $all_saved_successfully = false;
                        log_error("[LogoDebug] move_uploaded_file() ha FALLITO. Errore PHP codice: " . ($logo_file['error'] ?? 'N/D'));
                    }
                }
            }
        }
        
        if ($all_saved_successfully && !isset($_SESSION['flash_message'])) { 
            $_SESSION['flash_message'] = "Impostazioni aggiornate con successo."; $_SESSION['flash_type'] = 'success';
        }
    }
    header("Location: " . generate_url('admin_settings')); 
    exit; 
}

// --- HTML DELLA PAGINA ---
$current_settings = get_all_site_settings(true); // true forza refresh dal DB
// ... (TUTTA LA LOGICA PER RECUPERARE LE IMPOSTAZIONI E MOSTRARLE NEL FORM, come da ultima versione completa) ...
$page_title = "Impostazioni Sito";
require_once __DIR__ . '/includes/header.php'; 
?>

<h2><i class="fas fa-cogs"></i> Impostazioni Generali del Sito</h2>
<form action="<?php echo generate_url('admin_settings'); ?>" method="POST" class="needs-validation mt-3" novalidate enctype="multipart/form-data">
    <?php echo csrf_input_field(); ?>
    <fieldset class="mb-4 p-3 border rounded">
        <legend class="w-auto px-2 h5"><i class="fas fa-id-card"></i> Identità del Sito</legend>
        <div class="form-group">
            <label for="site_name">Nome del Sito:</label>
            <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo htmlspecialchars($current_settings['site_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="site_logo">Logo del Sito (opzionale):</label>
            <input type="file" class="form-control-file" id="site_logo" name="site_logo" accept="image/jpeg,image/png,image/gif,image/svg+xml">
            <small class="form-text text-muted">Sovrascriverà il logo attuale. Max 2MB.</small>
            <?php
            $logo_preview_url = '';
            if (!empty($current_settings['site_logo_url']) && is_file(PROJECT_ROOT . '/' . $current_settings['site_logo_url'])) {
                $logo_preview_url = SITE_URL . '/' . $current_settings['site_logo_url'];
            }
            if ($logo_preview_url):
            ?>
                <div class="mt-2">
                    <p class="mb-1">Logo attuale:</p>
                    <img src="<?php echo $logo_preview_url; ?>?t=<?php echo time(); ?>" alt="Logo Attuale" style="max-height: <?php echo htmlspecialchars($current_settings['site_logo_max_height'] ?? '50'); ?>px; background-color: #eee; padding: 5px; border: 1px solid #ddd;">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo_check">
                        <label class="form-check-label" for="remove_logo_check">Rimuovi logo attuale</label>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4"><label for="site_logo_max_height">Altezza Max Logo (px):</label><input type="number" class="form-control form-control-sm" id="site_logo_max_height" name="site_logo_max_height" value="<?php echo htmlspecialchars($current_settings['site_logo_max_height'] ?? '50'); ?>"></div>
            <div class="form-group col-md-4"><label for="site_logo_max_width">Larghezza Max Logo (px):</label><input type="number" class="form-control form-control-sm" id="site_logo_max_width" name="site_logo_max_width" value="<?php echo htmlspecialchars($current_settings['site_logo_max_width'] ?? '0'); ?>"><small class="form-text text-muted">0 per auto.</small></div>
            <div class="form-group col-md-4"><label for="site_logo_alignment">Allineamento Logo:</label><select class="form-control form-control-sm" id="site_logo_alignment" name="site_logo_alignment"><option value="left" <?php if(($current_settings['site_logo_alignment'] ?? 'center') === 'left') echo 'selected';?>>Sinistra</option><option value="center" <?php if(($current_settings['site_logo_alignment'] ?? 'center') === 'center') echo 'selected';?>>Centro</option><option value="right" <?php if(($current_settings['site_logo_alignment'] ?? 'center') === 'right') echo 'selected';?>>Destra</option></select></div>
        </div>
    </fieldset>

    <fieldset class="mb-4 p-3 border rounded">
        <legend class="w-auto px-2 h5"><i class="fas fa-palette"></i> Personalizzazione Tema</legend>
        <div class="form-group"><label for="theme_global_font_family">Carattere Principale:</label><select class="form-control form-control-sm" id="theme_global_font_family" name="theme_global_font_family"><?php foreach ($available_fonts as $font_stack => $font_name): ?><option value="<?php echo htmlspecialchars($font_stack); ?>" <?php if (($current_settings['theme_global_font_family'] ?? '') === $font_stack) echo 'selected'; ?>><?php echo htmlspecialchars($font_name); ?></option><?php endforeach; ?></select></div>
        <div class="form-row">
            <div class="form-group col-md-6 col-lg-3"><label for="theme_navbar_bg">Sfondo Navbar:</label><input type="color" class="form-control" id="theme_navbar_bg" name="theme_navbar_bg" value="<?php echo htmlspecialchars($current_settings['theme_navbar_bg'] ?? '#343a40'); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_navbar_text">Testo Navbar:</label><input type="color" class="form-control" id="theme_navbar_text" name="theme_navbar_text" value="<?php echo htmlspecialchars($current_settings['theme_navbar_text'] ?? '#ffffff'); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_navbar_text_hover">Testo Navbar (Hover):</label><input type="color" class="form-control" id="theme_navbar_text_hover" name="theme_navbar_text_hover" value="<?php echo htmlspecialchars($current_settings['theme_navbar_text_hover'] ?? '#f8f9fa'); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_accent_color">Accento Principale:</label><input type="color" class="form-control" id="theme_accent_color" name="theme_accent_color" value="<?php echo htmlspecialchars($current_settings['theme_accent_color'] ?? '#007bff'); ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6 col-lg-3"><label for="theme_footer_bg">Sfondo Footer:</label><input type="color" class="form-control" id="theme_footer_bg" name="theme_footer_bg" value="<?php echo htmlspecialchars($current_settings['theme_footer_bg'] ?? '#f8f9fa'); ?>"></div>
            <div class="form-group col-md-6 col-lg-3"><label for="theme_footer_text_color">Testo Footer:</label><input type="color" class="form-control" id="theme_footer_text_color" name="theme_footer_text_color" value="<?php echo htmlspecialchars($current_settings['theme_footer_text_color'] ?? '#6c757d'); ?>"></div>
        </div>
    </fieldset>

    <fieldset class="mb-4 p-3 border rounded"><legend class="w-auto px-2 h5"><i class="fas fa-shoe-prints"></i> Footer</legend><div class="form-group"><label for="footer_text">Testo Footer:</label><textarea class="form-control" id="footer_text" name="footer_text" rows="3"><?php echo htmlspecialchars($current_settings['footer_text'] ?? ''); ?></textarea></div></fieldset>
    <fieldset class="mb-4 p-3 border rounded"><legend class="w-auto px-2 h5"><i class="fas fa-history"></i> Policy File Aging</legend><div class="form-group"><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="aging_enabled" name="aging_enabled" value="1" <?php if(!empty($current_settings['aging_enabled'])) echo 'checked'; ?>><label class="custom-control-label" for="aging_enabled">Abilita cancellazione automatica</label></div></div> <div class="form-group"><label for="aging_delete_grace_period_days">Periodo grazia file scaduti (giorni):</label><input type="number" class="form-control form-control-sm" style="max-width:120px;" id="aging_delete_grace_period_days" name="aging_delete_grace_period_days" value="<?php echo htmlspecialchars($current_settings['aging_delete_grace_period_days'] ?? '7'); ?>" min="0"></div></fieldset>
    
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salva Impostazioni</button>
    <a href="<?php echo generate_url('admin_dashboard'); ?>" class="btn btn-secondary ml-2">Torna Dashboard Admin</a>
</form>

<?php
require_once __DIR__ . '/includes/footer.php';
?>