<?php
// includes/db_connection.php
require_once __DIR__ . '/../config.php';

function get_db_connection() {
    static $conn = null; // Connessione statica per riutilizzarla

    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Abilita eccezioni per errori MySQLi
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $e) {
            log_error("Errore connessione DB: " . $e->getMessage(), __FILE__, __LINE__);
            // In un'applicazione reale, potresti mostrare una pagina di errore generica
            die("Errore di connessione al database. Riprova più tardi.");
        }
    }
    return $conn;
}
?>