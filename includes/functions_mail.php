<?php
// Inclusione manuale dei file di PHPMailer
// Assicurati che i percorsi siano corretti rispetto alla posizione di questo file.
// Se functions_mail.php è in includes/, e PHPMailer è in includes/lib/PHPMailer/src/
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';

// Importa le classi nello namespace globale
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Il resto del file functions_mail.php rimane come precedentemente descritto:
// function send_email($to_email, $to_name, $subject, $html_body, $alt_body = '') { ... }
// function send_admin_new_user_notification(...) { ... }
// ecc.
?>