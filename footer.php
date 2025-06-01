<?php
// includes/footer.php
if (!function_exists('get_site_setting')) { 
    // Questo include è già in header.php, ma per sicurezza se footer.php fosse chiamato isolatamente
    // require_once __DIR__ . '/functions_settings.php'; 
}
$current_year = date('Y');
// Assicurati che SITE_NAME sia definito in config.php come fallback
$site_name_for_footer = get_site_setting('site_name', (defined('SITE_NAME') ? SITE_NAME : 'Il Mio Sito'));
$footer_text_from_db = get_site_setting('footer_text', "© {$current_year} " . htmlspecialchars($site_name_for_footer) . ". Tutti i diritti riservati.");
?>
        </main> <?php // Chiusura del tag <main> aperto in header.php ?>

        <footer class="footer mt-auto py-3 custom-theme-footer"> <?php // CLASSE PER STILE DINAMICO ?>
            <div class="container text-center">
                <span class="text-muted"><?php echo $footer_text_from_db; // Il testo dal DB o è già sanitizzato o è testo semplice ?></span>
            </div>
        </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="assets/js/main.js?v=<?php echo time(); // Cache buster per sviluppo ?>"></script>
</body>
</html>