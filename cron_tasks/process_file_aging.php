<?php
// /var/www/html/fm/cron_tasks/process_file_aging.php

// Imposta il timezone corretto se non già fatto globalmente in php.ini
// Sostituisci 'Europe/Rome' con il tuo timezone se necessario.
if (date_default_timezone_get() !== 'Europe/Rome' && !@date_default_timezone_set('Europe/Rome')) {
     error_log("CRON AGING: Impossibile impostare il timezone a Europe/Rome.");
}


define('PROJECT_ROOT', dirname(__DIR__)); 

require_once PROJECT_ROOT . '/config.php';
require_once PROJECT_ROOT . '/includes/db_connection.php';
require_once PROJECT_ROOT . '/includes/functions_settings.php';
require_once PROJECT_ROOT . '/includes/functions_file.php'; 

if (PHP_SAPI !== 'cli') {
    header("HTTP/1.1 403 Forbidden");
    echo "Accesso negato. Solo CLI.\n";
    exit;
}

function cron_log_output($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}\n";
    echo $log_message; 
    // Considera di loggare anche su un file specifico se l'output del cron non è persistente
    // error_log($log_message, 3, PROJECT_ROOT . '/LOG/cron_aging_script.log');
}

cron_log_output("=== Inizio Script File Aging ===");

$aging_enabled = get_site_setting('aging_enabled', '0');
if ($aging_enabled !== '1') {
    cron_log_output("File aging disabilitato globalmente. Uscita.");
    exit;
}

$grace_period_days = (int)get_site_setting('aging_delete_grace_period_days', '7');
cron_log_output("Policy: Elimina file con data di scadenza passata da {$grace_period_days} giorni E download_count = 0.");

$conn = get_db_connection();
if (!$conn) {
    cron_log_output("ERRORE CRITICO: Impossibile connettersi al database.");
    exit;
}

$sql = "SELECT id, user_id, file_path, original_filename, stored_filename 
        FROM files
        WHERE is_deleted = FALSE
          AND expiry_date IS NOT NULL
          AND expiry_date <= DATE_SUB(NOW(), INTERVAL ? DAY) 
          AND download_count = 0";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $error_msg = "Errore DB (prepare query file aging): " . $conn->error;
    log_error($error_msg, __FILE__, __LINE__); cron_log_output($error_msg);
    exit;
}

$stmt->bind_param("i", $grace_period_days);
if (!$stmt->execute()) {
    $error_msg = "Errore DB (execute query file aging): " . $stmt->error;
    log_error($error_msg, __FILE__, __LINE__); cron_log_output($error_msg);
    $stmt->close(); exit;
}

$result = $stmt->get_result();
$files_to_delete = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $files_to_delete[] = $row;
    }
    $result->free();
}
$stmt->close();

if (empty($files_to_delete)) {
    cron_log_output("Nessun file trovato che soddisfa i criteri di aging per l'eliminazione.");
    cron_log_output("=== Script File Aging Terminato ===");
    exit;
}

cron_log_output("Trovati " . count($files_to_delete) . " file da processare per l'eliminazione...");
$deleted_physically_count = 0; $deleted_db_count = 0;
$error_physical_count = 0; $error_db_count = 0;

foreach ($files_to_delete as $file) {
    cron_log_output("Processando File ID: {$file['id']} ('" . htmlspecialchars($file['original_filename']) . "') - Path DB: {$file['file_path']}");
    
    $full_physical_path = rtrim(NAS_ROOT_PATH, '/') . '/user_files/' . $file['file_path'];

    if (file_exists($full_physical_path) && is_file($full_physical_path)) {
        if (@unlink($full_physical_path)) {
            cron_log_output("  -> File fisico rimosso: {$full_physical_path}");
            log_activity("CRON AGING: File fisico rimosso: {$full_physical_path} (File ID: {$file['id']})", null);
            $deleted_physically_count++;
        } else {
            $unlink_error = error_get_last()['message'] ?? 'Errore sconosciuto unlink';
            cron_log_output("  -> ERRORE rimozione file fisico: {$unlink_error} ({$full_physical_path})");
            log_error("CRON AGING: Fallita rimozione fisica: {$full_physical_path} (File ID: {$file['id']}). Errore: {$unlink_error}");
            $error_physical_count++;
        }
    } else {
        cron_log_output("  -> File fisico non trovato su disco: {$full_physical_path}. Procedo con soft delete DB.");
        log_warning("CRON AGING: File fisico non trovato per File ID {$file['id']}: {$full_physical_path}. Eseguendo soft delete.");
    }

    $soft_delete_result = soft_delete_file($file['id'], null, true); // null per acting_user_id, true per is_system_action
    if ($soft_delete_result['success']) {
        cron_log_output("  -> Record DB marcato come eliminato (soft delete).");
        $deleted_db_count++;
    } else {
        cron_log_output("  -> ERRORE soft delete DB: {$soft_delete_result['message']}");
        log_error("CRON AGING: Fallito soft delete DB per File ID {$file['id']}: {$soft_delete_result['message']}");
        $error_db_count++;
    }
}

cron_log_output("--- Riepilogo File Aging ---");
cron_log_output("File totali candidati all'eliminazione: " . count($files_to_delete));
cron_log_output("File fisici eliminati con successo: {$deleted_physically_count}");
cron_log_output("Errori eliminazione fisica: {$error_physical_count}");
cron_log_output("Record DB marcati come eliminati (soft delete): {$deleted_db_count}");
cron_log_output("Errori soft delete DB: {$error_db_count}");
cron_log_output("=== Script File Aging Terminato ===");

if (defined('ADMIN_NOTIFICATION_EMAIL') && ADMIN_NOTIFICATION_EMAIL && function_exists('send_custom_email')) {
    if (count($files_to_delete) > 0 || $error_physical_count > 0 || $error_db_count > 0) { // Invia email se c'è stata attività o errori
        $report_subject = "[" . get_site_setting('site_name','FileShare') . "] Report Script File Aging";
        $report_body = "<p>Script file aging eseguito: " . date('Y-m-d H:i:s') . ".</p>" .
                       "<p>File totali processati: " . count($files_to_delete) . "</p>" .
                       "<p>File fisici eliminati: {$deleted_physically_count}</p>" .
                       "<p>Errori eliminazione fisica: {$error_physical_count}</p>" .
                       "<p>Record DB soft-deleted: {$deleted_db_count}</p>" .
                       "<p>Errori soft delete DB: {$error_db_count}</p>" .
                       "<p>Controlla i log del server per dettagli.</p>";
        send_custom_email(ADMIN_NOTIFICATION_EMAIL, $report_subject, $report_body);
    }
}
exit;
?>