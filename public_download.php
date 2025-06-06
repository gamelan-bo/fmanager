<?php
// /var/www/html/fm/public_download.php

// Includi solo i file strettamente necessari per non avviare sessioni o logica utente non richiesta
require_once __DIR__ . '/config.php'; // Per NAS_ROOT_PATH, SITE_URL, log_error
require_once __DIR__ . '/includes/db_connection.php'; // Per la connessione DB
require_once __DIR__ . '/includes/functions_file.php'; // Per get_file_by_public_token

$token = $_GET['token'] ?? '';

if (empty($token)) {
    // Potresti mostrare una pagina di errore più carina
    header("HTTP/1.0 400 Bad Request");
    die("Errore: Token di download mancante o non valido.");
}

$file = get_file_by_public_token($token);

if (!$file) {
    header("HTTP/1.0 404 Not Found");
    die("Errore: Link di download non valido, file non trovato o accesso negato.");
}

if (isset($file['expired']) && $file['expired'] === true) {
    header("HTTP/1.0 410 Gone"); // O 403 Forbidden
    die("Errore: Questo link di download è scaduto.");
}

// Costruisci il percorso completo del file sul server
// $file['file_path'] nel DB è USER_ID/eventuale_sottocartella/stored_filename.ext
$full_server_path = rtrim(NAS_ROOT_PATH, '/') . '/user_files/' . $file['file_path'];


if (!file_exists($full_server_path) || !is_readable($full_server_path)) {
    log_error("File pubblico non trovato sul server o non leggibile: {$full_server_path} (Token: {$token})", __FILE__, __LINE__);
    header("HTTP/1.0 500 Internal Server Error");
    die("Errore: Il file fisico non è stato trovato sul server. Contatta l'amministratore.");
}

// Non registriamo il download count per i link pubblici nello stesso modo dei download privati,
// a meno che non si decida diversamente.
// log_activity("Download pubblico via token: {$file['original_filename']} (Token: {$token})", null);


// Prepara gli header per il download
header('Content-Description: File Transfer');
header('Content-Type: ' . ($file['file_type'] ?: 'application/octet-stream')); // Fallback a octet-stream
header('Content-Disposition: attachment; filename="' . basename($file['original_filename']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $file['file_size_bytes']);

// Pulisci il buffer di output per evitare corruzione del file
if (ob_get_level()) {
    ob_end_clean();
}

// Leggi e invia il file
$chunk_size = 1024 * 1024; // Leggi file in blocchi da 1MB
$handle = fopen($full_server_path, 'rb');
if ($handle === false) {
    log_error("Impossibile aprire il file per la lettura (public_download): {$full_server_path}", __FILE__, __LINE__);
    header("HTTP/1.0 500 Internal Server Error");
    die("Errore durante la lettura del file sul server.");
}
while (!feof($handle)) {
    echo fread($handle, $chunk_size);
    @ob_flush(); // Invia l'output al browser
    @flush();    // Assicura che l'output sia inviato
}
fclose($handle);
exit;
?>