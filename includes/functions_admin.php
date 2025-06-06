<?php
// includes/functions_admin.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db_connection.php';

if (session_status() == PHP_SESSION_NONE) {
    if(defined('SESSION_NAME')) session_name(SESSION_NAME);
    session_start();
}
// Assicura che le funzioni di autenticazione siano disponibili, se non già incluse
if (!function_exists('is_admin')) { 
    if (file_exists(__DIR__ . '/functions_auth.php')) {
        require_once __DIR__ . '/functions_auth.php';
    } elseif (defined('PROJECT_ROOT') && file_exists(PROJECT_ROOT . '/includes/functions_auth.php')) {
        require_once PROJECT_ROOT . '/includes/functions_auth.php';
    } else {
        if(function_exists('log_error')) log_error("CRITICO: functions_auth.php non trovato, is_admin() potrebbe non essere disponibile.", __FILE__, __LINE__);
        // Potresti definire un fallback di is_admin() se necessario per evitare errori fatali immediati
        if (!function_exists('is_admin')) { function is_admin() { return false; } }
    }
}


/**
 * Recupera tutti gli utenti con i loro dettagli completi per la visualizzazione admin.
 */
if (!function_exists('get_all_users_with_details')) {
    function get_all_users_with_details() {
        $conn = get_db_connection(); 
        $users = [];
        $sql = "SELECT id, username, email, role, is_active, requires_admin_validation, 
                       requires_password_change, is_email_validated,
                       quota_bytes, used_space_bytes, created_at, last_login_at 
                FROM users ORDER BY username ASC";
        $result = $conn->query($sql);
        if ($result) { 
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $result->free(); 
        } else {
            if(function_exists('log_error')) log_error("Errore in get_all_users_with_details: " . $conn->error, __FILE__, __LINE__);
        }
        return $users;
    }
}

/**
 * Recupera i dettagli di un singolo utente per l'amministratore.
 */
if (!function_exists('get_user_details_for_admin')) {
    function get_user_details_for_admin($user_id) {
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT id, username, email, role, is_active, requires_admin_validation, 
                                       requires_password_change, is_email_validated, quota_bytes, used_space_bytes,
                                       created_at, last_login_at 
                                FROM users WHERE id = ?");
        if (!$stmt) { 
            if(function_exists('log_error')) log_error("DB Error (prepare get_user_details_for_admin): " . $conn->error, __FILE__, __LINE__);
            return null; 
        }
        $stmt->bind_param("i", $user_id); 
        if (!$stmt->execute()) {
            if(function_exists('log_error')) log_error("DB Error (execute get_user_details_for_admin): " . $stmt->error, __FILE__, __LINE__);
            $stmt->close();
            return null;
        }
        $user_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $user_data;
    }
}

/**
 * Recupera una lista semplificata di utenti (ID e username) per i menu a tendina (select).
 * Esclude tipicamente l'utente admin che sta visualizzando, se necessario, o utenti non attivi.
 * Per ora, recupera tutti gli utenti attivi.
 */
if (!function_exists('get_all_users_for_select')) {
    function get_all_users_for_select($exclude_user_id = null) {
        $conn = get_db_connection();
        $users = [];
        $sql = "SELECT id, username FROM users WHERE is_active = TRUE ORDER BY username ASC";
        // Se volessi escludere un utente specifico (es. l'admin stesso dalla lista dei permessi per sé):
        // if ($exclude_user_id !== null && is_numeric($exclude_user_id)) {
        // $sql = "SELECT id, username FROM users WHERE is_active = TRUE AND id != ? ORDER BY username ASC";
        // }
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            if(function_exists('log_error')) log_error("DB Error (prepare get_all_users_for_select): " . $conn->error, __FILE__, __LINE__);
            return $users;
        }
        // if ($exclude_user_id !== null && is_numeric($exclude_user_id)) {
        // $stmt->bind_param("i", $exclude_user_id);
        // }
        
        if (!$stmt->execute()) {
            if(function_exists('log_error')) log_error("DB Error (execute get_all_users_for_select): " . $stmt->error, __FILE__, __LINE__);
            $stmt->close();
            return $users;
        }
        
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $users;
    }
}


/**
 * Aggiorna i dettagli di un utente da parte dell'admin.
 */
if (!function_exists('admin_update_user_details')) {
    function admin_update_user_details($user_id, $new_username, $new_email, $new_role, $is_active_status, $new_quota_bytes, $force_password_change_status) {
        $conn = get_db_connection();
        if (empty($new_username) || strlen($new_username) < 3 || strlen($new_username) > 50 || !preg_match('/^[a-zA-Z0-9_]+$/', $new_username)) return ['success' => false, 'message' => "Username non valido."];
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => "Email non valida."];
        if ($new_role !== 'Admin' && $new_role !== 'User') return ['success' => false, 'message' => "Ruolo non valido."];
        
        // MODIFICA QUI: Se new_quota_bytes è 0 o null, lo impostiamo a NULL per il DB (illimitato)
        if ($new_quota_bytes === 0 || $new_quota_bytes === null) {
            $quota_to_save = null;
        } else {
            if (!is_numeric($new_quota_bytes) || $new_quota_bytes < 0) return ['success' => false, 'message' => "Quota non valida."];
            $quota_to_save = $new_quota_bytes;
        }

        $active_int = $is_active_status ? 1 : 0; 
        $force_pwd_change_int = $force_password_change_status ? 1 : 0;

        $stmt_check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        if (!$stmt_check) { log_error("DB Err (prep dupl check admin_update): " . $conn->error); return ['success' => false, 'message' => 'Errore DB (check duplicati).']; }
        $stmt_check->bind_param("ssi", $new_username, $new_email, $user_id); 
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) { $stmt_check->close(); return ['success' => false, 'message' => 'Nuovo username o email già in uso da un altro account.']; }
        $stmt_check->close();

        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ?, is_active = ?, quota_bytes = ?, requires_password_change = ? WHERE id = ?");
        if (!$stmt) { log_error("DB Err (prep admin_update_details): " . $conn->error); return ['success' => false, 'message' => 'Errore DB (Prepare).']; }
        // Nota: il tipo per quota_bytes deve essere 's' (stringa) o 'd' (double) se può essere null. 'i' non accetta null.
        // Usiamo 's' per la massima compatibilità con BIGINT e NULL.
        $stmt->bind_param("sssiisi", $new_username, $new_email, $new_role, $active_int, $quota_to_save, $force_pwd_change_int, $user_id);
        if ($stmt->execute()) {
            $stmt->close(); 
            $admin_id_acting = $_SESSION['user_id'] ?? 'Sistema';
            log_activity("Admin (ID: {$admin_id_acting}) ha aggiornato i dettagli per l'utente ID: {$user_id}", $admin_id_acting);
            return ['success' => true, 'message' => 'Dettagli utente aggiornati con successo.'];
        } else {
            log_error("DB Err (exec admin_update_details): " . $stmt->error); 
            $stmt->close();
            return ['success' => false, 'message' => 'Errore database durante l\'aggiornamento dei dettagli utente. Motivo: ' . $stmt->error];
        }
    }
}
/**
 * L'amministratore imposta una nuova password per l'utente.
 * Forza il cambio password al successivo login dell'utente.
 */
if (!function_exists('admin_set_user_password')) {
    function admin_set_user_password($user_id, $new_password) {
        if (empty($new_password) || strlen($new_password) < 8) {
            return ['success' => false, 'message' => "La nuova password deve essere di almeno 8 caratteri."];
        }
        $conn = get_db_connection();
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        if (!$new_password_hash) {
            if(function_exists('log_error')) log_error("Errore durante l'hashing della password per l'utente ID: {$user_id} in admin_set_user_password", __FILE__, __LINE__);
            return ['success' => false, 'message' => "Errore di sicurezza durante l'impostazione della password."];
        }
        // Imposta la nuova password e il flag per forzare il cambio al prossimo login
        $stmt = $conn->prepare("UPDATE users SET password_hash = ?, requires_password_change = TRUE WHERE id = ?");
        if (!$stmt) {
            if(function_exists('log_error')) log_error("DB Error (prepare admin_set_user_password): " . $conn->error, __FILE__, __LINE__);
            return ['success' => false, 'message' => 'Errore database durante la preparazione.'];
        }
        $stmt->bind_param("si", $new_password_hash, $user_id);
        if ($stmt->execute()) {
            $stmt->close();
            $admin_id_acting = $_SESSION['user_id'] ?? 'Sistema';
            if(function_exists('log_activity')) log_activity("Admin (ID: {$admin_id_acting}) ha impostato una nuova password per l'utente ID: {$user_id}. Cambio forzato al login.", $admin_id_acting);
            return ['success' => true, 'message' => 'Password utente aggiornata con successo. L\'utente dovrà cambiarla al prossimo login.'];
        } else {
            if(function_exists('log_error')) log_error("DB Error (execute admin_set_user_password): " . $stmt->error, __FILE__, __LINE__);
            $stmt->close();
            return ['success' => false, 'message' => 'Errore database durante l\'impostazione della password.'];
        }
    }
}

/**
 * Elimina un utente dal database (hard delete).
 * Non elimina i file fisici associati, solo il record utente e i record dipendenti con CASCADE.
 */
if (!function_exists('admin_delete_user')) {
    function admin_delete_user($user_id_to_delete, $current_admin_id) {
        if ($user_id_to_delete == $current_admin_id) {
            return ['success' => false, 'message' => 'Non puoi eliminare il tuo stesso account amministrativo da questa interfaccia.'];
        }
        $conn = get_db_connection();
        // Recupera lo username per il logging prima di eliminare
        $username_to_delete = "ID: ".$user_id_to_delete; 
        $stmt_get_username = $conn->prepare("SELECT username FROM users WHERE id = ?");
        if($stmt_get_username){ 
            $stmt_get_username->bind_param("i", $user_id_to_delete); 
            $stmt_get_username->execute(); 
            $user_data_del = $stmt_get_username->get_result()->fetch_assoc(); 
            if($user_data_del) $username_to_delete = $user_data_del['username']; 
            $stmt_get_username->close(); 
        }
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if (!$stmt) {
            if(function_exists('log_error')) log_error("DB Error (prepare admin_delete_user): " . $conn->error, __FILE__, __LINE__);
            return ['success' => false, 'message' => 'Errore database durante la preparazione dell\'eliminazione utente.'];
        }
        $stmt->bind_param("i", $user_id_to_delete);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $stmt->close();
                if(function_exists('log_activity')) log_activity("Admin (ID: {$current_admin_id}) ha eliminato l'utente: {$username_to_delete} (ID: {$user_id_to_delete}). I file fisici potrebbero rimanere.", $current_admin_id);
                return ['success' => true, 'message' => 'Utente eliminato con successo dal database. I file fisici associati non sono stati rimossi dal server NAS.'];
            } else {
                $stmt->close();
                return ['success' => false, 'message' => 'Nessun utente trovato con questo ID o utente già eliminato.'];
            }
        } else {
            $error_message_db = $stmt->error;
            $stmt->close();
            // Controlla se l'errore è dovuto a foreign key constraints (es. l'utente ha ancora file/cartelle)
            // Questo dipende da come hai impostato le foreign key (ON DELETE CASCADE, SET NULL, RESTRICT)
            if (strpos(strtolower($error_message_db), 'foreign key constraint') !== false) {
                if(function_exists('log_error')) log_error("DB Error (execute admin_delete_user - FK constraint): " . $error_message_db, __FILE__, __LINE__);
                return ['success' => false, 'message' => 'Impossibile eliminare l\'utente. Potrebbero esserci record correlati (es. file, cartelle, permessi) che impediscono l\'eliminazione. Rimuovi prima questi record o contatta il supporto tecnico.'];
            }
            if(function_exists('log_error')) log_error("DB Error (execute admin_delete_user): " . $error_message_db, __FILE__, __LINE__);
            return ['success' => false, 'message' => 'Errore database durante l\'eliminazione dell\'utente.'];
        }
    }
}


/**
 * Recupera i file per la visualizzazione dell'amministratore, con paginazione e filtri.
 * Utilizza due query separate per conteggio e dati. LIMIT/OFFSET sono hardcoded nella query dati.
 */
if (!function_exists('get_all_files_for_admin_view')) {
    function get_all_files_for_admin_view($filters = [], $page = 1, $items_per_page = 20) {
        $conn = get_db_connection();
        $files_data = [];
        
        $base_sql_from = "FROM files f 
                          JOIN users u ON f.user_id = u.id
                          LEFT JOIN folders fo ON f.folder_id = fo.id AND fo.is_deleted = FALSE 
                          LEFT JOIN users du ON f.deleted_by_user_id = du.id "; // Per vedere chi ha fatto soft-delete
        
        $where_clauses = [];
        $params_filter_for_query = []; 
        $types_filter_for_query = "";  

        // Gestione Filtro Stato (is_deleted)
        $show_deleted_status = $filters['show_deleted'] ?? 'active'; 
        if ($show_deleted_status === 'active') {
            $where_clauses[] = "f.is_deleted = FALSE";
        } elseif ($show_deleted_status === 'deleted') {
             $where_clauses[] = "f.is_deleted = TRUE";
        } // Se 'all', non si aggiunge nessun filtro specifico su f.is_deleted

        // Gestione Filtro Nome File
        if (!empty($filters['filename_filter'])) {
            $where_clauses[] = "f.original_filename LIKE ?";
            $types_filter_for_query .= "s";
            $params_filter_for_query[] = "%" . $filters['filename_filter'] . "%";
        }

        // Gestione Filtro Proprietario (Username)
        if (!empty($filters['owner_username_filter'])) {
            $where_clauses[] = "u.username LIKE ?";
            $types_filter_for_query .= "s";
            $params_filter_for_query[] = "%" . $filters['owner_username_filter'] . "%";
        }
        
        $sql_where_string = "";
        if (!empty($where_clauses)) {
            $sql_where_string = " WHERE " . implode(" AND ", $where_clauses);
        }

        // 1. Query per il conteggio totale dei file che soddisfano i filtri
        $count_sql = "SELECT COUNT(f.id) as total " . $base_sql_from . $sql_where_string;
        $stmt_count = $conn->prepare($count_sql);
        $total_files_count = 0;

        if (!$stmt_count) {
            log_error("DB Error (prepare count_sql GAFAV): " . $conn->error . " SQL: " . $count_sql, __FILE__, __LINE__);
        } else {
            if (!empty($types_filter_for_query)) {
                $stmt_count->bind_param($types_filter_for_query, ...$params_filter_for_query);
            }
            if ($stmt_count->execute()) {
                $result_count_obj = $stmt_count->get_result();
                if ($result_count_obj) {
                    $count_data = $result_count_obj->fetch_assoc();
                    if ($count_data && isset($count_data['total'])) {
                        $total_files_count = (int)$count_data['total'];
                    }
                    $result_count_obj->free();
                } else {
                     log_error("DB Error (get_result count_sql GAFAV): " . ($stmt_count->error ?: 'get_result failed'), __FILE__, __LINE__);
                }
            } else {
                log_error("DB Error (execute count_sql GAFAV): " . $stmt_count->error, __FILE__, __LINE__);
            }
            $stmt_count->close();
        }

        // Calcola i parametri di paginazione
        $page = max(1, (int)$page); 
        $items_per_page = max(1, (int)$items_per_page);
        $offset = ($page - 1) * $items_per_page;
        $total_pages = ($items_per_page > 0 && $total_files_count > 0) ? ceil($total_files_count / $items_per_page) : 1;
        $total_pages = max(1, $total_pages); 

        // 2. Query per recuperare i dati della pagina corrente
        if ($total_files_count > 0 && $page <= $total_pages) {
            $sql_select = "SELECT f.id, f.original_filename, f.file_type, f.file_size_bytes, 
                                  f.upload_date, f.is_deleted, f.deleted_at, f.expiry_date,
                                  f.user_id AS file_owner_id, u.username AS owner_username,
                                  f.folder_id, fo.folder_name,
                                  du.username as deleted_by_username ";
            $sql_order = " ORDER BY f.upload_date DESC";
            // LIMIT e OFFSET sono inseriti direttamente nella stringa SQL.
            $sql_limit_offset = " LIMIT " . (int)$items_per_page . " OFFSET " . (int)$offset;
            
            $data_sql = $sql_select . $base_sql_from . $sql_where_string . $sql_order . $sql_limit_offset;
            $stmt_data = $conn->prepare($data_sql);

            if (!$stmt_data) {
                log_error("DB Error (prepare data_sql GAFAV): " . $conn->error . " SQL: " . $data_sql, __FILE__, __LINE__);
            } else {
                // Bind solo per i parametri dei filtri, se presenti (LIMIT/OFFSET sono già nella query)
                if (!empty($types_filter_for_query)) {
                    $stmt_data->bind_param($types_filter_for_query, ...$params_filter_for_query);
                }
                
                if (!$stmt_data->execute()) {
                    log_error("DB Error (execute data_sql GAFAV): " . $stmt_data->error, __FILE__, __LINE__);
                } else {
                    $result = $stmt_data->get_result();
                    if ($result === false) {
                         log_error("DB Error (get_result data_sql GAFAV restituisce false): " . $stmt_data->error . " - Errore connessione: " . $conn->error, __FILE__, __LINE__);
                    } elseif ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $files_data[] = $row;
                        }
                        $result->free();
                    } else {
                        // Nessuna riga per questa pagina, ma non è un errore se il conteggio totale era > 0
                        if($result) $result->free();
                    }
                }
                $stmt_data->close();
            }
        }

        return [
            'files' => $files_data, 
            'total_count' => $total_files_count,
            'total_pages' => (int)$total_pages,
            'current_page' => $page,
            'items_per_page' => $items_per_page
        ];
    }
}
?>