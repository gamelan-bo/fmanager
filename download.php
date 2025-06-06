<?php
// /var/www/html/fm/download.php
require_once __DIR__ . '/config.php'; // Per NAS_ROOT_PATH
require_once __DIR__ . '/includes/functions_auth.php'; // Per session_start, is_logged_in, is_admin
require_once __DIR__ . '/includes/functions_file.php'; // Per get_file_for_download, record_file_download
require_once __DIR__ . '/includes/db_connection.php'; // A volte necessario se functions_file non lo include direttamente

// Avvia la sessione se non già fatto (functions_auth.php potrebbe farlo, ma meglio essere sicuri)
if (session_status() == PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

require_login(); // L'utente deve essere loggato per scaricare

if (!isset($_GET['file_id']) || !is_numeric($_GET['file_id'])) {
    $_SESSION['flash_message'] = "ID file non valido o mancante.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: my_files.php');
    exit;
}

$file_id = (int)$_GET['file_id'];
$user_id = $_SESSION['user_id'];

$file = get_file_for_download($file_id, $user_id);

if (!$file) {
    $_SESSION['flash_message'] = "File non trovato o accesso negato.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: my_files.php');
    exit;
}

// Costruisci il percorso completo del file sul server
// $file['file_path'] nel DB è USER_ID/eventuale_sottocartella/stored_filename.ext
$full_server_path = rtrim(NAS_ROOT_PATH, '/') . '/user_files/' . $file['file_path'];


if (!file_exists($full_server_path) || !is_readable($full_server_path)) {
    log_error("File non trovato sul server o non leggibile: {$full_server_path} (File ID: {$file_id})", __FILE__, __LINE__);
    $_SESSION['flash_message'] = "Errore: il file fisico non è stato trovato sul server.";
    $_SESSION['flash_type'] = 'danger';
    header('Location: my_files.php');
    exit;
}

// Registra il download prima di inviare il file
record_file_download($file_id);
log_activity("Download del file: {$file['original_filename']} (ID: {$file_id}) da utente ID: {$user_id}", $user_id);


// Prepara gli header per il download
header('Content-Description: File Transfer');
header('Content-Type: ' . $file['file_type']); // Usa il MIME type salvato
header('Content-Disposition: attachment; filename="' . basename($file['original_filename']) . '"'); // Usa il nome originale
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $file['file_size_bytes']); // Usa la dimensione salvata

// Pulisci il buffer di output per evitare corruzione del file
if (ob_get_level()) {
    ob_end_clean();
}

// Leggi e invia il file
readfile($full_server_path);
exit;
?>