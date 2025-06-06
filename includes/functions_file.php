<?php
// includes/functions_file.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db_connection.php';

if (!function_exists('is_admin')) { 
    require_once __DIR__ . '/functions_auth.php';
}
if (!function_exists('ensure_folder_path_exists_and_get_relative')) { 
    require_once __DIR__ . '/functions_folder.php';
}

/**
 * Gestisce l'upload di un singolo file, con supporto per folder_id.
 */
function handle_file_upload($file_input, $user_id, $description = null, $folder_id = null) {
    $conn = get_db_connection();
    $user_stmt = $conn->prepare("SELECT quota_bytes, used_space_bytes FROM users WHERE id = ?");
    if (!$user_stmt) { log_error("DB Error HFU (user_stmt prep): " . $conn->error); return ['success' => false, 'message' => 'Errore info utente.']; }
    $user_stmt->bind_param("i", $user_id); $user_stmt->execute(); $user_data = $user_stmt->get_result()->fetch_assoc(); $user_stmt->close();
    if (!$user_data) return ['success' => false, 'message' => 'Utente non valido.'];

    $user_quota = (int)$user_data['quota_bytes']; $user_used_space = (int)$user_data['used_space_bytes'];
    if (isset($file_input['error']) && $file_input['error'] !== UPLOAD_ERR_OK) {
        switch ($file_input['error']) {
            case UPLOAD_ERR_NO_FILE: return ['success' => false, 'message' => 'Nessun file inviato.'];
            case UPLOAD_ERR_INI_SIZE: case UPLOAD_ERR_FORM_SIZE: return ['success' => false, 'message' => 'File troppo grande.'];
            default: return ['success' => false, 'message' => 'Errore upload (Cod.: ' . $file_input['error'] . ').'];
        }
    }
    if (!isset($file_input['error'])) return ['success' => false, 'message' => 'Dati file errati.'];
    $file_size = (int)$file_input['size'];
    if ($file_size <= 0) return ['success' => false, 'message' => 'File vuoto.'];
    if (($user_used_space + $file_size) > $user_quota) return ['success' => false, 'message' => 'Spazio insuff.'];
    
    $original_filename = basename($file_input['name']);
    $temp_filename = strip_tags($original_filename);
    $sanitized_original_filename = preg_replace('/[[:cntrl:]]/', '', $temp_filename);
    if (empty($sanitized_original_filename)) $sanitized_original_filename = "file_" . time();
    
    $file_tmp_path = $file_input['tmp_name'];
    if (!is_uploaded_file($file_tmp_path)) { log_error("File non valido: {$file_tmp_path}"); return ['success' => false, 'message' => 'Err sicurezza file.']; }
    $file_type = mime_content_type($file_tmp_path);

    $user_nas_base_path = rtrim(NAS_ROOT_PATH, '/') . '/user_files/' . $user_id . '/';
    if ($folder_id === '' || $folder_id === 0) $folder_id = null;
    $relative_folder_path_for_storage = ensure_folder_path_exists_and_get_relative($folder_id, $user_id, $user_nas_base_path);
    if ($relative_folder_path_for_storage === false) return ['success' => false, 'message' => 'Errore cartella destinazione.'];

    $path_parts = pathinfo($sanitized_original_filename);
    $filename_no_ext = $path_parts['filename'] ?? 'file_' . time();
    $extension = isset($path_parts['extension']) ? $path_parts['extension'] : '';
    $safe_filename_no_ext = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename_no_ext);
    $safe_extension_only = preg_replace('/[^a-zA-Z0-9]/', '', $extension); 
    $safe_extension = !empty($safe_extension_only) ? '.' . $safe_extension_only : '';
    $stored_filename = uniqid($safe_filename_no_ext . '_', true) . $safe_extension;
    
    $full_file_destination = $user_nas_base_path . $relative_folder_path_for_storage . $stored_filename;
    $db_file_path = $user_id . '/' . $relative_folder_path_for_storage . $stored_filename;

    if (!move_uploaded_file($file_tmp_path, $full_file_destination)) { log_error("Err spostamento: {$file_tmp_path} a {$full_file_destination}"); return ['success' => false, 'message' => 'Err salvataggio.'];}
    @chmod($full_file_destination, 0640);

    // expiry_date di default è NULL nel DB, quindi non lo includiamo nell'INSERT se non specificato
    $stmt_insert = $conn->prepare("INSERT INTO files (user_id, folder_id, original_filename, stored_filename, file_path, file_type, file_size_bytes, description, upload_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt_insert) { log_error("DB Error HFU (insert prep): " . $conn->error); @unlink($full_file_destination); return ['success' => false, 'message' => 'Err DB info file.']; }
    $stmt_insert->bind_param("iissssis", $user_id, $folder_id, $sanitized_original_filename, $stored_filename, $db_file_path, $file_type, $file_size, $description);

    if ($stmt_insert->execute()) {
        $file_id = $stmt_insert->insert_id; $stmt_insert->close();
        $new_used_space = $user_used_space + $file_size;
        $stmt_update_quota = $conn->prepare("UPDATE users SET used_space_bytes = ? WHERE id = ?");
        if ($stmt_update_quota) { $stmt_update_quota->bind_param("ii", $new_used_space, $user_id); $stmt_update_quota->execute(); $stmt_update_quota->close(); }
        else log_error("Errore aggiornamento quota utente ID {$user_id}: " . $conn->error);
        log_activity("File caricato: {$sanitized_original_filename} (ID: {$file_id}) in folder ID: ".($folder_id ?? 'Root')." da user ID: {$user_id}", $user_id);
        return ['success' => true, 'message' => 'File "' . htmlspecialchars($sanitized_original_filename) . '" caricato con successo!', 'file_id' => $file_id];
    } else {
        log_error("DB Error HFU (insert exec): " . $stmt_insert->error); $stmt_insert->close(); @unlink($full_file_destination);
        return ['success' => false, 'message' => 'Errore DB salvataggio info file.'];
    }
}

/**
 * Recupera i file visualizzabili in una data cartella.
 */
function get_files_in_folder_for_user_display($folder_id, $requesting_user_id, $is_requester_admin) {
    $conn = get_db_connection(); $files = [];
    $sql = "SELECT f.id, f.user_id AS file_owner_id, f.original_filename, f.stored_filename, f.file_path, f.file_type, 
                   f.file_size_bytes, f.description, f.upload_date, f.last_download_date, 
                   f.download_count, f.public_link_token, f.public_link_expires_at, f.expiry_date,
                   u.username AS owner_username 
            FROM files f JOIN users u ON f.user_id = u.id WHERE f.is_deleted = FALSE ";
    $types = ""; $params = [];
    if ($folder_id === null) { $sql .= "AND f.folder_id IS NULL "; } 
    else { $sql .= "AND f.folder_id = ? "; $types .= "i"; $params[] = $folder_id; }
    $sql .= "ORDER BY f.original_filename ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { log_error("DB Err (prep get_files_in_folder): " . $conn->error); return $files; }
    if (!empty($types)) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { log_error("DB Err (exec get_files_in_folder): " . $stmt->error); $stmt->close(); return $files; }
    $result = $stmt->get_result(); if ($result) { while ($row = $result->fetch_assoc()) $files[] = $row; $result->free(); }
    $stmt->close(); return $files;
}

/**
 * Ottiene i dettagli di un file specifico per il download (privato).
 */
function get_file_for_download($file_id, $user_id_requesting_download) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT f.id, f.user_id, f.folder_id, f.original_filename, f.stored_filename, f.file_path, f.file_type, f.file_size_bytes FROM files f WHERE f.id = ? AND f.is_deleted = FALSE");
    if (!$stmt) { log_error("DB Error GFFD (prepare): " . $conn->error); return null; }
    $stmt->bind_param("i", $file_id); $stmt->execute(); $file = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$file) return null;
    $is_owner = ($file['user_id'] == $user_id_requesting_download);
    $is_requester_admin = is_admin(); 
    if ($is_owner || $is_requester_admin) return $file;
    log_activity("Download non autorizzato file ID {$file_id} da user ID {$user_id_requesting_download}", $user_id_requesting_download); 
    return null; 
}

/**
 * Aggiorna i metadati di un file dopo il download (privato).
 */
function record_file_download($file_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("UPDATE files SET last_download_date = NOW(), download_count = download_count + 1 WHERE id = ?");
    if ($stmt) { $stmt->bind_param("i", $file_id); $stmt->execute(); $stmt->close(); }
    else log_error("DB Error record_file_download ID {$file_id}: " . $conn->error);
}

/**
 * Esegue il "soft delete" di un file.
 */
function soft_delete_file($file_id, $acting_user_id, $is_system_action = false) {
    $conn = get_db_connection(); $conn->begin_transaction();
    try {
        $stmt_file = $conn->prepare("SELECT user_id, file_size_bytes, is_deleted FROM files WHERE id = ?");
        if (!$stmt_file) throw new Exception("DB Prepare (file details): " . $conn->error);
        $stmt_file->bind_param("i", $file_id); $stmt_file->execute(); $file = $stmt_file->get_result()->fetch_assoc(); $stmt_file->close();
        if (!$file) { $conn->rollback(); return ['success' => false, 'message' => 'File non trovato.']; }
        if ($file['is_deleted'] && !$is_system_action) { $conn->rollback(); return ['success' => false, 'message' => 'File già eliminato.']; }
        $file_owner_id = $file['user_id']; $file_size = (int)$file['file_size_bytes']; $can_delete = false;
        if ($is_system_action) { $can_delete = true; } 
        elseif ($acting_user_id !== null) { if ($file_owner_id == $acting_user_id || is_admin()) $can_delete = true; }
        if (!$can_delete) { $conn->rollback(); log_activity("Cancellazione non autorizzata file ID {$file_id}", $acting_user_id); return ['success' => false, 'message' => 'Permesso negato.'];}
        $stmt_delete = $conn->prepare("UPDATE files SET is_deleted = TRUE, deleted_at = NOW(), deleted_by_user_id = ? WHERE id = ?");
        if (!$stmt_delete) throw new Exception("DB Prepare (soft delete): " . $conn->error);
        $stmt_delete->bind_param("ii", $acting_user_id, $file_id);
        if (!$stmt_delete->execute()) throw new Exception("DB Execute (soft delete): " . $stmt_delete->error);
        $stmt_delete->close();
        if (!$file['is_deleted']) {
            $stmt_quota = $conn->prepare("UPDATE users SET used_space_bytes = GREATEST(0, used_space_bytes - ?) WHERE id = ?");
            if (!$stmt_quota) throw new Exception("DB Prepare (update quota): " . $conn->error);
            $stmt_quota->bind_param("ii", $file_size, $file_owner_id);
            if (!$stmt_quota->execute()) throw new Exception("DB Execute (update quota): " . $stmt_quota->error);
            $stmt_quota->close();
        }
        $conn->commit();
        $actor_log_id = $is_system_action ? 'Sistema (Cron)' : "Utente ID {$acting_user_id}";
        log_activity("File ID {$file_id} marcato eliminato da {$actor_log_id}.", ($is_system_action ? null : $acting_user_id));
        return ['success' => true, 'message' => 'File contrassegnato come eliminato.'];
    } catch (Exception $e) { $conn->rollback(); log_error("Errore soft_delete_file: " . $e->getMessage()); return ['success' => false, 'message' => 'Errore eliminazione file.']; }
}

/**
 * Ripristina un file precedentemente eliminato (soft delete) da un amministratore.
 */
function admin_restore_file($file_id, $admin_id) {
    if (!is_admin()) { return ['success' => false, 'message' => 'Azione non permessa.']; }
    $conn = get_db_connection(); $conn->begin_transaction();
    try {
        $stmt_file = $conn->prepare("SELECT user_id, file_size_bytes, is_deleted FROM files WHERE id = ?");
        if (!$stmt_file) throw new Exception("DB Err (prep restore details): " . $conn->error);
        $stmt_file->bind_param("i", $file_id); $stmt_file->execute(); $file = $stmt_file->get_result()->fetch_assoc(); $stmt_file->close();
        if (!$file) { $conn->rollback(); return ['success' => false, 'message' => 'File non trovato.']; }
        if (!$file['is_deleted']) { $conn->rollback(); return ['success' => false, 'message' => 'File non eliminato.']; }
        $file_owner_id = $file['user_id']; $file_size = (int)$file['file_size_bytes'];
        $stmt_restore = $conn->prepare("UPDATE files SET is_deleted = FALSE, deleted_at = NULL, deleted_by_user_id = NULL WHERE id = ?");
        if (!$stmt_restore) throw new Exception("DB Err (prep restore): " . $conn->error);
        $stmt_restore->bind_param("i", $file_id);
        if (!$stmt_restore->execute()) throw new Exception("DB Err (exec restore): " . $stmt_restore->error);
        $stmt_restore->close();
        $stmt_quota = $conn->prepare("UPDATE users SET used_space_bytes = used_space_bytes + ? WHERE id = ?");
        if (!$stmt_quota) throw new Exception("DB Err (prep quota restore): " . $conn->error);
        $stmt_quota->bind_param("ii", $file_size, $file_owner_id);
        if (!$stmt_quota->execute()) throw new Exception("DB Err (exec quota restore): " . $stmt_quota->error);
        $stmt_quota->close(); $conn->commit();
        log_activity("Admin (ID: {$admin_id}) ripristinato file ID: {$file_id}. Spazio utente ID {$file_owner_id} aggiornato.", $admin_id);
        return ['success' => true, 'message' => 'File ripristinato.'];
    } catch (Exception $e) { $conn->rollback(); log_error("Errore admin_restore_file: " . $e->getMessage()); return ['success' => false, 'message' => 'Errore ripristino file: ' . $e->getMessage()];}
}

/**
 * Elimina definitivamente un file (record DB e file fisico) da un amministratore.
 */
function admin_hard_delete_file($file_id, $admin_id) {
    if (!is_admin()) { return ['success' => false, 'message' => 'Azione non permessa.']; }
    $conn = get_db_connection(); $conn->begin_transaction();
    try {
        $stmt_file = $conn->prepare("SELECT user_id, file_size_bytes, file_path, original_filename, is_deleted FROM files WHERE id = ?");
        if (!$stmt_file) throw new Exception("DB Err (prep hard_del details): " . $conn->error);
        $stmt_file->bind_param("i", $file_id); $stmt_file->execute(); $file = $stmt_file->get_result()->fetch_assoc(); $stmt_file->close();
        if (!$file) { $conn->rollback(); return ['success' => false, 'message' => 'File non trovato.']; }
        $file_owner_id = $file['user_id']; $file_size = (int)$file['file_size_bytes'];
        $full_physical_path = rtrim(NAS_ROOT_PATH, '/') . '/user_files/' . $file['file_path'];
        $was_soft_deleted = $file['is_deleted'];
        if (file_exists($full_physical_path) && is_file($full_physical_path)) {
            if (!@unlink($full_physical_path)) { $unlink_error = error_get_last()['message'] ?? 'Errore unlink'; throw new Exception("Fallita eliminazione fisica: {$full_physical_path}. Errore: {$unlink_error}"); }
            log_activity("Admin (ID: {$admin_id}) eliminato fisicamente file: {$full_physical_path} (File ID: {$file_id})", $admin_id);
        } else { log_warning("Admin Hard Delete: File fisico non trovato ({$full_physical_path}) per File ID {$file_id}."); }
        $stmt_delete_db = $conn->prepare("DELETE FROM files WHERE id = ?");
        if (!$stmt_delete_db) throw new Exception("DB Err (prep hard_del DB): " . $conn->error);
        $stmt_delete_db->bind_param("i", $file_id);
        if (!$stmt_delete_db->execute()) throw new Exception("DB Err (exec hard_del DB): " . $stmt_delete_db->error);
        $stmt_delete_db->close();
        if (!$was_soft_deleted) {
            $stmt_quota = $conn->prepare("UPDATE users SET used_space_bytes = GREATEST(0, used_space_bytes - ?) WHERE id = ?");
            if (!$stmt_quota) throw new Exception("DB Err (prep quota hard_del): " . $conn->error);
            $stmt_quota->bind_param("ii", $file_size, $file_owner_id);
            if (!$stmt_quota->execute()) throw new Exception("DB Err (exec quota hard_del): " . $stmt_quota->error);
            $stmt_quota->close(); log_activity("Quota aggiornata user ID {$file_owner_id} dopo hard delete file ID {$file_id}", $admin_id);
        }
        $conn->commit();
        log_activity("Admin (ID: {$admin_id}) eliminato definitivamente file: ".htmlspecialchars($file['original_filename'])." (ID: {$file_id}).", $admin_id);
        return ['success' => true, 'message' => 'File eliminato definitivamente.'];
    } catch (Exception $e) { $conn->rollback(); log_error("Errore admin_hard_delete_file ID {$file_id}: " . $e->getMessage()); return ['success' => false, 'message' => 'Errore eliminazione definitiva: ' . $e->getMessage()];}
}

/**
 * Recupera i dettagli di un file per la modifica, inclusa expiry_date e folder_id.
 */
function get_file_for_editing($file_id, $user_id_requesting_edit) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT id, user_id, folder_id, original_filename, description, expiry_date, is_deleted FROM files WHERE id = ?");
    if (!$stmt) { log_error("DB Error get_file_for_editing: " . $conn->error); return null; }
    $stmt->bind_param("i", $file_id); $stmt->execute(); $file = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$file) return null;
    if ($file['is_deleted']) return ['is_deleted' => true, 'folder_id' => $file['folder_id']]; // Passa folder_id anche se cancellato per redirect
    if ($file['user_id'] != $user_id_requesting_edit && !is_admin()) { log_activity("Modifica non autorizzata file ID {$file_id}", $user_id_requesting_edit); return null; }
    return $file;
}

/**
 * Aggiorna i metadati di un file (nome, descrizione, expiry_date).
 */
function update_file_metadata($file_id, $new_original_filename, $new_description, $new_expiry_date_str_or_null, $acting_user_id) {
    $conn = get_db_connection();
    $file_check = get_file_for_editing($file_id, $acting_user_id);
    if (!$file_check || (isset($file_check['is_deleted']) && $file_check['is_deleted'])) return ['success' => false, 'message' => 'Accesso negato, file non trovato o eliminato.'];
    $temp_fn_meta = strip_tags(trim($new_original_filename));
    $sane_orig_fn = preg_replace('/[[:cntrl:]]/', '', $temp_fn_meta);
    if (empty($sane_orig_fn)) return ['success' => false, 'message' => 'Nome file non vuoto.'];
    $sane_desc = $new_description !== null ? trim(htmlspecialchars($new_description, ENT_QUOTES, 'UTF-8')) : null;
    if ($sane_desc !== null && mb_strlen($sane_desc) > 500) return ['success' => false, 'message' => 'Descrizione max 500 caratteri.'];
    $expiry_date_to_save = null;
    if ($new_expiry_date_str_or_null === null) { $expiry_date_to_save = null; } 
    elseif (!empty($new_expiry_date_str_or_null)) {
        try { $date_obj = new DateTime($new_expiry_date_str_or_null); $expiry_date_to_save = $date_obj->format('Y-m-d H:i:s'); } 
        catch (Exception $e) { return ['success' => false, 'message' => 'Formato data scadenza non valido.']; }
    }
    $stmt = $conn->prepare("UPDATE files SET original_filename = ?, description = ?, expiry_date = ? WHERE id = ?");
    if (!$stmt) { log_error("DB Err (prep upd_file_meta): " . $conn->error); return ['success' => false, 'message' => 'Errore DB.']; }
    $stmt->bind_param("sssi", $sane_orig_fn, $sane_desc, $expiry_date_to_save, $file_id);
    if ($stmt->execute()) { $stmt->close(); log_activity("Metadati (scad: ".($expiry_date_to_save ?? 'NULL').") file ID {$file_id} da user ID {$acting_user_id}", $acting_user_id); return ['success' => true, 'message' => 'Dettagli file aggiornati.']; }
    else { log_error("DB Err (exec upd_file_meta): " . $stmt->error); $stmt->close(); return ['success' => false, 'message' => 'Errore DB aggiornamento.'];}
}

/**
 * Genera un link di download pubblico per un file.
 */
function generate_public_link($file_id, $acting_user_id, $expiry_days = null) {
    $conn = get_db_connection();
    $stmt_check = $conn->prepare("SELECT id FROM files WHERE id = ? AND is_deleted = FALSE");
    if (!$stmt_check) { log_error("DB Err (gen_pub_link check): " . $conn->error); return ['success' => false, 'message' => 'Errore verifica file.']; }
    $stmt_check->bind_param("i", $file_id); $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows === 0) { $stmt_check->close(); return ['success' => false, 'message' => 'File non trovato o cancellato.']; }
    $stmt_check->close(); $token = bin2hex(random_bytes(16)); $expires_at_sql = null;
    if ($expiry_days !== null && is_numeric($expiry_days) && $expiry_days > 0) { $expires_at_sql = date('Y-m-d H:i:s', time() + ($expiry_days*86400)); }
    $stmt_update = $conn->prepare("UPDATE files SET public_link_token = ?, public_link_expires_at = ? WHERE id = ?");
    if (!$stmt_update) { log_error("DB Err (gen_pub_link update): " . $conn->error); return ['success' => false, 'message' => 'Errore DB link.']; }
    $stmt_update->bind_param("ssi", $token, $expires_at_sql, $file_id);
    if ($stmt_update->execute()) { $stmt_update->close(); $public_url = rtrim(SITE_URL, '/') . '/public_download.php?token=' . $token; log_activity("Link pubblico generato file ID {$file_id} da user ID {$acting_user_id}", $acting_user_id); return ['success' => true, 'message' => 'Link pubblico generato!', 'public_url' => $public_url, 'token' => $token, 'expires_at' => $expires_at_sql]; }
    else { log_error("DB Err (gen_pub_link exec): " . $stmt_update->error); $stmt_update->close(); return ['success' => false, 'message' => 'Errore DB link.']; }
}

/**
 * Revoca un link di download pubblico esistente per un file.
 */
function revoke_public_link($file_id, $acting_user_id) {
    $conn = get_db_connection();
    $stmt_owner_check = $conn->prepare("SELECT user_id FROM files WHERE id = ? AND is_deleted = FALSE");
    if (!$stmt_owner_check) { log_error("DB Err (revoke_link owner check): ".$conn->error); return ['success' => false, 'message' => 'Errore DB.']; }
    $stmt_owner_check->bind_param("i", $file_id); $stmt_owner_check->execute(); $file_data = $stmt_owner_check->get_result()->fetch_assoc(); $stmt_owner_check->close();
    if (!$file_data) return ['success' => false, 'message' => 'File non trovato.'];
    if ($file_data['user_id'] != $acting_user_id && !is_admin()) { log_activity("Tentativo non autorizzato revoca link file ID {$file_id}", $acting_user_id); return ['success' => false, 'message' => 'Permesso negato.']; }
    $stmt_update = $conn->prepare("UPDATE files SET public_link_token = NULL, public_link_expires_at = NULL WHERE id = ?");
    if (!$stmt_update) { log_error("DB Err (revoke_link prep): " . $conn->error); return ['success' => false, 'message' => 'Errore DB.']; }
    $stmt_update->bind_param("i", $file_id);
    if ($stmt_update->execute()) {
        if ($stmt_update->affected_rows > 0) { $stmt_update->close(); log_activity("Link pubblico revocato file ID {$file_id}", $acting_user_id); return ['success' => true, 'message' => 'Link pubblico revocato.']; }
        else { $stmt_update->close(); return ['success' => true, 'message' => 'Nessun link da revocare.']; }
    } else { log_error("DB Err (revoke_link exec): " . $stmt_update->error); $stmt_update->close(); return ['success' => false, 'message' => 'Errore DB.']; }
}

/**
 * Recupera i dettagli di un file basandosi sul token pubblico.
 */
function get_file_by_public_token($token) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT id, user_id, original_filename, stored_filename, file_path, file_type, file_size_bytes, public_link_expires_at FROM files WHERE public_link_token = ? AND is_deleted = FALSE");
    if (!$stmt) { log_error("DB Err (get_file_by_token prep): " . $conn->error); return null; }
    $stmt->bind_param("s", $token); $stmt->execute(); $file = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$file) return null;
    if ($file['public_link_expires_at'] !== null && time() > strtotime($file['public_link_expires_at'])) { log_activity("Accesso link scaduto: {$token}"); return ['expired' => true]; }
    return $file;
}
?>