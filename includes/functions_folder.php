<?php
// includes/functions_folder.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db_connection.php';

if (!function_exists('is_admin')) { 
    if (file_exists(__DIR__ . '/functions_auth.php')) {
        require_once __DIR__ . '/functions_auth.php'; 
    } elseif (defined('PROJECT_ROOT') && file_exists(PROJECT_ROOT . '/includes/functions_auth.php')) {
        require_once PROJECT_ROOT . '/includes/functions_auth.php';
    } else {
        if(function_exists('log_error')) log_error("CRITICO: functions_auth.php non trovato, is_admin() e altre funzioni di sessione potrebbero non essere disponibili.", __FILE__, __LINE__);
        // Definizioni di fallback minimali se functions_auth non è caricabile
        if (!function_exists('is_admin')) { function is_admin() { return false; } } // Potrebbe non essere sufficiente per tutto
    }
}
// Assicurati che soft_delete_file sia disponibile se delete_folder_recursive la usa
if (!function_exists('soft_delete_file')) { 
    if (file_exists(__DIR__ . '/functions_file.php')) {
        require_once __DIR__ . '/functions_file.php';
    } elseif (defined('PROJECT_ROOT') && file_exists(PROJECT_ROOT . '/includes/functions_file.php')) {
        require_once PROJECT_ROOT . '/includes/functions_file.php';
    } else {
         if(function_exists('log_error')) log_error("CRITICO: functions_file.php non trovato, soft_delete_file() potrebbe non essere disponibile.", __FILE__, __LINE__);
         // Potresti definire una funzione di fallback se strettamente necessario per evitare errori fatali immediati
         // if (!function_exists('soft_delete_file')) { function soft_delete_file($id, $uid, $sys = false) { return ['success'=>false, 'message'=>'Dipendenza mancante']; } }
    }
}

/**
 * Verifica se un utente specifico è un amministratore basandosi sul suo ID.
 */
if (!function_exists('is_admin_by_id')) {
    function is_admin_by_id($user_id) {
        if ($user_id === null || !is_numeric($user_id)) return false;
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
        if (!$stmt) { 
            if(function_exists('log_error')) log_error("DB Error (is_admin_by_id prep): " . $conn->error, __FILE__, __LINE__); 
            return false; 
        }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) { 
            if(function_exists('log_error')) log_error("DB Error (is_admin_by_id exec): " . $stmt->error, __FILE__, __LINE__); 
            $stmt->close(); return false; 
        }
        $result = $stmt->get_result(); 
        $user = $result->fetch_assoc(); 
        $stmt->close();
        return ($user && isset($user['role']) && $user['role'] === 'Admin');
    }
}

/**
 * Assicura che il percorso fisico della cartella esista sul NAS e restituisce il path relativo.
 */
if (!function_exists('ensure_folder_path_exists_and_get_relative')) {
    function ensure_folder_path_exists_and_get_relative($folder_id, $user_id, $user_nas_base_path) {
        $conn = get_db_connection(); 
        $user_nas_base_path = rtrim($user_nas_base_path, '/') . '/'; 
        
        if ($folder_id === null || $folder_id === 0 || $folder_id === '0' || $folder_id === '') { // Aggiunto controllo per stringa vuota
            if (!is_dir($user_nas_base_path)) { 
                if (!@mkdir($user_nas_base_path, 0750, true) && !is_dir($user_nas_base_path)) { 
                    if(function_exists('log_error')) log_error("Errore creazione dir base utente: " . $user_nas_base_path . " - Errore PHP: " . (error_get_last()['message'] ?? 'Sconosciuto'), __FILE__, __LINE__); 
                    return false; 
                } 
                if(function_exists('log_activity')) log_activity("Creata directory base per utente ID {$user_id}: {$user_nas_base_path}", $user_id); 
            }
            return ""; 
        }
        
        $current_id = (int)$folder_id; 
        $path_parts = []; 
        $max_depth_build = 10; // Limite per costruzione path
        $depth_count_build = 0;
        
        while ($current_id !== null && $current_id !== 0 && $depth_count_build < $max_depth_build) {
            $stmt_path = $conn->prepare("SELECT folder_name, parent_folder_id, owner_user_id FROM folders WHERE id = ? AND is_deleted = FALSE");
            if (!$stmt_path) { if(function_exists('log_error')) log_error("DB Err (ensure_folder_path prep single folder): " . $conn->error); return false; }
            $stmt_path->bind_param("i", $current_id); 
            if (!$stmt_path->execute()) { if(function_exists('log_error')) log_error("DB Err (ensure_folder_path exec single folder): " . $stmt_path->error); $stmt_path->close(); return false;}
            $folder_data = $stmt_path->get_result()->fetch_assoc(); 
            $stmt_path->close();
            
            if (!$folder_data) { if(function_exists('log_error')) log_error("Cartella ID {$current_id} non trovata o eliminata durante costruzione path."); return false; }
            
            $sanitized_folder_name_for_path = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $folder_data['folder_name']); // Ammessi anche punti e trattini
            if(empty($sanitized_folder_name_for_path)) $sanitized_folder_name_for_path = "folder_".$current_id; // Fallback se il nome sanificato è vuoto
            array_unshift($path_parts, $sanitized_folder_name_for_path);
            
            $current_id = $folder_data['parent_folder_id']; 
            if ($current_id !== null) $current_id = (int)$current_id; 
            $depth_count_build++;
        }
        if ($depth_count_build >= $max_depth_build) { if(function_exists('log_error')) log_error("Profondità massima cartelle ({$max_depth_build}) superata per folder ID {$folder_id} durante costruzione path."); return false; }
        
        $relative_path = implode('/', $path_parts); 
        if (!empty($relative_path)) { $relative_path .= '/'; }
        
        $full_physical_path = $user_nas_base_path . $relative_path;
        if (!is_dir($full_physical_path)) { 
            if (!@mkdir($full_physical_path, 0750, true) && !is_dir($full_physical_path)) { 
                if(function_exists('log_error')) log_error("Errore creazione dir fisica: " . $full_physical_path . " - Errore PHP: " . (error_get_last()['message'] ?? 'Sconosciuto'), __FILE__, __LINE__); 
                return false; 
            } 
            if(function_exists('log_activity')) log_activity("Creata directory fisica: {$full_physical_path} per folder ID {$folder_id} da utente ID {$user_id}", $user_id); 
        }
        return $relative_path; 
    }
}

/**
 * Crea una nuova cartella nel database.
 */
if (!function_exists('create_folder')) {
    function create_folder($folder_name, $parent_folder_id, $owner_user_id) {
        $conn = get_db_connection(); 
        $folder_name = trim(strip_tags($folder_name)); 
        if (empty($folder_name)) return ['success' => false, 'message' => 'Il nome della cartella non può essere vuoto.'];
        if (preg_match('/[\'^£$%&*()}{@#~?><>,|=+¬\/\\\\]/', $folder_name)) return ['success' => false, 'message' => 'Il nome della cartella contiene caratteri non validi.'];
        if (mb_strlen($folder_name) > 100) return ['success' => false, 'message' => 'Il nome della cartella non può superare i 100 caratteri.'];

        if ($parent_folder_id !== null && $parent_folder_id !== 0 && $parent_folder_id !== '0') {
            $parent_folder_id = (int)$parent_folder_id;
            $stmt_check_parent = $conn->prepare("SELECT id FROM folders WHERE id = ? AND is_deleted = FALSE");
            if (!$stmt_check_parent) { log_error("DB Err (parent check prep): " . $conn->error); return ['success' => false, 'message' => 'Errore DB verifica cartella genitore.']; }
            $stmt_check_parent->bind_param("i", $parent_folder_id); 
            if (!$stmt_check_parent->execute()) { log_error("DB Err (parent check exec): " . $stmt_check_parent->error); $stmt_check_parent->close(); return ['success' => false, 'message' => 'Errore DB verifica cartella genitore.'];}
            if ($stmt_check_parent->get_result()->num_rows === 0) { $stmt_check_parent->close(); return ['success' => false, 'message' => 'Cartella genitore specificata non valida o eliminata.']; }
            $stmt_check_parent->close();
        } else { $parent_folder_id = null; }
        
        $sql_check_duplicate = "SELECT id FROM folders WHERE folder_name = ? AND owner_user_id = ? AND is_deleted = FALSE AND ";
        $types_check_duplicate = "si"; 
        $params_check_duplicate = [$folder_name, $owner_user_id];
        if ($parent_folder_id === null) {
            $sql_check_duplicate .= "parent_folder_id IS NULL";
        } else { 
            $sql_check_duplicate .= "parent_folder_id = ?"; 
            $types_check_duplicate .= "i"; 
            $params_check_duplicate[] = $parent_folder_id; 
        }
        
        $stmt_check_duplicate = $conn->prepare($sql_check_duplicate);
        if (!$stmt_check_duplicate) { log_error("DB Err (dupl check prep): " . $conn->error . " SQL: ". $sql_check_duplicate); return ['success' => false, 'message' => 'Errore DB verifica nome duplicato.'];}
        $stmt_check_duplicate->bind_param($types_check_duplicate, ...$params_check_duplicate); 
        if (!$stmt_check_duplicate->execute()) { log_error("DB Err (dupl check exec): " . $stmt_check_duplicate->error); $stmt_check_duplicate->close(); return ['success' => false, 'message' => 'Errore DB verifica nome duplicato.'];}
        if ($stmt_check_duplicate->get_result()->num_rows > 0) { $stmt_check_duplicate->close(); return ['success' => false, 'message' => 'Una cartella con questo nome esiste già in questa posizione.']; }
        $stmt_check_duplicate->close();

        // La tabella 'folders' NON ha 'updated_at'. Ha solo 'created_at'.
        $stmt_insert = $conn->prepare("INSERT INTO folders (parent_folder_id, owner_user_id, folder_name, created_at) VALUES (?, ?, ?, NOW())");
        if (!$stmt_insert) { log_error("DB Err (insert prep): " . $conn->error); return ['success' => false, 'message' => 'Errore DB preparazione creazione cartella.']; }
        $stmt_insert->bind_param("iis", $parent_folder_id, $owner_user_id, $folder_name);
        if ($stmt_insert->execute()) {
            $folder_id_new = $stmt_insert->insert_id; // Rinomino per evitare confusione di scope
            $stmt_insert->close();
            
            $user_nas_base_path = rtrim(NAS_ROOT_PATH, '/') . '/user_files/' . $owner_user_id . '/';
            ensure_folder_path_exists_and_get_relative($folder_id_new, $owner_user_id, $user_nas_base_path); // Usa $folder_id_new

            log_activity("Cartella creata: '{$folder_name}' (ID: {$folder_id_new}), Parent: " . ($parent_folder_id ?? 'Root') . ", Owner: {$owner_user_id}", $owner_user_id);
            return ['success' => true, 'message' => "Cartella '" . htmlspecialchars($folder_name) . "' creata con successo!", 'folder_id' => $folder_id_new];
        } else {
            log_error("DB Err (insert exec): " . $stmt_insert->error); 
            $stmt_insert->close();
            return ['success' => false, 'message' => 'Errore DB durante la creazione della cartella.'];
        }
    }
}

/**
 * Recupera i dettagli di una singola cartella.
 * Corretto per non selezionare 'updated_at' che non esiste in 'folders'.
 */
if (!function_exists('get_folder_details')) {
    function get_folder_details($folder_id) {
        if ($folder_id === null || $folder_id === 0 || $folder_id === '0' || $folder_id === '') {
            $current_user_for_root_owner = $_SESSION['user_id'] ?? null; // Il proprietario della "Radice File" logica
            return ['id' => '0', 'folder_name' => 'Radice File', 'parent_folder_id' => null, 'owner_user_id' => $current_user_for_root_owner, 'created_at' => date('Y-m-d H:i:s')];
        }
        $conn = get_db_connection();
        $stmt = $conn->prepare("SELECT id, folder_name, parent_folder_id, owner_user_id, created_at FROM folders WHERE id = ? AND is_deleted = FALSE");
        if (!$stmt) { 
            log_error("DB Err (get_folder_details prep): ".$conn->error, __FILE__, __LINE__); 
            return null; 
        }
        $stmt->bind_param("i", $folder_id);
        if (!$stmt->execute()) { 
            log_error("DB Err (get_folder_details exec): ".$stmt->error, __FILE__, __LINE__); 
            $stmt->close(); 
            return null; 
        }
        $folder = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        return $folder; // Può essere null se non trovata o errore
    }
}

/**
 * Controlla se un utente può visualizzare una specifica cartella.
 * L'utente può visualizzare se è il proprietario, o se è un admin, 
 * o se ha un permesso esplicito di visualizzazione sulla cartella o su una cartella antenata.
 * Corretta per debug e per usare $max_depth_check nel log.
 */
if (!function_exists('can_user_access_folder_view')) {
    function can_user_access_folder_view($user_id, $folder_id_to_check, $is_user_admin) {
        $original_folder_id_for_log = $folder_id_to_check; // Salva l'ID originale per i log
        log_activity("[can_user_access_folder_view] Inizio check per UserID: {$user_id}, FolderID Richiesto: {$original_folder_id_for_log}, IsAdmin: ".($is_user_admin?'Sì':'No'));

        if ($is_user_admin) {
            log_activity("[can_user_access_folder_view] Accesso consentito: Utente {$user_id} è Admin.");
            return true; 
        }
        // Utente normale può sempre vedere la propria "Radice File" logica
        if ($folder_id_to_check === null || $folder_id_to_check === 0 || $folder_id_to_check === '0' || $folder_id_to_check === '') {
            log_activity("[can_user_access_folder_view] Accesso consentito: Richiesta per Radice File (FolderID: '{$folder_id_to_check}').");
            return true; 
        }
        
        $conn = get_db_connection(); 
        $current_check_id = (int)$folder_id_to_check; 
        $max_depth_check = 10; // Limite per prevenire loop infiniti dovuti a dati corrotti
        $count_depth = 0;

        while ($current_check_id !== null && $current_check_id !== 0 && $count_depth < $max_depth_check) {
            log_activity("[can_user_access_folder_view] Iterazione {$count_depth} - Controllo FolderID: {$current_check_id} per UserID: {$user_id}");

            $stmt = $conn->prepare("SELECT f.owner_user_id, fp.can_view 
                                    FROM folders f 
                                    LEFT JOIN folder_permissions fp ON f.id = fp.folder_id AND fp.user_id = ?
                                    WHERE f.id = ? AND f.is_deleted = FALSE");
            if (!$stmt) { 
                log_error("DB Err (can_user_access_folder_view prep): " . $conn->error . " per current_id: {$current_check_id}"); 
                return false; 
            }
            $stmt->bind_param("ii", $user_id, $current_check_id);
            if (!$stmt->execute()) { 
                log_error("DB Err (can_user_access_folder_view exec): " . $stmt->error . " per current_id: {$current_check_id}"); 
                $stmt->close(); return false;
            }
            $data = $stmt->get_result()->fetch_assoc(); 
            // Non chiudere $stmt qui se vuoi controllare num_rows o altro, ma se usi fetch_assoc() e poi lo chiudi va bene.
            
            if (!$data) { // Cartella non trovata o eliminata
                log_warning("[can_user_access_folder_view] Dati cartella non trovati o eliminata per current_id: {$current_check_id}. Accesso negato a {$original_folder_id_for_log}.");
                $stmt->close(); 
                return false; 
            } 
            $stmt->close(); // Chiudi qui dopo aver usato $data

            log_activity("[can_user_access_folder_view] Dati recuperati per FolderID {$current_check_id}: OwnerID=" . ($data['owner_user_id'] ?? 'N/A') . ", Permesso CanView=" . (isset($data['can_view']) ? $data['can_view'] : 'NULL (nessun permesso esplicito)'));

            if (isset($data['owner_user_id']) && $data['owner_user_id'] == $user_id) { 
                log_activity("[can_user_access_folder_view] Accesso consentito a {$original_folder_id_for_log}: Utente {$user_id} è proprietario della cartella antenata/corrente {$current_check_id}.");
                return true; 
            }
            if (isset($data['can_view']) && $data['can_view'] == 1) { 
                log_activity("[can_user_access_folder_view] Accesso consentito a {$original_folder_id_for_log}: Utente {$user_id} ha permesso esplicito di visualizzazione sulla cartella antenata/corrente {$current_check_id}.");
                return true; 
            }
            
            // Se non ha permesso diretto o non è proprietario, risali al genitore
            $stmt_parent = $conn->prepare("SELECT parent_folder_id FROM folders WHERE id = ? AND is_deleted = FALSE");
            if (!$stmt_parent) { 
                log_error("DB Err (can_user_access_folder_view parent prep): " . $conn->error . " per current_id: {$current_check_id}"); 
                return false; 
            }
            $stmt_parent->bind_param("i", $current_check_id);
            if (!$stmt_parent->execute()) { 
                log_error("DB Err (can_user_access_folder_view parent exec): " . $stmt_parent->error . " per current_id: {$current_check_id}"); 
                $stmt_parent->close(); return false; 
            }
            $parent_data = $stmt_parent->get_result()->fetch_assoc(); 
            $stmt_parent->close();

            if (!$parent_data || $parent_data['parent_folder_id'] === null) { 
                // Se non c'è genitore O il genitore è NULL (radice del proprietario della cartella), 
                // e non abbiamo trovato permessi finora, allora l'accesso dipende solo dalla radice generale.
                // Se siamo arrivati qui, significa che né proprietà né permesso esplicito sono stati trovati sugli antenati.
                // La radice generale (parent_folder_id IS NULL) è sempre accessibile all'utente proprietario di quella radice (gestito da my_files.php)
                // o a chiunque se fosse una radice pubblica (non il nostro caso).
                // Se $current_check_id era già una cartella di primo livello (parent_id=null), il loop termina qui.
                log_activity("[can_user_access_folder_view] Raggiunta radice o genitore non valido per folder ID {$current_check_id}. Parent data: " . print_r($parent_data, true));
                $current_check_id = null; // Forza uscita dal loop se parent_folder_id è null o non trovato
            } else {
                $current_check_id = (int)$parent_data['parent_folder_id'];
            }
            $count_depth++;
        } 

        if ($count_depth >= $max_depth_check) {
            log_error("Profondità massima ({$max_depth_check}) raggiunta in can_user_access_folder_view per la richiesta originale su folder ID {$original_folder_id_for_log}. Ultimo current_check_id: " . ($current_check_id ?? 'NULL'));
        }
        
        // Se il loop è terminato perché $current_check_id è diventato null o 0, significa che abbiamo risalito fino alla radice
        // e non abbiamo trovato un permesso esplicito né una proprietà lungo il percorso per le cartelle intermedie.
        // Tuttavia, la radice (null o 0) è implicitamente accessibile (come "Radice File" dell'utente).
        // Quindi, se si arriva a questo punto, significa che il permesso per la $original_folder_id_for_log non è stato trovato.
        if ($current_check_id === null || $current_check_id === 0) {
             log_warning("[can_user_access_folder_view] Percorso risalito fino alla radice per folder ID {$original_folder_id_for_log}, ma nessun permesso specifico o proprietà trovati lungo il percorso. L'accesso alla radice è consentito, ma non necessariamente alla cartella richiesta se non ha permessi espliciti o proprietà.");
             // Restituire true qui era l'errore, perché significa che se risalgo fino alla radice senza trovare permessi, concedo accesso.
             // Questo non è corretto. Devo aver trovato un permesso sulla cartella stessa o su un suo antenato.
             // Se il loop termina perché $current_check_id è null/0, significa che per $original_folder_id_for_log e i suoi antenati
             // non c'era né proprietà né permesso. Quindi l'accesso è negato.
        }
        
        log_warning("[can_user_access_folder_view] Accesso NEGATO per utente ID {$user_id} a folder ID {$original_folder_id_for_log} dopo aver controllato la gerarchia (o profondità massima raggiunta senza trovare permessi).");
        return false; 
    }
}
/**
 * Funzione ricorsiva helper per costruire l'albero delle cartelle per la visualizzazione 
 * (usata da get_folders_flat_tree_with_permission_check)
 * Aggiunge solo cartelle per cui l'utente ha permesso di visualizzazione.
 */
if (!function_exists('build_folder_tree_recursive_with_perms')) {
    function build_folder_tree_recursive_with_perms($conn, $requesting_user_id, $is_requester_admin, $parent_id, $level, &$flat_tree) {
        $folders_in_level = get_child_folders_with_permission_check($requesting_user_id, $is_requester_admin, $parent_id);

        foreach ($folders_in_level as $folder) {
            $folder['level'] = $level;
            $prefix = '';
            if ($level > 0) {
                $prefix = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level - 1) . '↳ ';
            }
            $folder['display_name'] = $prefix . htmlspecialchars($folder['folder_name']);
            $flat_tree[] = $folder;
            // Chiamata ricorsiva per i figli di questa cartella
            build_folder_tree_recursive_with_perms($conn, $requesting_user_id, $is_requester_admin, $folder['id'], $level + 1, $flat_tree);
        }
    }
}

/**
 * Recupera un albero appiattito di tutte le cartelle che l'utente può vedere 
 * (proprie o condivise o tutte se admin), formattato per un dropdown.
 */
if (!function_exists('get_folders_flat_tree_with_permission_check')) {
    function get_folders_flat_tree_with_permission_check($requesting_user_id, $is_requester_admin, $parent_id = null, $level = 0) {
        $conn = get_db_connection(); 
        $flat_tree = [];
        build_folder_tree_recursive_with_perms($conn, $requesting_user_id, $is_requester_admin, $parent_id, $level, $flat_tree);
        return $flat_tree;
    }
}

/**
 * Funzione ricorsiva helper per ottenere gli antenati di una cartella, verificando i permessi.
 */
if (!function_exists('get_folder_ancestors_recursive_with_perms')) {
    function get_folder_ancestors_recursive_with_perms($conn, $folder_id, $requesting_user_id, $is_requester_admin, &$breadcrumbs_array) {
        if ($folder_id === null || $folder_id === 0 || $folder_id === '0') {
            return; 
        }
        // Il controllo can_user_access_folder_view qui era la causa probabile del loop.
        // Se la cartella stessa non è accessibile, il breadcrumb non dovrebbe mostrarla.
        // Ma se un antenato non è accessibile, il breadcrumb dovrebbe fermarsi lì.
        // La funzione can_user_access_folder_view già gestisce la risalita, quindi non dovremmo entrare in loop qui.
        // Il problema del "Profondità massima" era in can_user_access_folder_view.

        $stmt = $conn->prepare("SELECT id, folder_name, parent_folder_id FROM folders WHERE id = ? AND is_deleted = FALSE");
        if (!$stmt) { log_error("DB Err (breadcrumb ancestor prep): ".$conn->error, __FILE__, __LINE__); return; }
        $stmt->bind_param("i", $folder_id);
        if (!$stmt->execute()) { log_error("DB Err (breadcrumb ancestor exec): ".$stmt->error, __FILE__, __LINE__); $stmt->close(); return; }
        $folder = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($folder) {
            // Solo aggiungi al breadcrumb se l'utente può vedere questo specifico antenato
            if (can_user_access_folder_view($requesting_user_id, $folder['id'], $is_requester_admin)) {
                 array_unshift($breadcrumbs_array, ['id' => $folder['id'], 'name' => $folder['folder_name']]);
                 get_folder_ancestors_recursive_with_perms($conn, $folder['parent_folder_id'], $requesting_user_id, $is_requester_admin, $breadcrumbs_array);
            } else {
                log_warning("Costruzione Breadcrumb: User ID {$requesting_user_id} no accesso a folder ID {$folder['id']} (antenato). Percorso interrotto.", __FILE__, __LINE__);
                // Non aggiungere questo antenato e non continuare a risalire da qui.
                // $breadcrumbs_array = []; // Svuotare potrebbe essere troppo drastico, l'utente vede fino a dove può
            }
        }
    }
}

/**
 * Recupera il percorso breadcrumb per una data cartella.
 */
if (!function_exists('get_folder_path_breadcrumbs_with_permission_check')) {
    function get_folder_path_breadcrumbs_with_permission_check($folder_id, $requesting_user_id, $is_requester_admin) {
        $conn = get_db_connection(); 
        $breadcrumbs = [];
        
        // Controlla l'accesso alla cartella target prima di tutto
        if ($folder_id !== null && $folder_id !== 0 && $folder_id !== '0') {
            if (!can_user_access_folder_view($requesting_user_id, $folder_id, $is_requester_admin)) {
                log_warning("Breadcrumb per folder ID {$folder_id} utente ID {$requesting_user_id}: Accesso negato alla cartella target.", __FILE__, __LINE__);
                // Restituisce solo la radice se non c'è accesso alla cartella richiesta
                $breadcrumbs[] = ['id' => '0', 'name' => 'Radice File'];
                return $breadcrumbs;
            }
            // Se ha accesso alla cartella target, costruisci il breadcrumb
            get_folder_ancestors_recursive_with_perms($conn, $folder_id, $requesting_user_id, $is_requester_admin, $breadcrumbs);
        }
        
        // Assicura che "Radice File" sia sempre il primo elemento se il breadcrumb non è vuoto
        // (potrebbe essere vuoto se get_folder_ancestors_recursive_with_perms interrompe a causa di permessi)
        if (empty($breadcrumbs) && ($folder_id !== null && $folder_id !== 0 && $folder_id !== '0')) {
            // Questo caso si verifica se l'accesso è stato negato a un antenato
            $breadcrumbs[] = ['id' => '0', 'name' => 'Radice File']; 
        } elseif (empty($breadcrumbs) || $breadcrumbs[0]['id'] !== '0') {
             array_unshift($breadcrumbs, ['id' => '0', 'name' => 'Radice File']);
        }
        return $breadcrumbs;
    }
}

/**
 * Recupera le sottocartelle dirette di una data cartella che l'utente può vedere.
 */
if (!function_exists('get_child_folders_with_permission_check')) {
    function get_child_folders_with_permission_check($requesting_user_id, $is_requester_admin, $parent_id = null) {
        $conn = get_db_connection(); $folders = [];
        $sql = "SELECT DISTINCT f.id, f.folder_name, f.parent_folder_id, f.owner_user_id, f.created_at 
                FROM folders f ";
        $where_clauses = ["f.is_deleted = FALSE"]; $types = ""; $params = [];
        if (!$is_requester_admin) { 
            $sql .= "LEFT JOIN folder_permissions fp ON f.id = fp.folder_id AND fp.user_id = ? "; 
            $types .= "i"; $params[] = $requesting_user_id; 
        }
        if ($parent_id === null) { $where_clauses[] = "f.parent_folder_id IS NULL"; }
        else { $where_clauses[] = "f.parent_folder_id = ?"; $types .= "i"; $params[] = (int)$parent_id; }
        
        if (!$is_requester_admin) { 
            $where_clauses[] = "(f.owner_user_id = ? OR fp.can_view = TRUE)"; 
            $types .= "i"; $params[] = $requesting_user_id; 
        }
        
        if (!empty($where_clauses)) $sql .= "WHERE " . implode(" AND ", $where_clauses);
        $sql .= " ORDER BY f.folder_name ASC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) { log_error("DB Err (prep get_child_folders): " . $conn->error . " SQL: " . $sql); return $folders; }
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) { log_error("DB Err (exec get_child_folders): " . $stmt->error); $stmt->close(); return $folders; }
        $result = $stmt->get_result(); 
        if ($result) { while ($row = $result->fetch_assoc()) $folders[] = $row; $result->free(); }
        $stmt->close(); 
        return $folders;
    }
}

/**
 * Aggiorna il nome di una cartella. Solo admin.
 */
if (!function_exists('update_folder_name')) {
    function update_folder_name($folder_id, $new_name, $acting_user_id) {
        if (!is_admin_by_id($acting_user_id)) return ['success' => false, 'message' => 'Solo gli amministratori possono rinominare le cartelle.'];
        $conn = get_db_connection(); $new_name = trim(strip_tags($new_name));
        if (empty($new_name)) return ['success' => false, 'message' => 'Il nome della cartella non può essere vuoto.'];
        if (mb_strlen($new_name) > 100) return ['success' => false, 'message' => 'Nome cartella troppo lungo (max 100 caratteri).'];
        if (preg_match('/[\'^£$%&*()}{@#~?><>,|=+¬\/\\\\]/', $new_name)) return ['success' => false, 'message' => 'Il nome della cartella contiene caratteri non validi.'];
        
        $folder_details = get_folder_details($folder_id);
        if (!$folder_details || !isset($folder_details['id']) || $folder_details['id'] == '0') return ['success' => false, 'message' => 'Cartella non trovata o non rinominabile (es. Radice).'];
        
        $parent_folder_id = $folder_details['parent_folder_id']; 
        $owner_user_id = $folder_details['owner_user_id']; 
        
        $sql_check_duplicate = "SELECT id FROM folders WHERE folder_name = ? AND owner_user_id = ? AND is_deleted = FALSE AND id != ? AND ";
        $types_check_duplicate = "sii"; $params_check_duplicate = [$new_name, $owner_user_id, $folder_id];
        if ($parent_folder_id === null) { $sql_check_duplicate .= "parent_folder_id IS NULL"; }
        else { $sql_check_duplicate .= "parent_folder_id = ?"; $types_check_duplicate .= "i"; $params_check_duplicate[] = $parent_folder_id; }
        
        $stmt_check_duplicate = $conn->prepare($sql_check_duplicate);
        if (!$stmt_check_duplicate) { log_error("DB Err (rename_folder dupl check): " . $conn->error); return ['success' => false, 'message' => 'Errore DB (controllo duplicato).'];}
        $stmt_check_duplicate->bind_param($types_check_duplicate, ...$params_check_duplicate); $stmt_check_duplicate->execute();
        if ($stmt_check_duplicate->get_result()->num_rows > 0) { $stmt_check_duplicate->close(); return ['success' => false, 'message' => 'Una cartella con questo nome esiste già in questa posizione.']; }
        $stmt_check_duplicate->close();
        
        $stmt_update = $conn->prepare("UPDATE folders SET folder_name = ? WHERE id = ?"); // Rimosso updated_at
        if (!$stmt_update) { log_error("DB Err (update_folder_name prep): " . $conn->error); return ['success' => false, 'message' => 'Errore DB (preparazione rinomina).']; }
        $stmt_update->bind_param("si", $new_name, $folder_id);
        if ($stmt_update->execute()) { 
            $stmt_update->close(); 
            log_activity("Cartella ID {$folder_id} rinominata in '{$new_name}' da utente ID {$acting_user_id}", $acting_user_id); 
            return ['success' => true, 'message' => 'Nome cartella aggiornato con successo.']; 
        } else { 
            log_error("DB Err (update_folder_name exec): " . $stmt_update->error); 
            $stmt_update->close(); 
            return ['success' => false, 'message' => 'Errore DB durante la rinomina della cartella.']; 
        }
    }
}

/**
 * Concede permessi su una cartella a un utente. Solo admin.
 */
if (!function_exists('grant_folder_permission')) {
    function grant_folder_permission($folder_id, $target_user_id, $can_view, $can_upload_files_in, $can_delete_files_in, $can_share_files_in, $acting_user_id) {
        if (!is_admin_by_id($acting_user_id)) return ['success' => false, 'message' => 'Solo gli amministratori possono gestire i permessi.'];
        $conn = get_db_connection(); 
        $view_int = $can_view ? 1 : 0;
        $upload_int = $view_int; $delete_int = $view_int; $share_int = $view_int; // Permessi granulari seguono can_view

        $stmt_check = $conn->prepare("SELECT id FROM folder_permissions WHERE folder_id = ? AND user_id = ?");
        if(!$stmt_check){log_error("DB Err (grant_perm check prep): ".$conn->error); return ['success'=>false, 'message'=>'Errore DB (check perm).'];}
        $stmt_check->bind_param("ii", $folder_id, $target_user_id); $stmt_check->execute(); $existing = $stmt_check->get_result()->fetch_assoc(); $stmt_check->close();
        
        if ($existing) {
            if ($view_int == 0) { // Se can_view è 0, rimuoviamo la riga di permesso
                return revoke_folder_permission($folder_id, $target_user_id, $acting_user_id);
            }
            $stmt = $conn->prepare("UPDATE folder_permissions SET can_view = ?, can_upload_files = ?, can_delete_files = ?, can_share_files = ? WHERE id = ?");
            if(!$stmt){log_error("DB Err (grant_perm update prep): ".$conn->error); return ['success'=>false, 'message'=>'Errore DB (update perm).'];}
            $stmt->bind_param("iiiii", $view_int, $upload_int, $delete_int, $share_int, $existing['id']);
        } else { 
            if ($view_int == 0) return ['success' => true, 'message' => 'Nessun permesso concesso (visualizzazione non abilitata).'];
            $stmt = $conn->prepare("INSERT INTO folder_permissions (folder_id, user_id, can_view, can_upload_files, can_delete_files, can_share_files) VALUES (?, ?, ?, ?, ?, ?)");
            if(!$stmt){log_error("DB Err (grant_perm insert prep): ".$conn->error); return ['success'=>false, 'message'=>'Errore DB (insert perm).'];}
            $stmt->bind_param("iiiiii", $folder_id, $target_user_id, $view_int, $upload_int, $delete_int, $share_int);
        }
        if ($stmt->execute()) { $stmt->close(); log_activity("Permessi cartella ID {$folder_id} per utente ID {$target_user_id} impostati (view={$view_int}) da admin ID {$acting_user_id}", $acting_user_id); return ['success' => true, 'message' => 'Permessi aggiornati con successo.']; }
        else { log_error("DB Err (grant_perm exec): ".$stmt->error); $stmt->close(); return ['success' => false, 'message' => 'Errore DB durante l\'aggiornamento dei permessi.']; }
    }
}

/**
 * Revoca tutti i permessi di un utente su una cartella. Solo admin.
 */
if (!function_exists('revoke_folder_permission')) {
    function revoke_folder_permission($folder_id, $target_user_id, $acting_user_id) {
        if (!is_admin_by_id($acting_user_id)) return ['success' => false, 'message' => 'Solo gli amministratori possono revocare i permessi.'];
        $conn = get_db_connection(); $stmt = $conn->prepare("DELETE FROM folder_permissions WHERE folder_id = ? AND user_id = ?");
        if(!$stmt){log_error("DB Err (revoke_perm prep): ".$conn->error); return ['success'=>false, 'message'=>'Errore DB (prep revoca).'];}
        $stmt->bind_param("ii", $folder_id, $target_user_id);
        if ($stmt->execute()) { $affected_rows = $stmt->affected_rows; $stmt->close(); 
            if($affected_rows > 0) { log_activity("Permessi cartella ID {$folder_id} revocati per utente ID {$target_user_id} da admin ID {$acting_user_id}", $acting_user_id); return ['success' => true, 'message' => 'Permessi revocati con successo.']; }
            else { return ['success' => true, 'message' => 'Nessun permesso specifico trovato da revocare per questo utente sulla cartella.']; } }
        else { log_error("DB Err (revoke_perm exec): ".$stmt->error); $stmt->close(); return ['success' => false, 'message' => 'Errore DB durante la revoca dei permessi.']; }
    }
}

/**
 * Recupera i permessi specifici concessi a utenti per una data cartella.
 */
if (!function_exists('get_folder_permissions')) {
    function get_folder_permissions($folder_id) {
        $conn = get_db_connection(); $permissions = [];
        // Se la tua tabella folder_permissions ha solo can_view, modifica la query.
        // Assumendo che ora abbia can_upload_files, can_delete_files, can_share_files come da ultimo ALTER TABLE.
        $stmt = $conn->prepare("SELECT fp.user_id, u.username, fp.can_view, 
                                       fp.can_upload_files, fp.can_delete_files, fp.can_share_files 
                                FROM folder_permissions fp 
                                JOIN users u ON fp.user_id = u.id 
                                WHERE fp.folder_id = ?");
        if (!$stmt) { log_error("DB Error (prepare get_folder_permissions): " . $conn->error); return $permissions; }
        $stmt->bind_param("i", $folder_id);
        if (!$stmt->execute()) { log_error("DB Error (execute get_folder_permissions): " . $stmt->error); $stmt->close(); return $permissions; }
        $result = $stmt->get_result();
        if ($result) { while ($row = $result->fetch_assoc()) $permissions[] = $row; $result->free(); }
        else { log_error("DB Error (get_result get_folder_permissions): " . ($stmt->error ?: 'Errore sconosciuto')); }
        $stmt->close(); return $permissions;
    }
}

/**
 * Helper ricorsivo per eliminare record DB di cartelle e sottocartelle, e soft-delete file.
 */
if (!function_exists('delete_folder_contents_recursive_db')) {
    function delete_folder_contents_recursive_db($conn, $folder_id, $acting_user_id) {
        if(!function_exists('soft_delete_file')) { 
            log_error("Funzione dipendente soft_delete_file() non trovata in delete_folder_contents_recursive_db", __FILE__, __LINE__);
            throw new Exception("Funzione dipendente soft_delete_file non trovata."); 
        }
        $stmt_files = $conn->prepare("SELECT id FROM files WHERE folder_id = ? AND is_deleted = FALSE");
        if (!$stmt_files) throw new Exception("Errore DB (prep files in folder): " . $conn->error);
        $stmt_files->bind_param("i", $folder_id); $stmt_files->execute(); $result_files = $stmt_files->get_result();
        $file_ids_to_delete = []; while ($row = $result_files->fetch_assoc()) $file_ids_to_delete[] = $row['id'];
        $stmt_files->close();
        foreach ($file_ids_to_delete as $file_id_del) {
            $del_res = soft_delete_file($file_id_del, $acting_user_id, true); 
            if (!$del_res['success']) log_warning("Fallito soft-delete file ID {$file_id_del} durante eliminazione cartella ID {$folder_id}. Msg: " . ($del_res['message'] ?? 'N/D'));
        }
        $stmt_subfolders = $conn->prepare("SELECT id FROM folders WHERE parent_folder_id = ? AND is_deleted = FALSE");
        if (!$stmt_subfolders) throw new Exception("Errore DB (prep subfolders): " . $conn->error);
        $stmt_subfolders->bind_param("i", $folder_id); $stmt_subfolders->execute(); $result_subfolders = $stmt_subfolders->get_result();
        $subfolder_ids = []; while ($row = $result_subfolders->fetch_assoc()) $subfolder_ids[] = $row['id'];
        $stmt_subfolders->close();
        foreach ($subfolder_ids as $subfolder_id) {
            delete_folder_contents_recursive_db($conn, $subfolder_id, $acting_user_id); 
            $stmt_del_sub = $conn->prepare("DELETE FROM folders WHERE id = ?"); 
            if (!$stmt_del_sub) throw new Exception("Errore DB (prep delete subfolder record): " . $conn->error);
            $stmt_del_sub->bind_param("i", $subfolder_id);
            if (!$stmt_del_sub->execute()) throw new Exception("Errore DB (exec delete subfolder record ID {$subfolder_id}): " . $stmt_del_sub->error);
            $stmt_del_sub->close();
            log_activity("Record DB sottocartella ID {$subfolder_id} eliminato (da admin ID {$acting_user_id}) durante eliminazione cartella ID {$folder_id}", $acting_user_id);
        }
    }
}

/**
 * Elimina una cartella (e ricorsivamente le sue sottocartelle dal DB).
 * Esegue il soft delete dei file contenuti. NON elimina i file fisici. Solo admin.
 */
if (!function_exists('delete_folder_recursive')) {
    function delete_folder_recursive($folder_id_to_delete, $acting_user_id) {
        if (!is_admin_by_id($acting_user_id)) return ['success' => false, 'message' => 'Azione non permessa. Solo gli amministratori possono eliminare cartelle.'];
        if ($folder_id_to_delete === null || $folder_id_to_delete === 0 || $folder_id_to_delete === '0') return ['success' => false, 'message' => 'Impossibile eliminare la cartella radice.'];
        $conn = get_db_connection();
        $folder_details = get_folder_details($folder_id_to_delete);
        if (!$folder_details || !isset($folder_details['folder_name'])) { return ['success' => false, 'message' => 'Cartella non trovata o dettagli non validi.'];}
        
        $conn->begin_transaction();
        try {
            delete_folder_contents_recursive_db($conn, $folder_id_to_delete, $acting_user_id);
            
            // Infine, elimina la cartella principale specificata dal DB
            $stmt_del_main = $conn->prepare("DELETE FROM folders WHERE id = ?");
            if (!$stmt_del_main) throw new Exception("Errore DB preparazione eliminazione cartella principale: " . $conn->error);
            $stmt_del_main->bind_param("i", $folder_id_to_delete);
            if (!$stmt_del_main->execute()) throw new Exception("Errore DB esecuzione eliminazione cartella principale ID {$folder_id_to_delete}: " . $stmt_del_main->error);
            $stmt_del_main->close(); 
            
            $conn->commit();
            log_activity("Cartella ID {$folder_id_to_delete} ('".htmlspecialchars($folder_details['folder_name'])."') e il suo contenuto DB (sottocartelle e permessi) eliminati. File contenuti soft-deleted. Azione da admin ID {$acting_user_id}", $acting_user_id);
            return ['success' => true, 'message' => "Cartella '".htmlspecialchars($folder_details['folder_name'])."' e tutto il suo contenuto (sottocartelle e file associati nel DB) sono stati eliminati con successo."];
        } catch (Exception $e) { 
            $conn->rollback(); 
            log_error("Errore durante delete_folder_recursive per ID {$folder_id_to_delete}: " . $e->getMessage(), __FILE__, __LINE__); 
            return ['success' => false, 'message' => "Errore durante l'eliminazione della cartella: " . $e->getMessage()];
        }
    }
}
?>