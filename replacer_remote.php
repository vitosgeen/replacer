<?php
/**
 * PHP Batch File Replacer — Remote (FTP/SFTP) Edition
 *
 * Same search/replace engine as replacer.php (string/regex/prepend/append/htaccess-bot-blocker,
 * glob & regex directory targeting, depth limits, exclude-dirs, dry-run vs --run, line-level diff)
 * but operating over a remote FTP or SFTP connection instead of the local filesystem.
 * Standalone CLI + Web UI, single file. SFTP requires `composer install` (phpseclib) — FTP needs
 * only the core `ftp` extension.
 */

// SECURITY CONFIGURATION
// Web UI access is restricted to localhost (127.0.0.1 / ::1) by default. This tool submits FTP/SFTP
// credentials through the browser to this script on every request, so only flip this to true if the
// host running the script is itself trusted/firewalled (matches the ALLOW_REMOTE_ACCESS convention in replacer.php).
define('ALLOW_REMOTE_ACCESS', true);

// Before any live write, the pre-change contents of a remote file are saved here (on the machine
// running this script), under <host>_<port>/<run-id>/<original-remote-path>. Nothing is ever
// written back to the remote server itself.
define('BACKUP_DIR', __DIR__ . '/backups');

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

// Helper function to colorize CLI output
function color($text, $color_code) {
    if (function_exists('posix_isatty') && !posix_isatty(STDOUT)) {
        return $text;
    }
    return "\033[{$color_code}m{$text}\033[0m";
}

$is_cli = (php_sapi_name() === 'cli' || defined('STDIN'));

function is_local_request() {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remote_ip, ['127.0.0.1', '::1', 'localhost']);
}

// ---------------------------------------------------------
// CLI - Print Help Instructions
// ---------------------------------------------------------
function print_help() {
    echo color("=== PHP Batch File Replacer (Remote FTP/SFTP Edition) ===", "1;36") . "\n";
    echo "Search and replace across files on a remote FTP or SFTP server, with dry-run previews.\n\n";
    echo color("Usage:", "1;33") . "\n";
    echo "  php replacer_remote.php --protocol=ftp|sftp --host=<host> --user=<user> [--password=<pass>] --dir=<path> --template=<glob> --mode=<mode> [options]\n\n";
    echo color("Connection Arguments:", "1;33") . "\n";
    echo "  --protocol=<ftp|sftp>   Remote protocol (default: ftp)\n";
    echo "  --host=<host>           Remote hostname or IP (required)\n";
    echo "  --port=<port>           Remote port (default: 21 for ftp, 22 for sftp)\n";
    echo "  --user=<user>           Username (required)\n";
    echo "  --password=<pass>       Password (omit to be prompted interactively; avoid on shared shells)\n";
    echo "  --password-env=<name>   Read the password from environment variable <name> instead\n";
    echo "  --key=<path>            Private key file for SFTP public-key auth\n";
    echo "  --passphrase=<pass>     Passphrase for --key, if any\n";
    echo "  --ssl                   Use explicit FTPS (FTP over TLS) instead of plain FTP\n";
    echo "  --active                Use active mode instead of passive mode for FTP\n";
    echo "  --timeout=<seconds>     Connection timeout (default: 30)\n\n";
    echo color("Replacement Arguments:", "1;33") . "\n";
    echo "  --dir=<paths>           Target remote directories, comma-separated (default: /)\n";
    echo "  --template=<glob>       File pattern templates, comma-separated (e.g. *.html,*.php)\n";
    echo "  --mode=<mode>           Replacement mode: string | regex | prepend | append | htaccess-bot-blocker\n";
    echo "  --find=<pattern>        Search string or regex pattern (required for string/regex modes)\n";
    echo "  --replace=<string>      Replacement text or value to add (required for string/regex/prepend/append modes)\n";
    echo "  --max-depth=<depth>     Maximum directory search depth: 0 = only target dir, 1 = target + 1 level, etc. (default: infinite)\n";
    echo "  --depth-mode=<mode>     Depth search mode: up_to (default) or equal\n";
    echo "  --exclude-dirs=<list>   Comma-separated folders to exclude (default: wp-admin,wp-includes,node_modules,vendor,.git)\n";
    echo "  --run                   Execute changes (default is dry-run / simulation)\n";
    echo "  --help, -h              Display this help message\n\n";
    echo color("Examples:", "1;33") . "\n";
    echo "  1. Dry-run over FTP:\n";
    echo "     php replacer_remote.php --protocol=ftp --host=ftp.example.com --user=bob --dir=/public_html --template=*.html --mode=string --find=\"old.com\" --replace=\"new.com\"\n\n";
    echo "  2. Live run over SFTP with a key:\n";
    echo "     php replacer_remote.php --protocol=sftp --host=example.com --user=bob --key=~/.ssh/id_rsa --dir=/var/www --template=*.php --mode=regex --find='/foo/' --replace='bar' --run\n\n";
}

// ---------------------------------------------------------
// CORE FUNCTIONS (pure — no filesystem access, shared with replacer.php's logic)
// ---------------------------------------------------------

function matches_template($filename, $templates) {
    if (empty($templates)) {
        return true;
    }
    $patterns = explode(',', $templates);
    foreach ($patterns as $pattern) {
        $pattern = trim($pattern);
        if (empty($pattern)) continue;

        $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
        if (preg_match($regex, $filename)) {
            return true;
        }
    }
    return false;
}

// LCS-based Diff algorithm optimized with prefix/suffix trimming
function get_diff($old_lines, $new_lines) {
    $prefix = [];
    $suffix = [];

    $i = 0;
    $n = count($old_lines);
    $m = count($new_lines);

    while ($i < $n && $i < $m && $old_lines[$i] === $new_lines[$i]) {
        $prefix[] = ['type' => 'same', 'line' => $old_lines[$i]];
        $i++;
    }

    $j_old = $n - 1;
    $j_new = $m - 1;
    while ($j_old >= $i && $j_new >= $i && $old_lines[$j_old] === $new_lines[$j_new]) {
        $suffix[] = ['type' => 'same', 'line' => $old_lines[$j_old]];
        $j_old--;
        $j_new--;
    }
    $suffix = array_reverse($suffix);

    $mid_old = array_slice($old_lines, $i, $j_old - $i + 1);
    $mid_new = array_slice($new_lines, $i, $j_new - $i + 1);

    $mid_diff = [];
    $n_mid = count($mid_old);
    $m_mid = count($mid_new);

    if ($n_mid > 0 || $m_mid > 0) {
        if ($n_mid === 0) {
            foreach ($mid_new as $line) {
                $mid_diff[] = ['type' => 'add', 'line' => $line];
            }
        } elseif ($m_mid === 0) {
            foreach ($mid_old as $line) {
                $mid_diff[] = ['type' => 'del', 'line' => $line];
            }
        } else {
            $matrix = [];
            for ($x = 0; $x <= $n_mid; $x++) {
                $matrix[$x][0] = 0;
            }
            for ($y = 0; $y <= $m_mid; $y++) {
                $matrix[0][$y] = 0;
            }
            for ($x = 1; $x <= $n_mid; $x++) {
                for ($y = 1; $y <= $m_mid; $y++) {
                    if ($mid_old[$x-1] === $mid_new[$y-1]) {
                        $matrix[$x][$y] = $matrix[$x-1][$y-1] + 1;
                    } else {
                        $matrix[$x][$y] = max($matrix[$x-1][$y], $matrix[$x][$y-1]);
                    }
                }
            }

            $x = $n_mid;
            $y = $m_mid;
            while ($x > 0 || $y > 0) {
                if ($x > 0 && $y > 0 && $mid_old[$x-1] === $mid_new[$y-1]) {
                    $mid_diff[] = ['type' => 'same', 'line' => $mid_old[$x-1]];
                    $x--;
                    $y--;
                } elseif ($y > 0 && ($x == 0 || $matrix[$x][$y-1] >= $matrix[$x-1][$y])) {
                    $mid_diff[] = ['type' => 'add', 'line' => $mid_new[$y-1]];
                    $y--;
                } else {
                    $mid_diff[] = ['type' => 'del', 'line' => $mid_old[$x-1]];
                    $x--;
                }
            }
            $mid_diff = array_reverse($mid_diff);
        }
    }

    return array_merge($prefix, $mid_diff, $suffix);
}

// Generates one shared id per run so every file touched by the same live-run/apply
// lands under the same backup folder.
function new_backup_run_id() {
    return date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

// Path (relative to BACKUP_DIR) where a given remote file's backup lives for a given run.
// Drops any '.', '..' or empty path segments so this can never resolve outside BACKUP_DIR.
function backup_relative_path($host, $port, $run_id, $absolute_remote_path) {
    $host_safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $host);
    $segments = explode('/', str_replace('\\', '/', $absolute_remote_path));
    $clean = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') continue;
        $clean[] = $seg;
    }
    return $host_safe . '_' . $port . '/' . $run_id . '/' . implode('/', $clean);
}

// Saves the pre-change file contents locally, before a remote file gets overwritten.
// Returns the local backup path on success, or false on failure.
function backup_local_copy($host, $port, $run_id, $absolute_remote_path, $content) {
    $full_path = BACKUP_DIR . '/' . backup_relative_path($host, $port, $run_id, $absolute_remote_path);

    $dir = dirname($full_path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    if (file_put_contents($full_path, $content) === false) {
        return false;
    }
    return $full_path;
}

// Locates a previously-saved backup for restore. Returns the real local path, or false if
// it doesn't exist (or would resolve outside BACKUP_DIR).
function find_backup_copy($host, $port, $run_id, $absolute_remote_path) {
    $full_path = BACKUP_DIR . '/' . backup_relative_path($host, $port, $run_id, $absolute_remote_path);
    $backup_root = realpath(BACKUP_DIR);
    $real_path = realpath($full_path);
    if ($backup_root === false || $real_path === false || strpos($real_path, $backup_root . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    return is_file($real_path) ? $real_path : false;
}

// Perform replacement based on mode (identical semantics to replacer.php)
function apply_replacement($content, $options) {
    $target_endings = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";

    $find = str_replace(["\r\n", "\r"], "\n", $options['find']);
    $find = str_replace("\n", $target_endings, $find);

    $replace = str_replace(["\r\n", "\r"], "\n", $options['replace']);
    $replace = str_replace("\n", $target_endings, $replace);

    switch ($options['mode']) {
        case 'string':
            return str_replace($find, $replace, $content);

        case 'regex':
            $result = @preg_replace($find, $replace, $content);
            return ($result === null) ? $content : $result;

        case 'prepend':
            if (strpos($content, $replace) !== false) {
                return $content;
            }
            return $replace . $target_endings . $content;

        case 'append':
            if (strpos($content, $replace) !== false) {
                return $content;
            }
            return $content . $target_endings . $replace;

        case 'htaccess-bot-blocker':
            $bot_block = trim($replace);
            if (empty($bot_block) || (!preg_match('/RewriteCond/i', $bot_block) && !preg_match('/RewriteRule/i', $bot_block))) {
                $bot_block = "RewriteCond %{HTTP_USER_AGENT} (AhrefsBot|SemrushBot|MJ12bot|DotBot|rogerbot|DataForSeoBot|BLEXBot|serpstatbot|Barkrowler|MegaIndex|GPTBot|ClaudeBot|CCBot|PerplexityBot|Bytespider|Amazonbot|meta-externalagent|Google-Extended|GoogleOther|anthropic-ai|Claude-Web) [NC]\nRewriteRule .* - [F,L]";
                $bot_block = str_replace("\n", $target_endings, $bot_block);
            }

            $bot_block_has_engine = preg_match('/RewriteEngine\s+On/i', $bot_block);
            $content_has_engine = preg_match('/RewriteEngine\s+On/i', $content);

            if (!empty($options['replace']) && (preg_match('/RewriteCond/i', $options['replace']) || preg_match('/RewriteRule/i', $options['replace']))) {
                $normalized_content = preg_replace('/\s+/', '', $content);
                $normalized_block = preg_replace('/\s+/', '', $bot_block);
                if (strpos($normalized_content, $normalized_block) !== false) {
                    return $content;
                }
            } else {
                if (stripos($content, 'AhrefsBot') !== false || stripos($content, 'SemrushBot') !== false) {
                    return $content;
                }
            }

            if ($content_has_engine) {
                if ($bot_block_has_engine) {
                    $cleaned_block = preg_replace('/RewriteEngine\s+On\s*/i', '', $bot_block);
                    $cleaned_block = trim($cleaned_block);
                } else {
                    $cleaned_block = $bot_block;
                }
                return preg_replace('/(RewriteEngine\s+On)/i', "$1" . $target_endings . $cleaned_block, $content, 1);
            } else {
                if ($bot_block_has_engine) {
                    return $bot_block . $target_endings . $target_endings . $content;
                } else {
                    return "RewriteEngine On" . $target_endings . $bot_block . $target_endings . $target_endings . $content;
                }
            }

        default:
            return $content;
    }
}

// ---------------------------------------------------------
// REMOTE FILESYSTEM ADAPTERS
// ---------------------------------------------------------

interface RemoteFs {
    public function connect(array $cfg);
    public function disconnect();
    public function isDir($path);
    public function fileExists($path);
    public function listDir($path); // => [ ['name'=>.., 'is_dir'=>bool], ... ]
    public function getContents($path); // => string|false
    public function putContents($path, $content); // => bool
    public function lastError();
}

class FtpRemoteFs implements RemoteFs {
    private $conn = null;
    private $lastError = '';

    public function connect(array $cfg) {
        $host = $cfg['host'];
        $port = $cfg['port'] ?: 21;
        $timeout = $cfg['timeout'] ?: 30;

        if (!empty($cfg['ssl'])) {
            if (!function_exists('ftp_ssl_connect')) {
                $this->lastError = "This PHP build's ftp extension does not support FTPS (ftp_ssl_connect missing).";
                return false;
            }
            $this->conn = @ftp_ssl_connect($host, $port, $timeout);
        } else {
            $this->conn = @ftp_connect($host, $port, $timeout);
        }

        if (!$this->conn) {
            $this->lastError = "Could not connect to {$host}:{$port}";
            return false;
        }
        if (!@ftp_login($this->conn, $cfg['user'], $cfg['password'])) {
            $this->lastError = "Login failed for user '{$cfg['user']}'";
            $this->conn = null;
            return false;
        }
        @ftp_pasv($this->conn, $cfg['passive'] ?? true);
        return true;
    }

    public function disconnect() {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    public function isDir($path) {
        $cur = @ftp_pwd($this->conn);
        if ($cur === false) return false;
        $ok = @ftp_chdir($this->conn, $path === '' ? '/' : $path);
        if ($ok) {
            @ftp_chdir($this->conn, $cur);
            return true;
        }
        return false;
    }

    public function fileExists($path) {
        if ($this->isDir($path)) return true;
        $parent = dirname($path);
        $parent = ($parent === '' || $parent === '.') ? '/' : $parent;
        $base = basename($path);
        $list = @ftp_nlist($this->conn, $parent);
        if ($list === false) return false;
        foreach ($list as $entry) {
            if (basename($entry) === $base) return true;
        }
        return false;
    }

    public function listDir($path) {
        $entries = @ftp_nlist($this->conn, $path === '' ? '/' : $path);
        if ($entries === false) return [];
        $result = [];
        $seen = [];
        foreach ($entries as $entry) {
            $name = basename($entry);
            if ($name === '.' || $name === '..' || $name === '') continue;
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $full = rtrim($path, '/') . '/' . $name;
            $result[] = ['name' => $name, 'is_dir' => $this->isDir($full)];
        }
        return $result;
    }

    public function getContents($path) {
        $stream = fopen('php://temp', 'r+');
        if (!@ftp_fget($this->conn, $stream, $path, FTP_BINARY)) {
            fclose($stream);
            $this->lastError = "Could not download '{$path}'";
            return false;
        }
        rewind($stream);
        $data = stream_get_contents($stream);
        fclose($stream);
        return $data;
    }

    public function putContents($path, $content) {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $ok = @ftp_fput($this->conn, $path, $stream, FTP_BINARY);
        fclose($stream);
        if (!$ok) {
            $this->lastError = "Could not upload '{$path}'";
        }
        return (bool) $ok;
    }

    public function lastError() {
        return $this->lastError;
    }
}

class SftpRemoteFs implements RemoteFs {
    /** @var \phpseclib3\Net\SFTP|null */
    private $sftp = null;
    private $lastError = '';

    public function connect(array $cfg) {
        if (!class_exists('\phpseclib3\Net\SFTP')) {
            $this->lastError = "phpseclib3 is not installed. Run 'composer install' in the replacer directory to enable SFTP support.";
            return false;
        }
        $host = $cfg['host'];
        $port = $cfg['port'] ?: 22;
        $timeout = $cfg['timeout'] ?: 30;

        $this->sftp = new \phpseclib3\Net\SFTP($host, $port, $timeout);

        $auth = $cfg['password'];
        if (!empty($cfg['key'])) {
            $keyData = @file_get_contents($cfg['key']);
            if ($keyData === false) {
                $this->lastError = "Could not read private key file '{$cfg['key']}'";
                return false;
            }
            try {
                $auth = \phpseclib3\Crypt\PublicKeyLoader::load($keyData, $cfg['passphrase'] !== '' ? $cfg['passphrase'] : false);
            } catch (\Exception $e) {
                $this->lastError = "Could not load private key: " . $e->getMessage();
                return false;
            }
        }

        if (!$this->sftp->login($cfg['user'], $auth)) {
            $this->lastError = "SFTP login failed for user '{$cfg['user']}'";
            return false;
        }
        return true;
    }

    public function disconnect() {
        $this->sftp = null;
    }

    public function isDir($path) {
        return (bool) $this->sftp->is_dir($path === '' ? '/' : $path);
    }

    public function fileExists($path) {
        return (bool) $this->sftp->file_exists($path);
    }

    public function listDir($path) {
        $names = $this->sftp->nlist($path === '' ? '/' : $path);
        if ($names === false) return [];
        $result = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = rtrim($path, '/') . '/' . $name;
            $result[] = ['name' => $name, 'is_dir' => $this->sftp->is_dir($full)];
        }
        return $result;
    }

    public function getContents($path) {
        $data = $this->sftp->get($path);
        if ($data === false) {
            $this->lastError = "Could not download '{$path}'";
            return false;
        }
        return $data;
    }

    public function putContents($path, $content) {
        $ok = $this->sftp->put($path, $content);
        if (!$ok) {
            $this->lastError = "Could not upload '{$path}'";
        }
        return (bool) $ok;
    }

    public function lastError() {
        if ($this->sftp) {
            $err = $this->sftp->getLastSFTPError();
            if (!empty($err)) return $err;
        }
        return $this->lastError;
    }
}

function make_remote_fs($protocol) {
    return $protocol === 'sftp' ? new SftpRemoteFs() : new FtpRemoteFs();
}

// ---------------------------------------------------------
// REMOTE PATH HELPERS
// ---------------------------------------------------------

function remote_normalize($path) {
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path);
    if ($path === '') return '/';
    if ($path !== '/') $path = rtrim($path, '/');
    return $path;
}

function remote_join($base, $name) {
    $base = rtrim($base, '/');
    return ($base === '' ? '' : $base) . '/' . $name;
}

function is_relpath_excluded($rel, $exclude_dirs) {
    if (empty($exclude_dirs) || $rel === '') {
        return false;
    }
    $parts = explode('/', $rel);
    foreach ($exclude_dirs as $exclude) {
        $exclude = trim(str_replace('\\', '/', $exclude), '/');
        if ($exclude === '') continue;
        if ($exclude === $rel || strpos($rel, $exclude . '/') === 0 || in_array($exclude, $parts, true)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------
// REMOTE DIRECTORY TRAVERSAL
// ---------------------------------------------------------

function has_web_files_remote($fs, $dir, $exclude_dirs = [], $rel = '') {
    $web_extensions = ['php', 'html', 'htm', 'shtml', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py'];
    $entries = $fs->listDir($dir);
    foreach ($entries as $entry) {
        $child_rel = $rel === '' ? $entry['name'] : $rel . '/' . $entry['name'];
        $full = remote_join($dir, $entry['name']);
        if ($entry['is_dir']) {
            if (!is_relpath_excluded($child_rel, $exclude_dirs)) {
                if (has_web_files_remote($fs, $full, $exclude_dirs, $child_rel)) {
                    return true;
                }
            }
        } else {
            $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $web_extensions, true)) {
                return true;
            }
        }
    }
    return false;
}

function find_files_recursive_remote($fs, $dir, $rel, $templates, $max_depth, $depth_mode, $exclude_dirs, $current_depth) {
    if (is_relpath_excluded($rel, $exclude_dirs)) {
        return [];
    }
    $files = [];
    $entries = $fs->listDir($dir);
    foreach ($entries as $entry) {
        $name = $entry['name'];
        $full = remote_join($dir, $name);
        $child_rel = $rel === '' ? $name : $rel . '/' . $name;
        if ($entry['is_dir']) {
            if ($max_depth === -1 || $current_depth < $max_depth) {
                $files = array_merge($files, find_files_recursive_remote($fs, $full, $child_rel, $templates, $max_depth, $depth_mode, $exclude_dirs, $current_depth + 1));
            }
        } else {
            if (matches_template($name, $templates)) {
                if ($max_depth !== -1 && $max_depth >= 0 && $depth_mode === 'equal' && $current_depth !== $max_depth) {
                    continue;
                }
                $files[] = ['path' => $full, 'rel' => $child_rel];
            }
        }
    }
    return $files;
}

function find_files_remote($fs, $dir, $templates, $max_depth = -1, $depth_mode = 'up_to', $exclude_dirs = []) {
    return find_files_recursive_remote($fs, $dir, '', $templates, $max_depth, $depth_mode, $exclude_dirs, 0);
}

function find_scanned_dirs_recursive_remote($fs, $dir, $rel, $max_depth, $depth_mode, $exclude_dirs, $current_depth) {
    if (is_relpath_excluded($rel, $exclude_dirs)) {
        return [];
    }
    $dirs = [];
    if ($max_depth === -1 || $depth_mode === 'up_to') {
        $dirs[] = ['path' => $dir, 'rel' => $rel];
    } elseif ($depth_mode === 'equal' && $current_depth === $max_depth) {
        $dirs[] = ['path' => $dir, 'rel' => $rel];
    }

    if ($max_depth !== -1 && $current_depth >= $max_depth) {
        return $dirs;
    }

    $entries = $fs->listDir($dir);
    foreach ($entries as $entry) {
        if (!$entry['is_dir']) continue;
        $full = remote_join($dir, $entry['name']);
        $child_rel = $rel === '' ? $entry['name'] : $rel . '/' . $entry['name'];
        $dirs = array_merge($dirs, find_scanned_dirs_recursive_remote($fs, $full, $child_rel, $max_depth, $depth_mode, $exclude_dirs, $current_depth + 1));
    }
    return $dirs;
}

function find_scanned_dirs_remote($fs, $dir, $max_depth = -1, $depth_mode = 'up_to', $exclude_dirs = []) {
    return find_scanned_dirs_recursive_remote($fs, $dir, '', $max_depth, $depth_mode, $exclude_dirs, 0);
}

function match_dirs_by_regex_recursive_remote($fs, $dir, $rel, $pattern, $depth) {
    if ($depth > 5) return [];
    $entries = $fs->listDir($dir);
    $matched = [];
    foreach ($entries as $entry) {
        if (!$entry['is_dir']) continue;
        $full = remote_join($dir, $entry['name']);
        $child_rel = $rel === '' ? $entry['name'] : $rel . '/' . $entry['name'];
        if (preg_match($pattern, $child_rel)) {
            $matched[] = $full;
        }
        $matched = array_merge($matched, match_dirs_by_regex_recursive_remote($fs, $full, $child_rel, $pattern, $depth + 1));
    }
    return $matched;
}

function match_dirs_by_regex_remote($fs, $base_dir, $pattern) {
    if (empty($pattern)) return [];
    if (!preg_match('/^([~\/#]).*\1[imsuxADSUXJu]*$/', $pattern)) {
        $pattern = '~' . $pattern . '~i';
    }
    return match_dirs_by_regex_recursive_remote($fs, $base_dir, '', $pattern, 0);
}

// Matches wildcard path segments (any position, not just the last) against each level's directory listing.
function expand_remote_glob($fs, $raw_path) {
    $raw_path = rtrim($raw_path, '/');
    if ($raw_path === '') return [];

    $parts = explode('/', $raw_path);
    $current_paths = [''];

    foreach ($parts as $part) {
        if ($part === '') continue;
        $next_paths = [];
        if (strpos($part, '*') !== false || strpos($part, '?') !== false) {
            $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($part, '/')) . '$/i';
            foreach ($current_paths as $base) {
                $entries = $fs->listDir($base === '' ? '/' : $base);
                foreach ($entries as $entry) {
                    if ($entry['is_dir'] && preg_match($regex, $entry['name'])) {
                        $next_paths[] = remote_join($base, $entry['name']);
                    }
                }
            }
        } else {
            foreach ($current_paths as $base) {
                $next_paths[] = remote_join($base, $part);
            }
        }
        $current_paths = $next_paths;
    }

    $matches = [];
    foreach ($current_paths as $p) {
        if ($fs->isDir($p)) $matches[] = $p;
    }
    return array_values(array_unique($matches));
}

function get_expanded_target_dirs_remote($fs, $dir_input) {
    $raw_dirs = explode(',', $dir_input);
    $resolved = [];
    foreach ($raw_dirs as $raw_path) {
        $raw_path = trim($raw_path);
        if ($raw_path === '') continue;

        if (strpos($raw_path, 'regex:') !== false) {
            $base_dir = '/';
            $pattern = '';
            if (strpos($raw_path, '|regex:') !== false) {
                list($base_dir, $pattern) = explode('|regex:', $raw_path, 2);
            } else {
                list(, $pattern) = explode('regex:', $raw_path, 2);
            }
            $base_dir = trim($base_dir) !== '' ? remote_normalize(trim($base_dir)) : '/';
            $pattern = trim($pattern);
            if ($fs->isDir($base_dir)) {
                foreach (match_dirs_by_regex_remote($fs, $base_dir, $pattern) as $m) {
                    $resolved[] = $m;
                }
            }
        } elseif (strpos($raw_path, '*') !== false || strpos($raw_path, '?') !== false) {
            foreach (expand_remote_glob($fs, remote_normalize($raw_path)) as $m) {
                $resolved[] = $m;
            }
        } else {
            $norm = remote_normalize($raw_path);
            if ($fs->isDir($norm)) {
                $resolved[] = $norm;
            }
        }
    }
    return array_values(array_unique($resolved));
}

// Gather remote files and execute replacements, returns data for diffs/results
function execute_replacer_remote($fs, $options) {
    $target_dirs = get_expanded_target_dirs_remote($fs, $options['dir']);
    if (empty($target_dirs)) {
        return ['error' => "No valid remote target directories found matching input '{$options['dir']}'."];
    }

    $files = [];
    $exclude_input = $options['exclude-dirs'] ?? '';
    $exclude_dirs = $exclude_input !== '' ? explode(',', $exclude_input) : [];

    foreach ($target_dirs as $real) {
        $found = find_files_remote($fs, $real, $options['template'], $options['max-depth'], $options['depth-mode'] ?? 'up_to', $exclude_dirs);
        foreach ($found as $f) {
            $files[] = ['absolute' => $f['path'], 'rel' => $f['rel'], 'base_dir' => $real];
        }

        if ($options['mode'] === 'htaccess-bot-blocker' && matches_template('.htaccess', $options['template'])) {
            $scanned_dirs = find_scanned_dirs_remote($fs, $real, $options['max-depth'], $options['depth-mode'] ?? 'up_to', $exclude_dirs);
            foreach ($scanned_dirs as $d) {
                $htaccess_file = remote_join($d['path'], '.htaccess');
                if (!$fs->fileExists($htaccess_file)) {
                    if (!has_web_files_remote($fs, $d['path'], $exclude_dirs, $d['rel'])) {
                        continue;
                    }
                    $already_in = false;
                    foreach ($files as $existing) {
                        if ($existing['absolute'] === $htaccess_file) {
                            $already_in = true;
                            break;
                        }
                    }
                    if (!$already_in) {
                        $files[] = [
                            'absolute' => $htaccess_file,
                            'rel' => ($d['rel'] === '' ? '.htaccess' : $d['rel'] . '/.htaccess'),
                            'base_dir' => $real,
                            'is_new' => true,
                        ];
                    }
                }
            }
        }
    }

    $results = [
        'target_dirs' => $target_dirs,
        'total_files' => count($files),
        'modified_count' => 0,
        'skipped_count' => 0,
        'files' => [],
    ];

    $is_multi_dir = count($target_dirs) > 1;

    $run_id = null;
    $backup_port = $options['port'] ?: ($options['protocol'] === 'sftp' ? 22 : 21);
    if ($options['run']) {
        $run_id = new_backup_run_id();
        $results['backup_run_id'] = $run_id;
        $results['backup_dir'] = BACKUP_DIR . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $options['host']) . '_' . $backup_port . '/' . $run_id;
    }

    foreach ($files as $fileData) {
        $file = $fileData['absolute'];
        $base_dir = $fileData['base_dir'];
        $relative_path = $fileData['rel'];
        $display_path = $is_multi_dir ? trim(basename($base_dir) . '/' . $relative_path, '/') : $relative_path;

        $is_new = !empty($fileData['is_new']);

        if ($is_new) {
            $content = '';
        } else {
            $content = $fs->getContents($file);
            if ($content === false) {
                $results['files'][] = [
                    'path' => $display_path,
                    'absolute' => $file,
                    'status' => 'error',
                    'message' => 'Could not read file: ' . $fs->lastError(),
                ];
                continue;
            }
        }

        $new_content = apply_replacement($content, $options);

        if ($new_content === $content) {
            $results['skipped_count']++;
            $results['files'][] = [
                'path' => $display_path,
                'absolute' => $file,
                'status' => 'unchanged',
                'diff' => [],
            ];
            continue;
        }

        $results['modified_count']++;

        $old_lines = explode("\n", rtrim($content));
        $new_lines = explode("\n", rtrim($new_content));
        $diff = get_diff($old_lines, $new_lines);

        $write_success = true;
        $backup_path = null;
        if ($options['run']) {
            if (!$is_new) {
                $backup_path = backup_local_copy($options['host'], $backup_port, $run_id, $file, $content);
                if ($backup_path === false) {
                    $write_success = false;
                    $results['files'][] = [
                        'path' => $display_path,
                        'absolute' => $file,
                        'status' => $is_new ? 'created' : 'modified',
                        'write_success' => false,
                        'backup_error' => true,
                        'diff' => $diff,
                    ];
                    continue;
                }
            }
            if (!$fs->putContents($file, $new_content)) {
                $write_success = false;
            }
        }

        $results['files'][] = [
            'path' => $display_path,
            'absolute' => $file,
            'status' => $is_new ? 'created' : 'modified',
            'write_success' => $write_success,
            'backup_path' => $backup_path ?: null,
            'diff' => $diff,
        ];
    }

    return $results;
}

// ---------------------------------------------------------
// RUNTIME HANDLER
// ---------------------------------------------------------

if ($is_cli) {
    // ---------------------------------------------------------
    // CLI EXECUTION
    // ---------------------------------------------------------

    $options = [
        'protocol' => 'ftp',
        'host' => '',
        'port' => 0,
        'user' => '',
        'password' => '',
        'key' => '',
        'passphrase' => '',
        'ssl' => false,
        'passive' => true,
        'timeout' => 30,
        'dir' => '/',
        'template' => '',
        'mode' => '',
        'find' => '',
        'replace' => '',
        'run' => false,
        'max-depth' => -1,
        'depth-mode' => 'up_to',
        'exclude-dirs' => 'wp-admin,wp-includes,node_modules,vendor,.git',
        'help' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--run') {
            $options['run'] = true;
        } elseif ($arg === '--ssl') {
            $options['ssl'] = true;
        } elseif ($arg === '--active') {
            $options['passive'] = false;
        } elseif (strpos($arg, '--protocol=') === 0) {
            $options['protocol'] = strtolower(substr($arg, 11));
        } elseif (strpos($arg, '--host=') === 0) {
            $options['host'] = substr($arg, 7);
        } elseif (strpos($arg, '--port=') === 0) {
            $options['port'] = (int)substr($arg, 7);
        } elseif (strpos($arg, '--user=') === 0) {
            $options['user'] = substr($arg, 7);
        } elseif (strpos($arg, '--password=') === 0) {
            $options['password'] = substr($arg, 11);
        } elseif (strpos($arg, '--password-env=') === 0) {
            $options['password'] = getenv(substr($arg, 15)) ?: '';
        } elseif (strpos($arg, '--key=') === 0) {
            $options['key'] = substr($arg, 6);
        } elseif (strpos($arg, '--passphrase=') === 0) {
            $options['passphrase'] = substr($arg, 13);
        } elseif (strpos($arg, '--timeout=') === 0) {
            $options['timeout'] = (int)substr($arg, 10);
        } elseif (strpos($arg, '--dir=') === 0) {
            $options['dir'] = substr($arg, 6);
        } elseif (strpos($arg, '--template=') === 0) {
            $options['template'] = substr($arg, 11);
        } elseif (strpos($arg, '--mode=') === 0) {
            $options['mode'] = substr($arg, 7);
        } elseif (strpos($arg, '--find=') === 0) {
            $options['find'] = substr($arg, 7);
        } elseif (strpos($arg, '--replace=') === 0) {
            $options['replace'] = substr($arg, 10);
        } elseif (strpos($arg, '--max-depth=') === 0) {
            $options['max-depth'] = (int)substr($arg, 12);
        } elseif (strpos($arg, '--depth-mode=') === 0) {
            $options['depth-mode'] = substr($arg, 13);
        } elseif (strpos($arg, '--exclude-dirs=') === 0) {
            $options['exclude-dirs'] = substr($arg, 15);
        }
    }

    if ($options['help']) {
        print_help();
        exit(0);
    }

    if (!in_array($options['protocol'], ['ftp', 'sftp'], true)) {
        echo color("Error: --protocol must be 'ftp' or 'sftp'.\n", "1;31");
        exit(1);
    }
    if (empty($options['host'])) {
        echo color("Error: --host is required.\n\n", "1;31");
        print_help();
        exit(1);
    }
    if (empty($options['user'])) {
        echo color("Error: --user is required.\n", "1;31");
        exit(1);
    }
    if (empty($options['mode'])) {
        echo color("Error: --mode is required.\n\n", "1;31");
        print_help();
        exit(1);
    }
    if (!in_array($options['mode'], ['string', 'regex', 'prepend', 'append', 'htaccess-bot-blocker'], true)) {
        echo color("Error: Invalid mode '{$options['mode']}'. Allowed modes: string, regex, prepend, append, htaccess-bot-blocker.\n", "1;31");
        exit(1);
    }
    if (!in_array($options['depth-mode'], ['up_to', 'equal'], true)) {
        echo color("Error: Invalid depth-mode '{$options['depth-mode']}'. Allowed modes: up_to, equal.\n", "1;31");
        exit(1);
    }
    if (in_array($options['mode'], ['string', 'regex'], true) && empty($options['find'])) {
        echo color("Error: --find is required for mode '{$options['mode']}'.\n", "1;31");
        exit(1);
    }

    if ($options['password'] === '' && $options['key'] === '') {
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            echo "Password for {$options['user']}@{$options['host']}: ";
            if (DIRECTORY_SEPARATOR !== '\\') {
                shell_exec('stty -echo');
                $options['password'] = trim(fgets(STDIN));
                shell_exec('stty echo');
                echo "\n";
            } else {
                $options['password'] = trim(fgets(STDIN));
            }
        } else {
            echo color("Error: no --password, --password-env, or --key supplied and no TTY to prompt on.\n", "1;31");
            exit(1);
        }
    }

    $port = $options['port'] ?: ($options['protocol'] === 'sftp' ? 22 : 21);
    echo color("Connecting to {$options['protocol']}://{$options['host']}:{$port} as {$options['user']}...", "1;34") . "\n";

    $fs = make_remote_fs($options['protocol']);
    if (!$fs->connect($options)) {
        echo color("Error: " . $fs->lastError() . "\n", "1;31");
        exit(1);
    }
    echo color("Connected.\n\n", "1;32");

    $results = execute_replacer_remote($fs, $options);
    $fs->disconnect();

    if (isset($results['error'])) {
        echo color("Error: " . $results['error'] . "\n", "1;31");
        exit(1);
    }

    echo color("Scanning remote directories: " . implode(', ', $results['target_dirs']), "1;34") . "\n";
    echo "Template filter: " . ($options['template'] ?: "* (all files)") . "\n";
    echo "Search depth: " . ($options['max-depth'] === -1 ? "Infinite" : $options['max-depth']) . " (" . ($options['depth-mode'] === 'equal' ? "Exactly at depth" : "Up to depth") . ")\n";
    echo "Excluded folders: " . ($options['exclude-dirs'] ?: "None") . "\n";
    echo "Replacement Mode: " . $options['mode'] . "\n";
    echo "Execution Mode: " . ($options['run'] ? color("LIVE RUN (Writing to remote files)", "1;31") : color("DRY RUN (Simulated preview)", "1;32")) . "\n";
    if ($options['run'] && isset($results['backup_dir'])) {
        echo "Backups (pre-change originals) saved to: " . color($results['backup_dir'], "1;36") . "\n";
    }
    echo "\n";

    echo "Found " . color($results['total_files'], "1;33") . " matching file(s).\n\n";

    function print_cli_diff($diff, $context = 3) {
        $total = count($diff);
        $to_print = array_fill(0, $total, false);
        for ($i = 0; $i < $total; $i++) {
            if ($diff[$i]['type'] !== 'same') {
                for ($c = -$context; $c <= $context; $c++) {
                    $idx = $i + $c;
                    if ($idx >= 0 && $idx < $total) $to_print[$idx] = true;
                }
            }
        }
        $in_gap = false;
        for ($i = 0; $i < $total; $i++) {
            if ($to_print[$i]) {
                if ($in_gap) {
                    echo color("@@ ... @@\n", "33");
                    $in_gap = false;
                }
                $item = $diff[$i];
                if ($item['type'] === 'add') {
                    echo color("+ " . $item['line'], "32") . "\n";
                } elseif ($item['type'] === 'del') {
                    echo color("- " . $item['line'], "31") . "\n";
                } else {
                    echo "  " . $item['line'] . "\n";
                }
            } else {
                $in_gap = true;
            }
        }
    }

    foreach ($results['files'] as $fileData) {
        if ($fileData['status'] === 'error') {
            echo color("[ERROR] {$fileData['path']} - {$fileData['message']}\n", "31");
        } elseif ($fileData['status'] === 'modified' || $fileData['status'] === 'created') {
            $prefix = $fileData['status'] === 'created' ? '[CREATED]' : '[MODIFIED]';
            echo color("{$prefix} {$fileData['path']}", "1;33") . "\n";
            print_cli_diff($fileData['diff']);
            echo "\n";
            if ($options['run']) {
                if (!empty($fileData['backup_error'])) {
                    echo color("[ERROR] Backup failed, write skipped for: {$fileData['path']}\n\n", "1;31");
                } elseif ($fileData['write_success']) {
                    echo color("[SUCCESS] Applied changes to: {$fileData['path']}\n\n", "1;32");
                } else {
                    echo color("[ERROR] Failed writing to: {$fileData['path']}\n\n", "1;31");
                }
            }
        }
    }

    echo color("=== Summary ===", "1;36") . "\n";
    echo "Total matching files: {$results['total_files']}\n";
    echo "Files proposed/modified: {$results['modified_count']}\n";
    echo "Files unchanged: {$results['skipped_count']}\n";

    if (!$options['run'] && $results['modified_count'] > 0) {
        echo "\n" . color("To apply these changes permanently, rerun the command with the --run flag.", "1;33") . "\n";
    }
    exit(0);

} else {
    // ---------------------------------------------------------
    // WEB EXECUTION
    // ---------------------------------------------------------

    if (!ALLOW_REMOTE_ACCESS && !is_local_request()) {
        header('HTTP/1.1 403 Forbidden');
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Access Forbidden</title>
            <style>
                body { background-color: #0f172a; color: #f1f5f9; font-family: system-ui, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                .card { background-color: #1e293b; border: 1px solid #334155; padding: 2.5rem; border-radius: 12px; max-width: 500px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); text-align: center; }
                h1 { color: #f43f5e; margin-top: 0; }
                p { color: #94a3b8; line-height: 1.6; }
                code { background-color: #0f172a; padding: 2px 6px; border-radius: 4px; color: #38bdf8; font-family: monospace; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1>Access Restricted</h1>
                <p>For security, the web interface of the Remote (FTP/SFTP) Batch Replacer is restricted to <strong>localhost</strong> loopback requests by default — this form submits remote server credentials on every request.</p>
                <p>To enable remote access, edit <code>replacer_remote.php</code> and set <code>ALLOW_REMOTE_ACCESS</code> to <code>true</code>.</p>
            </div>
        </body>
        </html>
        <?php
        exit(0);
    }

    function build_connection_config($src) {
        return [
            'protocol' => (isset($src['protocol']) && $src['protocol'] === 'sftp') ? 'sftp' : 'ftp',
            'host' => trim($src['host'] ?? ''),
            'port' => (isset($src['port']) && $src['port'] !== '') ? (int)$src['port'] : 0,
            'user' => $src['user'] ?? '',
            'password' => $src['password'] ?? '',
            'key' => trim($src['key'] ?? ''),
            'passphrase' => $src['passphrase'] ?? '',
            'ssl' => !empty($src['ssl']),
            'passive' => empty($src['active']),
            'timeout' => (isset($src['timeout']) && $src['timeout'] !== '') ? (int)$src['timeout'] : 30,
        ];
    }

    function build_replace_options($src) {
        return [
            'dir' => $src['dir'] ?? '/',
            'template' => $src['template'] ?? '',
            'mode' => $src['mode'] ?? '',
            'find' => $src['find'] ?? '',
            'replace' => $src['replace'] ?? '',
            'run' => !empty($src['run']) && in_array($src['run'], ['1', 'on', 'true'], true),
            'max-depth' => (isset($src['max-depth']) && $src['max-depth'] !== '') ? (int)$src['max-depth'] : -1,
            'depth-mode' => $src['depth-mode'] ?? 'up_to',
            'exclude-dirs' => $src['exclude-dirs'] ?? 'wp-admin,wp-includes,node_modules,vendor,.git',
        ];
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'scan') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $cfg = build_connection_config($_POST);
        $options = array_merge($cfg, build_replace_options($_POST));

        if ($cfg['host'] === '' || $cfg['user'] === '') {
            echo json_encode(['success' => false, 'error' => 'Host and username are required.']);
            exit(0);
        }
        if (empty($options['mode'])) {
            echo json_encode(['success' => false, 'error' => 'Replacement Mode is required.']);
            exit(0);
        }
        if (!in_array($options['mode'], ['string', 'regex', 'prepend', 'append', 'htaccess-bot-blocker'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid replacement mode.']);
            exit(0);
        }
        if (in_array($options['mode'], ['string', 'regex'], true) && $options['find'] === '') {
            echo json_encode(['success' => false, 'error' => "Search pattern (Find) is required for mode '{$options['mode']}'."]);
            exit(0);
        }
        if (!in_array($options['depth-mode'], ['up_to', 'equal'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid depth-mode.']);
            exit(0);
        }

        $fs = make_remote_fs($cfg['protocol']);
        if (!$fs->connect($cfg)) {
            echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $fs->lastError()]);
            exit(0);
        }

        $results = execute_replacer_remote($fs, $options);
        $fs->disconnect();

        if (isset($results['error'])) {
            echo json_encode(['success' => false, 'error' => $results['error']]);
            exit(0);
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit(0);
    }

    if ($action === 'apply_single') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $cfg = build_connection_config($_POST);
        $options = array_merge($cfg, build_replace_options($_POST));
        $options['run'] = true;

        $target_file = isset($_POST['file']) ? remote_normalize($_POST['file']) : '';
        if ($target_file === '' || $target_file === '/') {
            echo json_encode(['success' => false, 'error' => 'No target file specified.']);
            exit(0);
        }

        $fs = make_remote_fs($cfg['protocol']);
        if (!$fs->connect($cfg)) {
            echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $fs->lastError()]);
            exit(0);
        }

        $target_dirs = get_expanded_target_dirs_remote($fs, $options['dir']);
        $is_inside = false;
        foreach ($target_dirs as $td) {
            $td_norm = rtrim(remote_normalize($td), '/') . '/';
            if (strpos($target_file . '/', $td_norm) === 0) {
                $is_inside = true;
                break;
            }
        }

        if (!$is_inside) {
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => 'Access Denied: Target file is outside targeted directories.']);
            exit(0);
        }

        $is_new = !$fs->fileExists($target_file);
        if ($is_new) {
            if ($options['mode'] !== 'htaccess-bot-blocker' || basename($target_file) !== '.htaccess') {
                $fs->disconnect();
                echo json_encode(['success' => false, 'error' => 'Creation of new files is only allowed for .htaccess in bot blocker mode.']);
                exit(0);
            }
            $content = '';
        } else {
            $content = $fs->getContents($target_file);
            if ($content === false) {
                $fs->disconnect();
                echo json_encode(['success' => false, 'error' => 'Could not read target file: ' . $fs->lastError()]);
                exit(0);
            }
        }

        $new_content = apply_replacement($content, $options);

        $backup_path = null;
        $backup_run_id = null;
        if (!$is_new) {
            $backup_port = $cfg['port'] ?: ($cfg['protocol'] === 'sftp' ? 22 : 21);
            $backup_run_id = new_backup_run_id();
            $backup_path = backup_local_copy($cfg['host'], $backup_port, $backup_run_id, $target_file, $content);
            if ($backup_path === false) {
                $fs->disconnect();
                echo json_encode(['success' => false, 'error' => 'Failed to save a local backup before writing; aborted without touching the remote file.']);
                exit(0);
            }
        }

        if (!$fs->putContents($target_file, $new_content)) {
            $err = $fs->lastError();
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => 'Failed to write to remote file: ' . $err]);
            exit(0);
        }

        $fs->disconnect();
        echo json_encode(['success' => true, 'backup_path' => $backup_path, 'backup_run_id' => $backup_run_id]);
        exit(0);
    }

    if ($action === 'restore_backup') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $cfg = build_connection_config($_POST);
        $target_file = isset($_POST['file']) ? remote_normalize($_POST['file']) : '';
        $run_id = $_POST['run_id'] ?? '';
        $dir = $_POST['dir'] ?? '/';

        if ($target_file === '' || $target_file === '/') {
            echo json_encode(['success' => false, 'error' => 'No target file specified.']);
            exit(0);
        }
        if (!preg_match('/^\d{8}_\d{6}_[0-9a-f]{6}$/', $run_id)) {
            echo json_encode(['success' => false, 'error' => 'Invalid or missing backup reference.']);
            exit(0);
        }

        $fs = make_remote_fs($cfg['protocol']);
        if (!$fs->connect($cfg)) {
            echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $fs->lastError()]);
            exit(0);
        }

        $target_dirs = get_expanded_target_dirs_remote($fs, $dir);
        $is_inside = false;
        foreach ($target_dirs as $td) {
            $td_norm = rtrim(remote_normalize($td), '/') . '/';
            if (strpos($target_file . '/', $td_norm) === 0) {
                $is_inside = true;
                break;
            }
        }
        if (!$is_inside) {
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => 'Access Denied: Target file is outside targeted directories.']);
            exit(0);
        }

        $backup_port = $cfg['port'] ?: ($cfg['protocol'] === 'sftp' ? 22 : 21);
        $backup_file = find_backup_copy($cfg['host'], $backup_port, $run_id, $target_file);
        if ($backup_file === false) {
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => 'No backup found for this file and run.']);
            exit(0);
        }
        $backup_content = file_get_contents($backup_file);
        if ($backup_content === false) {
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => 'Could not read the backup file.']);
            exit(0);
        }

        // Restoring is itself an overwrite, so snapshot the file's current (edited) state first.
        $pre_restore_backup_path = null;
        $current_content = $fs->getContents($target_file);
        if ($current_content !== false) {
            $pre_restore_backup_path = backup_local_copy($cfg['host'], $backup_port, new_backup_run_id(), $target_file, $current_content);
        }

        if (!$fs->putContents($target_file, $backup_content)) {
            $err = $fs->lastError();
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => 'Failed to write restored content to remote file: ' . $err]);
            exit(0);
        }

        $fs->disconnect();
        echo json_encode(['success' => true, 'pre_restore_backup_path' => $pre_restore_backup_path ?: null]);
        exit(0);
    }

    if ($action === 'list_dir') {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $cfg = build_connection_config($_POST);
        $path = remote_normalize($_POST['path'] ?? '/');

        if ($cfg['host'] === '' || $cfg['user'] === '') {
            echo json_encode(['success' => false, 'error' => 'Host and username are required.']);
            exit(0);
        }

        $fs = make_remote_fs($cfg['protocol']);
        if (!$fs->connect($cfg)) {
            echo json_encode(['success' => false, 'error' => 'Connection failed: ' . $fs->lastError()]);
            exit(0);
        }
        if (!$fs->isDir($path)) {
            $fs->disconnect();
            echo json_encode(['success' => false, 'error' => "Not a directory: {$path}"]);
            exit(0);
        }
        $entries = $fs->listDir($path);
        $fs->disconnect();

        $dirs = [];
        foreach ($entries as $e) {
            if ($e['is_dir']) $dirs[] = $e['name'];
        }
        sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
        echo json_encode(['success' => true, 'path' => $path, 'dirs' => $dirs]);
        exit(0);
    }

    // Default: render the page shell. All scanning/applying happens client-side via fetch().
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PHP Batch File Replacer — Remote (FTP/SFTP)</title>
        <style>
            :root {
                --bg-primary: #090d16;
                --bg-card: #111726;
                --bg-card-hover: #171f32;
                --text-primary: #f3f4f6;
                --text-secondary: #9ca3af;
                --accent: #6366f1;
                --accent-hover: #4f46e5;
                --accent-danger: #ef4444;
                --accent-danger-hover: #dc2626;
                --border: #272f44;
                --diff-add-bg: rgba(16, 185, 129, 0.12);
                --diff-add-text: #34d399;
                --diff-del-bg: rgba(239, 68, 68, 0.12);
                --diff-del-text: #f87171;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background-color: var(--bg-primary);
                color: var(--text-primary);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                min-height: 100vh;
                padding: 2rem;
                line-height: 1.5;
            }
            .container { max-width: 100%; width: 100%; margin: 0 auto; }
            header {
                margin-bottom: 2rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-bottom: 1px solid var(--border);
                padding-bottom: 1.5rem;
                flex-wrap: wrap;
                gap: 1rem;
            }
            .header-title h1 {
                font-size: 1.8rem;
                font-weight: 700;
                background: linear-gradient(135deg, #a5b4fc, #6366f1);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin-bottom: 0.25rem;
            }
            .header-title p { color: var(--text-secondary); font-size: 0.95rem; }
            .badge-security {
                background-color: rgba(16, 185, 129, 0.15);
                color: #34d399;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
                border: 1px solid rgba(16, 185, 129, 0.3);
                white-space: nowrap;
            }
            .main-grid { display: grid; grid-template-columns: 420px minmax(0, 1fr); gap: 2rem; align-items: start; }
            @media (max-width: 1024px) { .main-grid { grid-template-columns: 1fr; } }
            .card {
                background-color: var(--bg-card);
                border: 1px solid var(--border);
                border-radius: 16px;
                padding: 1.75rem;
                box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            }
            fieldset { border: 1px solid var(--border); border-radius: 10px; padding: 1rem; margin-bottom: 1.25rem; }
            legend { padding: 0 8px; font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
            .form-group { margin-bottom: 1.1rem; }
            .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.4rem; }
            input[type="text"], input[type="password"], input[type="number"], select, textarea {
                width: 100%;
                background-color: rgba(9, 13, 22, 0.5);
                border: 1px solid var(--border);
                color: var(--text-primary);
                padding: 9px 12px;
                border-radius: 8px;
                font-size: 0.9rem;
                font-family: inherit;
            }
            textarea { resize: vertical; min-height: 70px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
            input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
            .help-text { font-size: 0.72rem; color: var(--text-secondary); margin-top: 0.3rem; }
            .checkbox-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
            .checkbox-row label { margin: 0; font-size: 0.85rem; color: var(--text-primary); }
            .checkbox-wrapper {
                background-color: rgba(239, 68, 68, 0.03);
                border: 1px dashed rgba(239, 68, 68, 0.2);
                padding: 0.85rem;
                border-radius: 10px;
                margin-bottom: 1.25rem;
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            .checkbox-wrapper input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--accent-danger); }
            .checkbox-label { font-weight: 600; color: #f87171; font-size: 0.88rem; }
            .btn {
                display: block; width: 100%; background-color: var(--accent); color: white; border: none;
                padding: 12px; font-size: 1rem; font-weight: 600; border-radius: 8px; cursor: pointer;
            }
            .btn:hover { background-color: var(--accent-hover); }
            .btn:disabled { opacity: 0.5; cursor: not-allowed; }
            .btn-secondary {
                width: auto; padding: 8px 14px; background-color: transparent; border: 1px solid var(--border);
                color: var(--text-primary); border-radius: 8px; cursor: pointer; font-size: 0.85rem;
            }
            .btn-secondary:hover { background-color: var(--bg-card-hover); }
            .browse-row { display: flex; gap: 0.5rem; }
            .browse-row input { flex: 1; }
            .browse-list {
                margin-top: 0.6rem; max-height: 180px; overflow-y: auto; border: 1px solid var(--border);
                border-radius: 8px; padding: 0.4rem; background-color: rgba(9, 13, 22, 0.3); display: none;
            }
            .browse-item { padding: 6px 8px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-family: ui-monospace, monospace; }
            .browse-item:hover { background-color: rgba(255,255,255,0.05); }
            .error-banner {
                background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171;
                padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: 0.92rem;
            }
            .results-title { font-size: 1.3rem; font-weight: 600; margin-bottom: 1.25rem; }
            .summary-bar { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
            .summary-item { background-color: rgba(255,255,255,0.02); border: 1px solid var(--border); padding: 12px 20px; border-radius: 10px; flex: 1; min-width: 120px; text-align: center; }
            .summary-num { font-size: 1.4rem; font-weight: 700; margin-bottom: 2px; }
            .summary-num.blue { color: #60a5fa; }
            .summary-num.yellow { color: #fbbf24; }
            .summary-num.gray { color: #9ca3af; }
            .summary-label { font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.05em; }
            .file-result-card { background-color: rgba(255,255,255,0.01); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 1rem; overflow: hidden; }
            .file-result-card.status-modified { border-left: 4px solid #fbbf24; }
            .file-result-card.status-created { border-left: 4px solid #22d3ee; }
            .file-result-card.status-error { border-left: 4px solid #f87171; }
            .file-header { background-color: rgba(255,255,255,0.02); padding: 10px 16px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; }
            .file-name { font-family: ui-monospace, monospace; font-size: 0.88rem; font-weight: 500; word-break: break-all; display: flex; flex-direction: column; gap: 2px; }
            .file-path { font-size: 0.76rem; font-weight: 400; color: var(--text-secondary); }
            .badge { font-size: 0.72rem; padding: 3px 8px; border-radius: 12px; font-weight: 600; white-space: nowrap; }
            .badge-modified { background-color: rgba(251, 191, 36, 0.15); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.3); }
            .badge-created { background-color: rgba(6, 182, 212, 0.15); color: #22d3ee; border: 1px solid rgba(6, 182, 212, 0.3); }
            .badge-success { background-color: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
            .badge-unchanged { background-color: rgba(156, 163, 175, 0.15); color: #9ca3af; border: 1px solid rgba(156, 163, 175, 0.3); }
            .btn-apply-single {
                background-color: var(--accent); color: white; border: none; padding: 4px 10px; border-radius: 4px;
                font-size: 0.72rem; font-weight: 600; cursor: pointer;
            }
            .btn-apply-single:hover { background-color: var(--accent-hover); }
            .btn-apply-single:disabled { background-color: var(--border); color: var(--text-secondary); cursor: not-allowed; opacity: 0.5; }
            .btn-restore {
                background-color: transparent; color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.4); padding: 4px 10px; border-radius: 4px;
                font-size: 0.72rem; font-weight: 600; cursor: pointer; margin-left: 6px;
            }
            .btn-restore:hover { background-color: rgba(251, 191, 36, 0.1); }
            .btn-restore:disabled { color: var(--text-secondary); border-color: var(--border); cursor: not-allowed; opacity: 0.5; }
            .diff-viewer { font-family: ui-monospace, monospace; font-size: 0.82rem; overflow-x: auto; background-color: #060910; padding: 1rem; white-space: pre; line-height: 1.6; }
            .diff-line { display: block; padding: 2px 6px; border-radius: 4px; min-height: 1.4em; }
            .diff-add { background-color: var(--diff-add-bg); color: var(--diff-add-text); }
            .diff-del { background-color: var(--diff-del-bg); color: var(--diff-del-text); }
            .diff-gap { color: #fbbf24; opacity: 0.7; font-style: italic; }
            .diff-same { color: var(--text-secondary); opacity: 0.7; }
            .no-results { text-align: center; padding: 4rem 2rem; color: var(--text-secondary); border: 1px dashed var(--border); border-radius: 12px; }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <div class="header-title">
                    <h1>PHP Batch File Replacer — Remote</h1>
                    <p>Search &amp; replace across files on an FTP or SFTP server</p>
                </div>
                <div class="badge-security" id="accessBadge">Local Only Access Protected</div>
            </header>

            <div class="main-grid">
                <div class="card">
                    <form id="replacerForm">
                        <div id="errorBanner" class="error-banner" style="display:none;"></div>

                        <fieldset>
                            <legend>Connection</legend>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Protocol</label>
                                    <select name="protocol" id="protocol">
                                        <option value="ftp">FTP</option>
                                        <option value="sftp">SFTP</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Port</label>
                                    <input type="number" name="port" id="port" placeholder="21 / 22">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Host</label>
                                <input type="text" name="host" id="host" placeholder="ftp.example.com" required>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="user" id="user" required>
                                </div>
                                <div class="form-group">
                                    <label>Password</label>
                                    <input type="password" name="password" id="password" autocomplete="off">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Private key path (SFTP)</label>
                                    <input type="text" name="key" id="key" placeholder="/home/you/.ssh/id_rsa">
                                </div>
                                <div class="form-group">
                                    <label>Key passphrase</label>
                                    <input type="password" name="passphrase" id="passphrase" autocomplete="off">
                                </div>
                            </div>
                            <div class="checkbox-row">
                                <input type="checkbox" name="ssl" id="ssl" value="1">
                                <label for="ssl">Use FTPS (explicit TLS)</label>
                            </div>
                            <div class="checkbox-row">
                                <input type="checkbox" name="active" id="active" value="1">
                                <label for="active">Use active mode (FTP, instead of passive)</label>
                            </div>
                            <div class="help-text">The key path is read from the machine running this script, not uploaded from your browser.</div>
                        </fieldset>

                        <fieldset>
                            <legend>Target</legend>
                            <div class="form-group">
                                <label>Sites Root</label>
                                <input type="text" id="sites-root" placeholder="/home/Admin/web" oninput="updateDirField()">
                                <div class="help-text">Remote folder containing multiple website directories, e.g. /home/Admin/web.</div>

                                <div style="margin-top:0.75rem;">
                                    <label for="site-subpath">Website Subpath</label>
                                    <input type="text" id="site-subpath" placeholder="public_html" oninput="updateDirField()">
                                    <div class="help-text">Appended to the site root when building the target directory (e.g. "public_html"). Leave blank or "." to target the site root itself.</div>
                                </div>
                            </div>
                            <input type="hidden" name="dir" id="dir" value="/">
                            <div class="form-group">
                                <label>Template (comma-separated glob)</label>
                                <input type="text" name="template" id="template" placeholder="*.html,*.php">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Max Depth</label>
                                    <input type="number" name="max-depth" id="max-depth" value="-1">
                                </div>
                                <div class="form-group">
                                    <label>Depth Mode</label>
                                    <select name="depth-mode" id="depth-mode">
                                        <option value="up_to">Up to depth</option>
                                        <option value="equal">Exactly at depth</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Exclude Dirs</label>
                                <input type="text" name="exclude-dirs" id="exclude-dirs" value="wp-admin,wp-includes,node_modules,vendor,.git">
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend>Replacement</legend>
                            <div class="form-group">
                                <label>Mode</label>
                                <select name="mode" id="mode">
                                    <option value="">-- select --</option>
                                    <option value="string">string</option>
                                    <option value="regex">regex</option>
                                    <option value="prepend">prepend</option>
                                    <option value="append">append</option>
                                    <option value="htaccess-bot-blocker">htaccess-bot-blocker</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Find</label>
                                <textarea name="find" id="find"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Replace</label>
                                <textarea name="replace" id="replace"></textarea>
                            </div>
                        </fieldset>

                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="run" id="run" value="1" disabled>
                            <label for="run" class="checkbox-label">Apply changes (Live Run) — otherwise this is a dry-run preview</label>
                        </div>
                        <div class="help-text" id="runHelp">Run a dry-run preview first to unlock Live Run.</div>

                        <button type="submit" class="btn" id="scanBtn">Scan &amp; Preview</button>
                    </form>
                </div>

                <div class="card" id="resultsCard">
                    <div class="no-results" id="noResults">
                        <p>Fill in connection &amp; replacement details, then click "Scan &amp; Preview".</p>
                    </div>
                    <div id="resultsContent" style="display:none;"></div>
                </div>
            </div>
        </div>

        <script>
        const form = document.getElementById('replacerForm');
        const noResults = document.getElementById('noResults');
        const resultsContent = document.getElementById('resultsContent');
        const errorBanner = document.getElementById('errorBanner');
        const scanBtn = document.getElementById('scanBtn');
        const runCheckbox = document.getElementById('run');
        const runHelp = document.getElementById('runHelp');

        function lockLiveRun(message) {
            runCheckbox.checked = false;
            runCheckbox.disabled = true;
            runHelp.textContent = message || 'Run a dry-run preview first to unlock Live Run.';
        }

        function unlockLiveRun() {
            runCheckbox.disabled = false;
            runHelp.textContent = 'Preview looks good — Live Run is now available.';
        }

        form.addEventListener('input', (e) => {
            if (e.target !== runCheckbox && !runCheckbox.disabled) lockLiveRun();
        });
        form.addEventListener('change', (e) => {
            if (e.target !== runCheckbox && !runCheckbox.disabled) lockLiveRun();
        });

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function updateDirField() {
            const root = document.getElementById('sites-root').value.trim().replace(/\/+$/, '');
            if (root === '') return;
            const subpath = document.getElementById('site-subpath').value.trim().replace(/^\/+|\/+$/g, '');
            document.getElementById('dir').value = (subpath === '' || subpath === '.') ? root + '/*' : root + '/*/' + subpath;
        }

        function connectionParams() {
            return {
                protocol: document.getElementById('protocol').value,
                host: document.getElementById('host').value,
                port: document.getElementById('port').value,
                user: document.getElementById('user').value,
                password: document.getElementById('password').value,
                key: document.getElementById('key').value,
                passphrase: document.getElementById('passphrase').value,
                ssl: document.getElementById('ssl').checked ? '1' : '',
                active: document.getElementById('active').checked ? '1' : '',
            };
        }

        function replaceParams() {
            return {
                dir: document.getElementById('dir').value,
                template: document.getElementById('template').value,
                mode: document.getElementById('mode').value,
                find: document.getElementById('find').value,
                replace: document.getElementById('replace').value,
                'max-depth': document.getElementById('max-depth').value,
                'depth-mode': document.getElementById('depth-mode').value,
                'exclude-dirs': document.getElementById('exclude-dirs').value,
            };
        }

        function toFormBody(obj) {
            const p = new URLSearchParams();
            for (const k in obj) p.append(k, obj[k]);
            return p;
        }

        function showError(msg) {
            errorBanner.textContent = msg;
            errorBanner.style.display = 'block';
        }
        function clearError() {
            errorBanner.style.display = 'none';
        }

        function renderDiff(diff, context = 3) {
            const total = diff.length;
            const toPrint = new Array(total).fill(false);
            for (let i = 0; i < total; i++) {
                if (diff[i].type !== 'same') {
                    for (let c = -context; c <= context; c++) {
                        const idx = i + c;
                        if (idx >= 0 && idx < total) toPrint[idx] = true;
                    }
                }
            }
            let html = '';
            let inGap = false;
            for (let i = 0; i < total; i++) {
                if (toPrint[i]) {
                    if (inGap) { html += '<span class="diff-line diff-gap">@@ ... @@</span>'; inGap = false; }
                    const item = diff[i];
                    const cls = item.type === 'add' ? 'diff-add' : (item.type === 'del' ? 'diff-del' : 'diff-same');
                    const prefix = item.type === 'add' ? '+ ' : (item.type === 'del' ? '- ' : '  ');
                    html += `<span class="diff-line ${cls}">${escapeHtml(prefix + item.line)}</span>`;
                } else {
                    inGap = true;
                }
            }
            return html;
        }

        function addRestoreButton(div, f, connParams, replParams, backupPath, runId) {
            const holder = div.querySelector('.file-header > span:last-child');
            holder.insertAdjacentHTML('beforeend', `<button class="btn-restore" data-run-id="${escapeHtml(runId)}">Restore Backup</button>`);
            const restoreBtn = holder.querySelector('.btn-restore');
            restoreBtn.addEventListener('click', async () => {
                if (!confirm('Restore this file from its backup? The remote file\'s current contents will be overwritten (a fresh safety copy of the current state is taken first).')) {
                    return;
                }
                restoreBtn.disabled = true;
                restoreBtn.textContent = 'Restoring...';
                try {
                    const body = toFormBody(Object.assign({ action: 'restore_backup', file: f.absolute, run_id: runId, dir: replParams.dir }, connParams));
                    const res = await fetch('', { method: 'POST', body });
                    const json = await res.json();
                    if (json.success) {
                        restoreBtn.disabled = false;
                        restoreBtn.textContent = 'Restore Backup';
                        holder.insertAdjacentHTML('beforeend', '<span class="badge badge-success">RESTORED</span>');
                        if (json.pre_restore_backup_path) {
                            div.querySelector('.file-name').insertAdjacentHTML('beforeend', `<div class="file-path" style="margin-top:4px">Pre-restore snapshot: ${escapeHtml(json.pre_restore_backup_path)}</div>`);
                        }
                    } else {
                        restoreBtn.disabled = false;
                        restoreBtn.textContent = 'Restore Backup';
                        showError(json.error || 'Failed to restore backup.');
                    }
                } catch (e) {
                    restoreBtn.disabled = false;
                    restoreBtn.textContent = 'Restore Backup';
                    showError('Network error restoring backup: ' + e.message);
                }
            });
        }

        function fileCard(f, connParams, replParams, runId) {
            const div = document.createElement('div');
            div.className = 'file-result-card status-' + f.status;

            if (f.status === 'error') {
                div.innerHTML = `<div class="file-header"><span class="file-name">${escapeHtml(f.path)}<span class="file-path">${escapeHtml(f.absolute)}</span></span><span class="badge" style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)">ERROR</span></div>
                    <div class="diff-viewer">${escapeHtml(f.message)}</div>`;
                return div;
            }
            if (f.status === 'unchanged') {
                div.innerHTML = `<div class="file-header"><span class="file-name">${escapeHtml(f.path)}<span class="file-path">${escapeHtml(f.absolute)}</span></span><span class="badge badge-unchanged">UNCHANGED</span></div>`;
                return div;
            }

            const badgeClass = f.status === 'created' ? 'badge-created' : 'badge-modified';
            const wasRun = replParams.run === '1';
            let statusBadge = `<span class="badge ${badgeClass}">${f.status.toUpperCase()}</span>`;
            if (wasRun) {
                if (f.backup_error) {
                    statusBadge += '<span class="badge" style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)">BACKUP FAILED</span>';
                } else {
                    statusBadge += f.write_success
                        ? '<span class="badge badge-success">WRITTEN</span>'
                        : '<span class="badge" style="background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)">WRITE FAILED</span>';
                }
            }

            const applyBtn = wasRun ? '' : `<button class="btn-apply-single" data-file="${escapeHtml(f.absolute)}">Apply Change</button>`;
            const backupNote = (wasRun && f.backup_path) ? `<div class="file-path" style="margin-top:4px">Backup: ${escapeHtml(f.backup_path)}</div>` : '';

            div.innerHTML = `<div class="file-header">
                    <span class="file-name">${escapeHtml(f.path)}<span class="file-path">${escapeHtml(f.absolute)}</span>${backupNote}</span>
                    <span>${statusBadge}${applyBtn}</span>
                </div>
                <div class="diff-viewer">${renderDiff(f.diff)}</div>`;

            if (wasRun && f.write_success && f.backup_path && runId) {
                addRestoreButton(div, f, connParams, replParams, f.backup_path, runId);
            }

            const btn = div.querySelector('.btn-apply-single');
            if (btn) {
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    btn.textContent = 'Applying...';
                    try {
                        const body = toFormBody(Object.assign({ action: 'apply_single', file: f.absolute }, connParams, replParams));
                        const res = await fetch('', { method: 'POST', body });
                        const json = await res.json();
                        if (json.success) {
                            btn.remove();
                            div.querySelector('.file-header > span:last-child').insertAdjacentHTML('beforeend', '<span class="badge badge-success">WRITTEN</span>');
                            if (json.backup_path) {
                                div.querySelector('.file-name').insertAdjacentHTML('beforeend', `<div class="file-path" style="margin-top:4px">Backup: ${escapeHtml(json.backup_path)}</div>`);
                            }
                            if (json.backup_path && json.backup_run_id) {
                                addRestoreButton(div, f, connParams, replParams, json.backup_path, json.backup_run_id);
                            }
                        } else {
                            btn.disabled = false;
                            btn.textContent = 'Apply Change';
                            showError(json.error || 'Failed to apply change.');
                        }
                    } catch (e) {
                        btn.disabled = false;
                        btn.textContent = 'Apply Change';
                        showError('Network error applying change: ' + e.message);
                    }
                });
            }

            return div;
        }

        function renderResults(results, replParams, connParams) {
            resultsContent.innerHTML = '';
            noResults.style.display = 'none';
            resultsContent.style.display = 'block';

            const title = document.createElement('div');
            title.className = 'results-title';
            title.textContent = 'Scan Results';
            resultsContent.appendChild(title);

            const summary = document.createElement('div');
            summary.className = 'summary-bar';
            summary.innerHTML = `
                <div class="summary-item"><div class="summary-num blue">${results.total_files}</div><div class="summary-label">Total Files</div></div>
                <div class="summary-item"><div class="summary-num yellow">${results.modified_count}</div><div class="summary-label">Modified/Created</div></div>
                <div class="summary-item"><div class="summary-num gray">${results.skipped_count}</div><div class="summary-label">Unchanged</div></div>`;
            resultsContent.appendChild(summary);

            if (results.backup_dir) {
                const note = document.createElement('div');
                note.className = 'help-text';
                note.style.marginBottom = '1rem';
                note.textContent = 'Originals backed up to: ' + results.backup_dir;
                resultsContent.appendChild(note);
            }

            const relevant = results.files.filter(f => f.status !== 'unchanged');
            if (relevant.length === 0) {
                const p = document.createElement('div');
                p.className = 'no-results';
                p.textContent = 'No files needed changes.';
                resultsContent.appendChild(p);
            } else {
                relevant.forEach(f => resultsContent.appendChild(fileCard(f, connParams, replParams, results.backup_run_id)));
            }
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearError();
            const isLiveRun = runCheckbox.checked && !runCheckbox.disabled;
            if (isLiveRun) {
                if (!confirm('Live Run is enabled — this will apply changes directly to files on the remote server. Are you sure you want to proceed?')) {
                    return;
                }
            }
            scanBtn.disabled = true;
            scanBtn.textContent = 'Scanning...';
            try {
                const connParams = connectionParams();
                const replParams = replaceParams();
                replParams.run = isLiveRun ? '1' : '';
                const body = toFormBody(Object.assign({ action: 'scan' }, connParams, replParams));
                const res = await fetch('', { method: 'POST', body });
                const json = await res.json();
                if (json.success) {
                    renderResults(json.results, replParams, connParams);
                    // Every live run must be preceded by a fresh preview of the exact same parameters.
                    if (isLiveRun) {
                        lockLiveRun('Live Run applied — run a new preview to unlock it again.');
                    } else {
                        unlockLiveRun();
                    }
                } else {
                    showError(json.error || 'Scan failed.');
                }
            } catch (e) {
                showError('Network error: ' + e.message);
            } finally {
                scanBtn.disabled = false;
                scanBtn.textContent = 'Scan & Preview';
            }
        });

        </script>
    </body>
    </html>
    <?php
}
