<?php
// includes/functions_utils.php

if (!defined('PROJECT_ROOT')) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    } else {
        // Fallback se config.php non è immediatamente sopra
        // Questo è un setup di base, potrebbe essere necessario un path più robusto
        define('PROJECT_ROOT', dirname(__DIR__));
    }
}

/**
 * Schiarisce o scurisce un colore esadecimale.
 * @param string $hex Colore esadecimale (es. #RRGGBB).
 * @param int $steps Numero di passi per schiarire (positivo) o scurire (negativo). Max 255.
 * @return string Nuovo colore esadecimale.
 */
if (!function_exists('adjust_brightness')) {
    function adjust_brightness($hex, $steps) {
        $steps = max(-255, min(255, $steps)); 
        $hex = str_replace('#', '', $hex);
        if (strlen($hex) == 3) {
            $hex = str_repeat(substr($hex,0,1), 2) . str_repeat(substr($hex,1,1), 2) . str_repeat(substr($hex,2,1), 2);
        }
        $r = max(0, min(255, hexdec(substr($hex,0,2)) + $steps));
        $g = max(0, min(255, hexdec(substr($hex,2,2)) + $steps));
        $b = max(0, min(255, hexdec(substr($hex,4,2)) + $steps));
        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT)
                 . str_pad(dechex($g), 2, '0', STR_PAD_LEFT)
                 . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }
}

/**
 * Formatta la dimensione di un file da byte a un formato leggibile (KB, MB, GB, ecc.).
 * @param int $bytes Numero di byte.
 * @param int $precision Numero di cifre decimali.
 * @return string Dimensione formattata del file.
 */
if (!function_exists('format_file_size')) { 
    function format_file_size($bytes, $precision = 2){ 
        if(!is_numeric($bytes) || $bytes < 0) return 'N/D';
        if($bytes == 0) return '0 B'; 
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
?>