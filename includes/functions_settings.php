<?php
// includes/functions_settings.php
require_once __DIR__ . '/../config.php'; // Per fallback o altre costanti, se necessario
require_once __DIR__ . '/db_connection.php';

/**
 * Recupera un'impostazione specifica del sito dal database.
 *
 * @param string $setting_key La chiave dell'impostazione (es. 'site_name').
 * @param mixed $default_value Valore da restituire se l'impostazione non è trovata (opzionale).
 * @return string|null Il valore dell'impostazione o il valore di default.
 */
function get_site_setting($setting_key, $default_value = null) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
    if (!$stmt) {
        log_error("Errore prepare get_site_setting per key {$setting_key}: " . $conn->error, __FILE__, __LINE__);
        return $default_value;
    }
    $stmt->bind_param("s", $setting_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $setting = $result->fetch_assoc();
    $stmt->close();

    if ($setting) {
        return $setting['setting_value'];
    }
    return $default_value;
}

/**
 * Recupera tutte le impostazioni del sito dal database.
 *
 * @return array Un array associativo delle impostazioni (chiave => valore).
 */
function get_all_site_settings() {
    $conn = get_db_connection();
    $settings = [];
    $sql = "SELECT setting_key, setting_value FROM site_settings";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        $result->free();
    } else {
        log_error("Errore in get_all_site_settings: " . $conn->error, __FILE__, __LINE__);
    }
    return $settings;
}

/**
 * Aggiorna (o inserisce se non esiste) un'impostazione del sito.
 *
 * @param string $setting_key La chiave dell'impostazione.
 * @param string $setting_value Il nuovo valore per l'impostazione.
 * @return bool True se l'operazione ha avuto successo, false altrimenti.
 */
function update_site_setting($setting_key, $setting_value) {
    $conn = get_db_connection();
    // Utilizza INSERT ... ON DUPLICATE KEY UPDATE per inserire o aggiornare.
    // Assicurati che 'setting_key' sia una PRIMARY KEY o UNIQUE KEY nella tabella site_settings.
    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) {
        log_error("Errore prepare update_site_setting per key {$setting_key}: " . $conn->error, __FILE__, __LINE__);
        return false;
    }
    $stmt->bind_param("ss", $setting_key, $setting_value);
    if ($stmt->execute()) {
        $stmt->close();
        log_activity("Impostazione sito aggiornata: {$setting_key} = " . substr($setting_value, 0, 50) . "...", $_SESSION['user_id'] ?? null);
        return true;
    } else {
        $error_msg = $stmt->error;
        $stmt->close();
        log_error("Errore execute update_site_setting per key {$setting_key}: " . $error_msg, __FILE__, __LINE__);
        return false;
    }
}

?>