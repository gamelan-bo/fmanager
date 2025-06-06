<?php
// File di configurazione generato dall'installer il 2025-06-05 16:06:02

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if(!@date_default_timezone_set('Europe/Rome')) { date_default_timezone_set('UTC'); }

// --- Impostazioni Database ---
if (!defined('DB_HOST')) define('DB_HOST', '');
if (!defined('DB_USER')) define('DB_USER', '');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', '');
if (!defined('DB_TABLE_PREFIX')) define('DB_TABLE_PREFIX', ''); // Sarà vuoto

// --- Impostazioni Sito ---
if (!defined('SITE_URL')) define('SITE_URL', '');
if (!defined('REWRITE_BASE_FOR_PHP')) define('REWRITE_BASE_FOR_PHP', '/');
if (!defined('SITE_NAME')) define('SITE_NAME', '');
if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', dirname(__FILE__)); // Definisce PROJECT_ROOT relativo a config.php
if (defined('PROJECT_ROOT') && !defined('PHPMAILER_PATH')) define('PHPMAILER_PATH', PROJECT_ROOT . '/includes/PHPMailer/src/');
if (!defined('RECAPTCHA_SITE_KEY')) define('RECAPTCHA_SITE_KEY', '');
if (!defined('RECAPTCHA_SECRET_KEY')) define('RECAPTCHA_SECRET_KEY', '');
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'MyFileShareFM_SID');
if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', 1800);
if (!defined('LOG_PATH')) define('LOG_PATH', PROJECT_ROOT . '/LOG/');
if (!defined('ACTIVITY_LOG')) define('ACTIVITY_LOG', LOG_PATH . 'activity.log');
if (!defined('ERROR_LOG')) define('ERROR_LOG', LOG_PATH . 'error.log');
if (!defined('NAS_ROOT_PATH')) define('NAS_ROOT_PATH', '/mnt/nas/');
if (!defined('ADMIN_NOTIFICATION_EMAIL')) define('ADMIN_NOTIFICATION_EMAIL', '');
if (!defined('SITE_EMAIL_FROM')) define('SITE_EMAIL_FROM', '');
if (!defined('DEFAULT_USER_QUOTA_BYTES')) define('DEFAULT_USER_QUOTA_BYTES', 1073741824); // 1GB

// --- Funzioni di Logging Base ---
if (!function_exists('log_error')) { function log_error($m,$s='',$l='') { $ts=date('Y-m-d H:i:s');$le="[{$ts}]";if(!empty($s))$le.=" [S: ".basename($s)."]";if(!empty($l))$le.=" [L:{$l}]";$le.=" - Error: {$m}".PHP_EOL;$elf=defined('ERROR_LOG')?ERROR_LOG:(dirname(__FILE__).'/LOG/error.log');@error_log($le,3,$elf);}}
if (!function_exists('log_activity')) { function log_activity($m,$uid=null) { $ts=date('Y-m-d H:i:s');$le="[{$ts}]";if($uid!==null)$le.=" [UID:{$uid}]";$le.=" - Activity: {$m}".PHP_EOL;$alf=defined('ACTIVITY_LOG')?ACTIVITY_LOG:(dirname(__FILE__).'/LOG/activity.log');@error_log($le,3,$alf);}}
if (!function_exists('log_warning')) { function log_warning($m,$s='',$l='') { $ts=date('Y-m-d H:i:s');$le="[{$ts}]";if(!empty($s))$le.=" [S: ".basename($s)."]";if(!empty($l))$le.=" [L:{$l}]";$le.=" - Warning: {$m}".PHP_EOL;$elf=defined('ERROR_LOG')?ERROR_LOG:(dirname(__FILE__).'/LOG/error.log');@error_log($le,3,$elf);}}

if (defined('LOG_PATH') && !is_dir(LOG_PATH)) { if (!@mkdir(LOG_PATH,0755,true)&&!is_dir(LOG_PATH)) { trigger_error("CRITICO: Impossibile creare LOG_PATH: ".LOG_PATH,E_USER_WARNING);}}

if (defined('PROJECT_ROOT')&&file_exists(PROJECT_ROOT.'/includes/functions_url.php')){require_once PROJECT_ROOT.'/includes/functions_url.php';} elseif(file_exists(dirname(__FILE__).'/includes/functions_url.php')){require_once dirname(__FILE__).'/includes/functions_url.php';} else { if(function_exists("log_error")) log_error("CRITICO: functions_url.php non trovato da config.php"); else error_log("CRITICO: functions_url.php non trovato da config.php");}

if (session_status()==PHP_SESSION_NONE){if(defined('SESSION_NAME'))session_name(SESSION_NAME);session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>($_SERVER['HTTP_HOST'] ?? ''),'secure'=>isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on','httponly'=>true,'samesite'=>'Lax']);if(!session_start()){if(function_exists('log_error'))log_error('Impossibile avviare sessione da config.php.',__FILE__,__LINE__);}}
?>
