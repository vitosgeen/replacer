#!/usr/bin/env php
<?php
/**
 * PHP Batch File Replacer
 * Standard CLI and Web Interface combined in a single file.
 * Developed by Antigravity AI
 */

// SECURITY CONFIGURATION
// For safety, web interface access is restricted to localhost (127.0.0.1 or ::1) by default.
// Change this to true ONLY if you understand the risks of allowing remote users to edit server files.
define('ALLOW_REMOTE_ACCESS', false);

// Helper function to colorize CLI output
function color($text, $color_code) {
    if (function_exists('posix_isatty') && !posix_isatty(STDOUT)) {
        return $text;
    }
    return "\033[{$color_code}m{$text}\033[0m";
}

// Check if running in CLI mode
$is_cli = (php_sapi_name() === 'cli' || defined('STDIN'));

// Detect request IP for Web Mode security
function is_local_request() {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remote_ip, ['127.0.0.1', '::1', 'localhost']);
}

// ---------------------------------------------------------
// CLI - Print Help Instructions
// ---------------------------------------------------------
function print_help() {
    echo color("=== PHP Batch File Replacer ===", "1;36") . "\n";
    echo "A safe CLI tool to search and replace patterns in files with colored previews.\n\n";
    echo color("Usage:", "1;33") . "\n";
    echo "  php replacer.php --dir=<path> --template=<glob> --mode=<mode> [options]\n\n";
    echo color("Arguments:", "1;33") . "\n";
    echo "  --dir=<paths>           Target directories, comma-separated (default: current directory)\n";
    echo "  --template=<glob>       File pattern templates, comma-separated (e.g. *.html,*.php)\n";
    echo "  --mode=<mode>           Replacement mode: string | regex | prepend | append | htaccess-bot-blocker\n";
    echo "  --find=<pattern>        Search string or regex pattern (required for string/regex modes)\n";
    echo "  --replace=<string>      Replacement text or value to add (required for string/regex/prepend/append modes)\n";
    echo "  --max-depth=<depth>     Maximum directory search depth: 0 = only target dir, 1 = target + 1 level, etc. (default: infinite)
  --depth-mode=<mode>     Depth search mode: up_to (up to max-depth, default) or equal (exactly at max-depth)
  --exclude-dirs=<list>   Comma-separated list of folders to exclude (default: wp-admin,wp-includes,node_modules,vendor,.git)
  --run                   Execute changes (default is dry-run / simulation)\n";
    echo "  --help, -h              Display this help message\n\n";
    echo color("Examples:", "1;33") . "\n";
    echo "  1. Dry-run htaccess bot blocker:\n";
    echo "     php replacer.php --dir=./test_env --template=.htaccess --mode=htaccess-bot-blocker\n\n";
    echo "  2. Run (write) htaccess bot blocker:\n";
    echo "     php replacer.php --dir=./test_env --template=.htaccess --mode=htaccess-bot-blocker --run\n\n";
}

// ---------------------------------------------------------
// CORE FUNCTIONS
// ---------------------------------------------------------

// Match glob pattern template
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

// Expand directory lists and resolve wildcard/glob directories
function get_expanded_target_dirs($dir_input) {
    $raw_dirs = explode(',', $dir_input);
    $resolved = [];
    foreach ($raw_dirs as $raw_path) {
        $raw_path = trim($raw_path);
        if (empty($raw_path)) continue;
        
        if (strpos($raw_path, 'regex:') !== false) {
            $base_dir = '.';
            $pattern = '';
            if (strpos($raw_path, '|regex:') !== false) {
                list($base_dir, $pattern) = explode('|regex:', $raw_path, 2);
            } else {
                list($unused, $pattern) = explode('regex:', $raw_path, 2);
            }
            $base_dir = trim($base_dir);
            $pattern = trim($pattern);
            
            $real_base = @realpath($base_dir);
            if ($real_base !== false && @is_dir($real_base)) {
                $matched = match_dirs_by_regex($real_base, $pattern);
                foreach ($matched as $m) {
                    $resolved[] = $m;
                }
            }
        } elseif (strpos($raw_path, '*') !== false || strpos($raw_path, '?') !== false) {
            $expanded = @glob($raw_path, GLOB_ONLYDIR);
            if ($expanded !== false && !empty($expanded)) {
                foreach ($expanded as $dir) {
                    $real = @realpath($dir);
                    if ($real !== false) {
                        $resolved[] = $real;
                    }
                }
            }
        } else {
            $real = @realpath($raw_path);
            if ($real !== false) {
                $resolved[] = $real;
            }
        }
    }
    return array_unique($resolved);
}

// Combine a site's base directory with an optional subpath (e.g. "public_html") to get its target path
function combine_site_path($base, $subpath) {
    $subpath = trim(trim($subpath), '/');
    if ($subpath === '' || $subpath === '.') {
        return $base;
    }
    return $base . '/' . $subpath;
}

// List immediate subdirectories of a base path (used for the "pick a website" UI)
function list_site_directories($base_path) {
    $real_base = @realpath($base_path);
    if ($real_base === false || !@is_dir($real_base)) {
        return null;
    }
    $dh = @opendir($real_base);
    if (!$dh) {
        return null;
    }
    $dirs = [];
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $full_path = $real_base . '/' . $file;
        if (@is_dir($full_path)) {
            $dirs[] = [
                'name' => $file,
                'path' => $full_path
            ];
        }
    }
    closedir($dh);
    usort($dirs, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $dirs;
}

// Find directories matching regex pattern recursively
function match_dirs_by_regex($base_dir, $pattern) {
    if (empty($pattern)) {
        return [];
    }
    // Check if regex has delimiters (e.g. ^/ ... /i or / ... /)
    if (preg_match('/^([~\/#]).*\1[imsuxADSUXJu]*$/', $pattern)) {
        // Delimiters exist
    } else {
        // Auto-wrap in standard ~...~i delimiter to support forward slashes in path patterns without escape errors
        $pattern = '~' . $pattern . '~i';
    }
    return match_dirs_by_regex_recursive($base_dir, $pattern, $base_dir);
}

function match_dirs_by_regex_recursive($dir, $pattern, $base_dir, $current_depth = 0) {
    if ($current_depth > 5) {
        return [];
    }
    $dh = @opendir($dir);
    if (!$dh) {
        return [];
    }
    $matched = [];
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $full_path = $dir . '/' . $file;
        if (@is_dir($full_path)) {
            $real_full = @realpath($full_path);
            $real_base = @realpath($base_dir);
            if ($real_full !== false && $real_base !== false) {
                $rel = substr($real_full, strlen($real_base));
                $rel = ltrim(str_replace('\\', '/', $rel), '/');
                if (preg_match($pattern, $rel)) {
                    $matched[] = $real_full;
                }
            }
            $sub = match_dirs_by_regex_recursive($full_path, $pattern, $base_dir, $current_depth + 1);
            $matched = array_merge($matched, $sub);
        }
    }
    closedir($dh);
    return $matched;
}

// Check if a path is excluded based on relative path segment match
function is_path_excluded($path, $base_dir, $exclude_dirs) {
    if (empty($exclude_dirs)) {
        return false;
    }
    $real_path = @realpath($path) ?: $path;
    $real_base = @realpath($base_dir) ?: $base_dir;
    
    $rel = substr($real_path, strlen($real_base));
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') {
        return false;
    }
    
    $parts = explode('/', $rel);
    foreach ($exclude_dirs as $exclude) {
        $exclude = trim(str_replace('\\', '/', $exclude), '/');
        if (empty($exclude)) continue;
        
        if ($exclude === $rel || strpos($rel, $exclude . '/') === 0 || in_array($exclude, $parts)) {
            return true;
        }
    }
    return false;
}

// Helper to check recursively if a directory or its subdirectories contain any web files (like .php, .html, etc.)
function has_web_files($dir, $exclude_dirs = []) {
    $dh = @opendir($dir);
    if (!$dh) {
        return false;
    }
    $web_extensions = ['php', 'html', 'htm', 'shtml', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py'];
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $full_path = $dir . '/' . $file;
        if (@is_dir($full_path)) {
            if (!is_path_excluded($full_path, $dir, $exclude_dirs)) {
                if (has_web_files($full_path, $exclude_dirs)) {
                    closedir($dh);
                    return true;
                }
            }
        } else {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $web_extensions)) {
                closedir($dh);
                return true;
            }
        }
    }
    closedir($dh);
    return false;
}

// Find files recursively (helper)
function find_files_recursive($dir, $templates, $max_depth, $depth_mode, $exclude_dirs, $base_dir, $current_depth = 0) {
    if (!@is_dir($dir)) {
        return [];
    }
    if (is_path_excluded($dir, $base_dir, $exclude_dirs)) {
        return [];
    }
    
    $files = [];
    $dh = @opendir($dir);
    if (!$dh) {
        return [];
    }
    
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $full_path = $dir . '/' . $file;
        if (@is_dir($full_path)) {
            if ($max_depth === -1 || $current_depth < $max_depth) {
                $files = array_merge($files, find_files_recursive($full_path, $templates, $max_depth, $depth_mode, $exclude_dirs, $base_dir, $current_depth + 1));
            }
        } else {
            if (matches_template($file, $templates)) {
                if ($max_depth !== -1 && $max_depth >= 0 && $depth_mode === 'equal') {
                    if ($current_depth !== $max_depth) {
                        continue;
                    }
                }
                $files[] = @realpath($full_path) ?: $full_path;
            }
        }
    }
    closedir($dh);
    return $files;
}

// Find files recursively with optional maximum depth
function find_files($dir, $templates, $max_depth = -1, $depth_mode = 'up_to', $exclude_dirs = []) {
    return find_files_recursive($dir, $templates, $max_depth, $depth_mode, $exclude_dirs, $dir, 0);
}

// Find directories recursively (helper)
function find_scanned_dirs_recursive($dir, $max_depth, $depth_mode, $exclude_dirs, $base_dir, $current_depth = 0) {
    if (!@is_dir($dir)) {
        return [];
    }
    if (is_path_excluded($dir, $base_dir, $exclude_dirs)) {
        return [];
    }
    
    $dirs = [];
    if ($max_depth === -1 || $depth_mode === 'up_to') {
        $dirs[] = @realpath($dir) ?: $dir;
    } else if ($depth_mode === 'equal' && $current_depth === $max_depth) {
        $dirs[] = @realpath($dir) ?: $dir;
    }
    
    if ($max_depth !== -1 && $current_depth >= $max_depth) {
        return $dirs;
    }
    
    $dh = @opendir($dir);
    if (!$dh) {
        return $dirs;
    }
    
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $full_path = $dir . '/' . $file;
        if (@is_dir($full_path)) {
            $dirs = array_merge($dirs, find_scanned_dirs_recursive($full_path, $max_depth, $depth_mode, $exclude_dirs, $base_dir, $current_depth + 1));
        }
    }
    closedir($dh);
    return $dirs;
}

// Find directories recursively with optional maximum depth
function find_scanned_dirs($dir, $max_depth = -1, $depth_mode = 'up_to', $exclude_dirs = []) {
    return find_scanned_dirs_recursive($dir, $max_depth, $depth_mode, $exclude_dirs, $dir, 0);
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

// Perform replacement based on mode
function apply_replacement($content, $options) {
    // Detect line endings of the file content
    $target_endings = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    
    // Normalize find and replace inputs to match the file's line endings
    $find = str_replace(["\r\n", "\r"], "\n", $options['find']);
    $find = str_replace("\n", $target_endings, $find);
    
    $replace = str_replace(["\r\n", "\r"], "\n", $options['replace']);
    $replace = str_replace("\n", $target_endings, $replace);
    
    switch ($options['mode']) {
        case 'string':
            return str_replace($find, $replace, $content);
            
        case 'regex':
            // Suppress warnings for invalid regex formats
            $result = @preg_replace($find, $replace, $content);
            return ($result === null) ? $content : $result;
            
        case 'prepend':
            if (strpos($content, $replace) !== false) {
                return $content; // Avoid duplicates
            }
            return $replace . $target_endings . $content;
            
        case 'append':
            if (strpos($content, $replace) !== false) {
                return $content; // Avoid duplicates
            }
            return $content . $target_endings . $replace;
            
        case 'htaccess-bot-blocker':
            $bot_block = trim($replace);
            // Fallback to default if empty or if it doesn't contain a RewriteCond or RewriteRule directive
            if (empty($bot_block) || (!preg_match('/RewriteCond/i', $bot_block) && !preg_match('/RewriteRule/i', $bot_block))) {
                $bot_block = "RewriteCond %{HTTP_USER_AGENT} (AhrefsBot|SemrushBot|MJ12bot|DotBot|rogerbot|DataForSeoBot|BLEXBot|serpstatbot|Barkrowler|MegaIndex|GPTBot|ClaudeBot|CCBot|PerplexityBot|Bytespider|Amazonbot|meta-externalagent|Google-Extended|GoogleOther|anthropic-ai|Claude-Web) [NC]\nRewriteRule .* - [F,L]";
                $bot_block = str_replace("\n", $target_endings, $bot_block);
            }
            
            $bot_block_has_engine = preg_match('/RewriteEngine\s+On/i', $bot_block);
            $content_has_engine = preg_match('/RewriteEngine\s+On/i', $content);

            // Avoid duplicate checks
            if (!empty($options['replace']) && (preg_match('/RewriteCond/i', $options['replace']) || preg_match('/RewriteRule/i', $options['replace']))) {
                $normalized_content = preg_replace('/\s+/', '', $content);
                $normalized_block = preg_replace('/\s+/', '', $bot_block);
                if (strpos($normalized_content, $normalized_block) !== false) {
                    return $content; // Already present, skip
                }
            } else {
                if (stripos($content, 'AhrefsBot') !== false || stripos($content, 'SemrushBot') !== false) {
                    return $content; // Already present, skip
                }
            }
            
            if ($content_has_engine) {
                // If content has RewriteEngine On, we insert the block right after it.
                // If the block itself has RewriteEngine On, we strip it from the block first to prevent duplicates.
                if ($bot_block_has_engine) {
                    $cleaned_block = preg_replace('/RewriteEngine\s+On\s*/i', '', $bot_block);
                    $cleaned_block = trim($cleaned_block);
                } else {
                    $cleaned_block = $bot_block;
                }
                return preg_replace('/(RewriteEngine\s+On)/i', "$1" . $target_endings . $cleaned_block, $content, 1);
            } else {
                // If content does not have RewriteEngine On:
                if ($bot_block_has_engine) {
                    // Block already has it, just prepend the block
                    return $bot_block . $target_endings . $target_endings . $content;
                } else {
                    // Prepends RewriteEngine On + block
                    return "RewriteEngine On" . $target_endings . $bot_block . $target_endings . $target_endings . $content;
                }
            }
            
        default:
            return $content;
    }
}

// Gather files and execute replacements, returns data for diffs/results
function execute_replacer($options) {
    $target_dirs = get_expanded_target_dirs($options['dir']);
    if (empty($target_dirs)) {
        return ['error' => "No valid target directories found matching input '{$options['dir']}'."];
    }
    
    $files = [];
    $resolved_dirs = [];

    $exclude_input = isset($options['exclude-dirs']) ? $options['exclude-dirs'] : '';
    $exclude_dirs = [];
    if (!empty($exclude_input)) {
        $exclude_dirs = explode(',', $exclude_input);
    }
    
    foreach ($target_dirs as $real) {
        $resolved_dirs[] = $real;
        
        $found = find_files($real, $options['template'], $options['max-depth'], isset($options['depth-mode']) ? $options['depth-mode'] : 'up_to', $exclude_dirs);
        foreach ($found as $f) {
            $files[] = [
                'absolute' => $f,
                'base_dir' => $real
            ];
        }
        
        if ($options['mode'] === 'htaccess-bot-blocker' && matches_template('.htaccess', $options['template'])) {
            $scanned_dirs = find_scanned_dirs($real, $options['max-depth'], isset($options['depth-mode']) ? $options['depth-mode'] : 'up_to', $exclude_dirs);
            foreach ($scanned_dirs as $d) {
                $htaccess_file = $d . '/.htaccess';
                if (!@file_exists($htaccess_file)) {
                    if (!has_web_files($d, $exclude_dirs)) {
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
                            'base_dir' => $real,
                            'is_new' => true
                        ];
                    }
                }
            }
        }
    }
    
    $results = [
        'target_dirs' => $resolved_dirs,
        'total_files' => count($files),
        'modified_count' => 0,
        'skipped_count' => 0,
        'files' => []
    ];
    
    $is_multi_dir = count($resolved_dirs) > 1;
    
    foreach ($files as $fileData) {
        $file = $fileData['absolute'];
        $base_dir = $fileData['base_dir'];
        
        $relative_path = str_replace($base_dir . '/', '', $file);
        $display_path = $is_multi_dir ? basename($base_dir) . '/' . $relative_path : $relative_path;
        
        $is_new = !empty($fileData['is_new']);
        
        if ($is_new) {
            $content = '';
        } else {
            $content = @file_get_contents($file);
            if ($content === false) {
                $results['files'][] = [
                    'path' => $display_path,
                    'absolute' => $file,
                    'status' => 'error',
                    'message' => 'Could not read file'
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
                'diff' => []
            ];
            continue;
        }
        
        $results['modified_count']++;
        
        // Generate Diff
        $old_lines = explode("\n", rtrim($content));
        $new_lines = explode("\n", rtrim($new_content));
        $diff = get_diff($old_lines, $new_lines);
        
        $write_success = true;
        if ($options['run']) {
            if (@file_put_contents($file, $new_content) === false) {
                $write_success = false;
            }
        }
        
        $results['files'][] = [
            'path' => $display_path,
            'absolute' => $file,
            'status' => $is_new ? 'created' : 'modified',
            'write_success' => $write_success,
            'diff' => $diff
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
        'dir' => '.',
        'template' => '',
        'mode' => '',
        'find' => '',
        'replace' => '',
        'run' => false,
        'max-depth' => -1,
        'depth-mode' => 'up_to',
        'exclude-dirs' => 'wp-admin,wp-includes,node_modules,vendor,.git',
        'help' => false
    ];

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--run') {
            $options['run'] = true;
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

    if (empty($options['mode'])) {
        echo color("Error: --mode is required.\n\n", "1;31");
        print_help();
        exit(1);
    }

    if (!in_array($options['mode'], ['string', 'regex', 'prepend', 'append', 'htaccess-bot-blocker'])) {
        echo color("Error: Invalid mode '{$options['mode']}'. Allowed modes: string, regex, prepend, append, htaccess-bot-blocker.\n", "1;31");
        exit(1);
    }

    if (!in_array($options['depth-mode'], ['up_to', 'equal'])) {
        echo color("Error: Invalid depth-mode '{$options['depth-mode']}'. Allowed modes: up_to, equal.\n", "1;31");
        exit(1);
    }

    if (in_array($options['mode'], ['string', 'regex']) && empty($options['find'])) {
        echo color("Error: --find is required for mode '{$options['mode']}'.\n", "1;31");
        exit(1);
    }

    $results = execute_replacer($options);
    
    if (isset($results['error'])) {
        echo color("Error: " . $results['error'] . "\n", "1;31");
        exit(1);
    }

    echo color("Scanning directories: " . implode(', ', $results['target_dirs']), "1;34") . "\n";
    echo "Template filter: " . ($options['template'] ?: "* (all files)") . "\n";
    echo "Search depth: " . ($options['max-depth'] === -1 ? "Infinite" : $options['max-depth']) . " (" . ($options['depth-mode'] === 'equal' ? "Exactly at depth" : "Up to depth") . ")\n";
    echo "Excluded folders: " . ($options['exclude-dirs'] ?: "None") . "\n";
    echo "Replacement Mode: " . $options['mode'] . "\n";
    echo "Execution Mode: " . ($options['run'] ? color("LIVE RUN (Writing to files)", "1;31") : color("DRY RUN (Simulated preview)", "1;32")) . "\n\n";

    echo "Found " . color($results['total_files'], "1;33") . " matching file(s).\n\n";

    // CLI Diff printer function
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
                if ($fileData['write_success']) {
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
    
    // Security restriction check
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
                <p>For security, the web interface of the Batch File Replacer script is restricted to <strong>localhost</strong> loopback requests by default.</p>
                <p>To enable remote access, edit <code>replacer.php</code> and set <code>ALLOW_REMOTE_ACCESS</code> to <code>true</code>.</p>
            </div>
        </body>
        </html>
        <?php
        exit(0);
    }
    
    // Handle AJAX list_sites request (browse subdirectories of a "sites root" for quick selection)
    if (isset($_POST['action']) && $_POST['action'] === 'list_sites') {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');

        $sites_root = isset($_POST['sites-root']) ? trim($_POST['sites-root']) : '';
        if (empty($sites_root)) {
            echo json_encode(['success' => false, 'error' => 'No sites root path specified.']);
            exit(0);
        }

        $dirs = list_site_directories($sites_root);
        if ($dirs === null) {
            echo json_encode(['success' => false, 'error' => 'Path not found or not a directory.']);
            exit(0);
        }

        echo json_encode(['success' => true, 'root' => realpath($sites_root), 'dirs' => $dirs]);
        exit(0);
    }

    // Handle AJAX apply_single request
    if (isset($_POST['action']) && $_POST['action'] === 'apply_single') {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        
        $target_file = isset($_POST['file']) ? $_POST['file'] : '';
        if (empty($target_file)) {
            echo json_encode(['success' => false, 'error' => 'No target file specified.']);
            exit(0);
        }
        
        $options = [
            'dir' => isset($_POST['dir']) ? $_POST['dir'] : '.',
            'template' => isset($_POST['template']) ? $_POST['template'] : '',
            'mode' => isset($_POST['mode']) ? $_POST['mode'] : '',
            'find' => isset($_POST['find']) ? $_POST['find'] : '',
            'replace' => isset($_POST['replace']) ? $_POST['replace'] : '',
            'run' => true, // force write
            'max-depth' => isset($_POST['max-depth']) ? (int)$_POST['max-depth'] : -1,
            'depth-mode' => isset($_POST['depth-mode']) ? $_POST['depth-mode'] : 'up_to',
            'exclude-dirs' => isset($_POST['exclude-dirs']) ? $_POST['exclude-dirs'] : 'wp-admin,wp-includes,node_modules,vendor,.git',
        ];
        
        $target_dirs = get_expanded_target_dirs($options['dir']);
        $is_inside = false;
        
        $real_target_file = @realpath($target_file);
        if ($real_target_file === false) {
            $parent_dir = dirname($target_file);
            $real_parent = @realpath($parent_dir);
            if ($real_parent !== false) {
                foreach ($target_dirs as $real_td) {
                    if (strpos($real_parent, $real_td) === 0) {
                        $is_inside = true;
                        $real_target_file = $real_parent . '/' . basename($target_file);
                        break;
                    }
                }
            }
        } else {
            foreach ($target_dirs as $real_td) {
                if (strpos($real_target_file, $real_td) === 0) {
                    $is_inside = true;
                    break;
                }
            }
        }
        
        if (!$is_inside) {
            echo json_encode(['success' => false, 'error' => 'Access Denied: Target file is outside targeted directories.']);
            exit(0);
        }
        
        $is_new = !@file_exists($real_target_file);
        if ($is_new) {
            $content = '';
            if ($options['mode'] !== 'htaccess-bot-blocker' || basename($real_target_file) !== '.htaccess') {
                echo json_encode(['success' => false, 'error' => 'Creation of new files is only allowed for .htaccess in bot blocker mode.']);
                exit(0);
            }
        } else {
            $content = @file_get_contents($real_target_file);
            if ($content === false) {
                echo json_encode(['success' => false, 'error' => 'Could not read target file.']);
                exit(0);
            }
        }
        
        $new_content = apply_replacement($content, $options);
        
        if (@file_put_contents($real_target_file, $new_content) === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to write to file. Check permissions.']);
            exit(0);
        }
        
        echo json_encode(['success' => true]);
        exit(0);
    }
    
    // Process input
    $submitted = isset($_REQUEST['action']);
    $options = [
        'dir' => isset($_REQUEST['dir']) ? $_REQUEST['dir'] : '.',
        'template' => isset($_REQUEST['template']) ? $_REQUEST['template'] : '',
        'mode' => isset($_REQUEST['mode']) ? $_REQUEST['mode'] : '',
        'find' => isset($_REQUEST['find']) ? $_REQUEST['find'] : '',
        'replace' => isset($_REQUEST['replace']) ? $_REQUEST['replace'] : '',
        'run' => isset($_REQUEST['run']) && ($_REQUEST['run'] === '1' || $_REQUEST['run'] === 'on'),
        'max-depth' => isset($_REQUEST['max-depth']) ? (int)$_REQUEST['max-depth'] : -1,
        'depth-mode' => isset($_REQUEST['depth-mode']) ? $_REQUEST['depth-mode'] : 'up_to',
        'exclude-dirs' => isset($_REQUEST['exclude-dirs']) ? $_REQUEST['exclude-dirs'] : 'wp-admin,wp-includes,node_modules,vendor,.git',
        'sites-root' => isset($_REQUEST['sites-root']) ? $_REQUEST['sites-root'] : '',
        'site-subpath' => isset($_REQUEST['site-subpath']) ? $_REQUEST['site-subpath'] : '',
    ];

    // Resolve the "sites root" browsable directory list (lets users pick site folders instead of typing paths)
    $site_dirs_list = [];
    if (!empty($options['sites-root'])) {
        $found_sites = list_site_directories($options['sites-root']);
        if ($found_sites !== null) {
            $site_dirs_list = $found_sites;
        }
    }
    $selected_dirs = array_filter(array_map('trim', explode(',', $options['dir'])));
    $selected_dirs = array_map(function($p) {
        $real = @realpath($p);
        return $real !== false ? $real : $p;
    }, $selected_dirs);

    $results = null;
    $error = null;
    
    if ($submitted) {
        if (empty($options['mode'])) {
            $error = "Replacement Mode is required.";
        } elseif (in_array($options['mode'], ['string', 'regex']) && empty($options['find'])) {
            $error = "Search pattern (Find) is required for mode '{$options['mode']}'.";
        } else {
            $results = execute_replacer($options);
            if (isset($results['error'])) {
                $error = $results['error'];
                $results = null;
            }
        }
    }
    
    // HTML Web UI Render
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PHP Batch File Replacer</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
        
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
                --diff-context-text: #e5e7eb;
            }
            
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body {
                background-color: var(--bg-primary);
                color: var(--text-primary);
                font-family: 'Plus Jakarta Sans', sans-serif;
                min-height: 100vh;
                padding: 2rem;
                line-height: 1.5;
            }
            
            .container {
                max-width: 1400px;
                margin: 0;
            }
            
            header {
                margin-bottom: 2.5rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-bottom: 1px solid var(--border);
                padding-bottom: 1.5rem;
            }
            
            .header-title h1 {
                font-size: 2rem;
                font-weight: 700;
                background: linear-gradient(135deg, #a5b4fc, #6366f1);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin-bottom: 0.25rem;
            }
            
            .header-title p {
                color: var(--text-secondary);
                font-size: 0.95rem;
            }
            
            .badge-security {
                background-color: rgba(16, 185, 129, 0.15);
                color: #34d399;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
                border: 1px solid rgba(16, 185, 129, 0.3);
            }
            
            .main-grid {
                display: grid;
                grid-template-columns: 420px 1fr;
                gap: 2rem;
                align-items: start;
            }
            
            @media (max-width: 1024px) {
                .main-grid {
                    grid-template-columns: 1fr;
                }
            }
            
            .card {
                background-color: var(--bg-card);
                border: 1px solid var(--border);
                border-radius: 16px;
                padding: 1.75rem;
                box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            }
            
            .form-group {
                margin-bottom: 1.25rem;
            }
            
            label {
                display: block;
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--text-secondary);
                margin-bottom: 0.5rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            
            input[type="text"],
            input[type="number"],
            select,
            textarea {
                width: 100%;
                background-color: rgba(9, 13, 22, 0.5);
                border: 1px solid var(--border);
                color: var(--text-primary);
                padding: 10px 14px;
                border-radius: 8px;
                font-size: 0.95rem;
                font-family: inherit;
                transition: all 0.2s ease;
            }
            
            input:focus, select:focus, textarea:focus {
                outline: none;
                border-color: var(--accent);
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            }
            
            textarea {
                resize: vertical;
                min-height: 80px;
                font-family: 'JetBrains Mono', monospace;
            }
            
            .help-text {
                font-size: 0.75rem;
                color: var(--text-secondary);
                margin-top: 0.25rem;
            }
            
            .checkbox-wrapper {
                background-color: rgba(239, 68, 68, 0.03);
                border: 1px dashed rgba(239, 68, 68, 0.2);
                padding: 1rem;
                border-radius: 10px;
                margin-top: 1.5rem;
                margin-bottom: 1.5rem;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                cursor: pointer;
            }
            
            .checkbox-wrapper input[type="checkbox"] {
                width: 18px;
                height: 18px;
                accent-color: var(--accent-danger);
                cursor: pointer;
            }
            
            .checkbox-label {
                font-weight: 600;
                color: #f87171;
                font-size: 0.9rem;
                cursor: pointer;
            }
            
            .btn {
                display: block;
                width: 100%;
                background-color: var(--accent);
                color: white;
                border: none;
                padding: 12px;
                font-size: 1rem;
                font-weight: 600;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                text-align: center;
            }
            
            .btn:hover {
                background-color: var(--accent-hover);
                transform: translateY(-1px);
            }
            
            .btn-danger {
                background-color: var(--accent-danger);
            }
            
            .btn-danger:hover {
                background-color: var(--accent-danger-hover);
            }
            
            .error-banner {
                background-color: rgba(239, 68, 68, 0.1);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: #f87171;
                padding: 1rem;
                border-radius: 10px;
                margin-bottom: 1.5rem;
                font-size: 0.95rem;
            }
            
            /* Results Panel */
            .results-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1.5rem;
            }
            
            .results-title {
                font-size: 1.35rem;
                font-weight: 600;
            }
            
            .summary-bar {
                display: flex;
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
            
            .summary-item {
                background-color: rgba(255,255,255,0.02);
                border: 1px solid var(--border);
                padding: 12px 20px;
                border-radius: 10px;
                flex: 1;
                text-align: center;
            }
            
            .summary-num {
                font-size: 1.5rem;
                font-weight: 700;
                margin-bottom: 2px;
            }
            
            .summary-num.blue { color: #60a5fa; }
            .summary-num.yellow { color: #fbbf24; }
            .summary-num.gray { color: #9ca3af; }
            
            .summary-label {
                font-size: 0.75rem;
                text-transform: uppercase;
                color: var(--text-secondary);
                letter-spacing: 0.05em;
            }
            
            .file-result-card {
                background-color: rgba(255,255,255,0.01);
                border: 1px solid var(--border);
                border-radius: 12px;
                margin-bottom: 1rem;
                overflow: hidden;
            }
            
            .file-header {
                background-color: rgba(255,255,255,0.02);
                padding: 10px 16px;
                border-bottom: 1px solid var(--border);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .file-name {
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.9rem;
                font-weight: 500;
                word-break: break-all;
            }
            
            .badge {
                font-size: 0.75rem;
                padding: 3px 8px;
                border-radius: 12px;
                font-weight: 600;
            }
            
            .badge-modified {
                background-color: rgba(251, 191, 36, 0.15);
                color: #fbbf24;
                border: 1px solid rgba(251, 191, 36, 0.3);
            }
            
            .badge-created {
                background-color: rgba(6, 182, 212, 0.15);
                color: #22d3ee;
                border: 1px solid rgba(6, 182, 212, 0.3);
            }
            
            .file-result-card.status-modified {
                border-left: 4px solid #fbbf24;
            }
            
            .file-result-card.status-created {
                border-left: 4px solid #22d3ee;
            }
            
            .file-result-card.status-error {
                border-left: 4px solid #f87171;
            }
            
            .badge-success {
                background-color: rgba(16, 185, 129, 0.15);
                color: #34d399;
                border: 1px solid rgba(16, 185, 129, 0.3);
            }
            
            .badge-unchanged {
                background-color: rgba(156, 163, 175, 0.15);
                color: #9ca3af;
                border: 1px solid rgba(156, 163, 175, 0.3);
            }
            
            .btn-apply-single {
                background-color: var(--accent);
                color: white;
                border: none;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 0.75rem;
                font-weight: 600;
                cursor: pointer;
                margin-left: 10px;
                transition: background-color 0.2s, opacity 0.2s;
            }
            .btn-apply-single:hover {
                background-color: var(--accent-hover);
            }
            .btn-apply-single:disabled {
                background-color: var(--border);
                color: var(--text-secondary);
                cursor: not-allowed;
                opacity: 0.5;
            }
            
            .diff-viewer {
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.85rem;
                overflow-x: auto;
                background-color: #060910;
                padding: 1rem;
                white-space: pre;
                line-height: 1.6;
            }
            
            .diff-line {
                display: block;
                padding: 2px 6px;
                border-radius: 4px;
                min-height: 1.5em;
            }
            
            .diff-add {
                background-color: var(--diff-add-bg);
                color: var(--diff-add-text);
            }
            
            .diff-del {
                background-color: var(--diff-del-bg);
                color: var(--diff-del-text);
            }
            
            .diff-gap {
                color: #fbbf24;
                opacity: 0.7;
                font-style: italic;
            }
            
            .diff-same {
                color: var(--text-secondary);
                opacity: 0.7;
            }
            
            .no-results {
                text-align: center;
                padding: 4rem 2rem;
                color: var(--text-secondary);
                border: 1px dashed var(--border);
                border-radius: 12px;
            }
            
            .no-results svg {
                width: 48px;
                height: 48px;
                stroke: var(--text-secondary);
                margin-bottom: 1rem;
                opacity: 0.5;
            }

            .btn-secondary {
                width: auto;
                white-space: nowrap;
                padding: 10px 16px;
                background-color: transparent;
                border: 1px solid var(--border);
                color: var(--text-primary);
            }

            .btn-secondary:hover {
                background-color: var(--bg-card-hover);
                transform: none;
            }

            .site-list-container {
                margin-top: 0.75rem;
                max-height: 220px;
                overflow-y: auto;
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 0.5rem;
                background-color: rgba(9, 13, 22, 0.3);
            }

            .site-list-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 8px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 0.9rem;
            }

            .site-list-item:hover {
                background-color: rgba(255,255,255,0.03);
            }

            .site-list-item input[type="checkbox"] {
                width: 16px;
                height: 16px;
                accent-color: var(--accent);
                cursor: pointer;
                flex-shrink: 0;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <div class="header-title">
                    <h1>PHP Batch File Replacer</h1>
                    <p>Search and replace files recursively or modify .htaccess rules from web</p>
                </div>
                <div class="badge-security">
                    Local Only Access Protected
                </div>
            </header>
            
            <div class="main-grid">
                <!-- Left Pane: Configuration Form -->
                <div class="card">
                    <form method="POST" id="replacerForm">
                        <input type="hidden" name="action" value="submit">
                        
                        <?php if ($error): ?>
                            <div class="error-banner"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="sites-root">Sites Root (optional)</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="sites-root" name="sites-root" value="<?= htmlspecialchars($options['sites-root']) ?>" placeholder="e.g. /var/www">
                                <button type="button" class="btn btn-secondary" onclick="loadSites()">List Sites</button>
                                <button type="button" class="btn btn-secondary" onclick="selectAllSites()">Select All</button>
                            </div>
                            <div class="help-text">Point this at a folder containing multiple website directories to pick from below, instead of typing paths by hand.</div>

                            <div style="margin-top: 0.75rem;">
                                <label for="site-subpath">Website Subpath (optional)</label>
                                <input type="text" id="site-subpath" name="site-subpath" value="<?= htmlspecialchars($options['site-subpath']) ?>" placeholder="e.g. public_html or public_html/public" oninput="updateDirField()">
                                <div class="help-text">Appended to each selected website's folder when building Target Directory (e.g. "public_html"). Leave blank or "." to target the website folder itself.</div>
                            </div>

                            <div id="siteListContainer" class="site-list-container">
                                <?php if (empty($options['sites-root'])): ?>
                                    <div class="help-text">Enter a sites root path and click "List Sites".</div>
                                <?php elseif (empty($site_dirs_list)): ?>
                                    <div class="help-text">No subdirectories found in this path.</div>
                                <?php else: ?>
                                    <?php foreach ($site_dirs_list as $sd):
                                        $target_path = combine_site_path($sd['path'], $options['site-subpath']);
                                        $is_checked = in_array($sd['path'], $selected_dirs, true) || in_array($target_path, $selected_dirs, true);
                                    ?>
                                        <label class="site-list-item">
                                            <input type="checkbox" class="site-checkbox" data-path="<?= htmlspecialchars($sd['path']) ?>" onchange="updateDirField()" <?= $is_checked ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars($sd['name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="dir">Target Directory</label>
                            <input type="text" id="dir" name="dir" value="<?= htmlspecialchars($options['dir']) ?>" placeholder="e.g. ./dir1,./dir2 or /var/www/html">
                            <div class="help-text">Absolute or relative paths to scan (comma-separated). Checking sites above adds/removes them here automatically.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="template">Filename Template (Glob)</label>
                            <input type="text" id="template" name="template" value="<?= htmlspecialchars($options['template']) ?>" placeholder="e.g. *.html,*.php,*.htaccess">
                            <div class="help-text">Comma-separated glob wildcards. Leave empty to scan all files.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="exclude-dirs">Exclude Directories</label>
                            <input type="text" id="exclude-dirs" name="exclude-dirs" value="<?= htmlspecialchars($options['exclude-dirs']) ?>" placeholder="e.g. wp-admin,wp-includes,node_modules,.git">
                            <div class="help-text">Comma-separated folder names to exclude from scanning.</div>
                        </div>
                        
                        <div class="form-group-row" style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1;">
                                <label for="max-depth">Search Depth</label>
                                <input type="number" id="max-depth" name="max-depth" value="<?= (int)$options['max-depth'] ?>" min="-1" placeholder="-1">
                                <div class="help-text">-1 = infinite recursion. 0 = scan target directory only.</div>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label for="depth-mode">Depth Mode</label>
                                <select id="depth-mode" name="depth-mode">
                                    <option value="up_to" <?= (!isset($options['depth-mode']) || $options['depth-mode'] === 'up_to') ? 'selected' : '' ?>>Up to Depth (&le;)</option>
                                    <option value="equal" <?= (isset($options['depth-mode']) && $options['depth-mode'] === 'equal') ? 'selected' : '' ?>>Exactly at Depth (=)</option>
                                </select>
                                <div class="help-text">Scan up to max depth, or strictly at max depth.</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="mode">Replacement Mode</label>
                            <select id="mode" name="mode" onchange="toggleModeFields()">
                                <option value="string" <?= $options['mode'] === 'string' ? 'selected' : '' ?>>String Find & Replace</option>
                                <option value="regex" <?= $options['mode'] === 'regex' ? 'selected' : '' ?>>Regular Expression (regex)</option>
                                <option value="prepend" <?= $options['mode'] === 'prepend' ? 'selected' : '' ?>>Prepend to File</option>
                                <option value="append" <?= $options['mode'] === 'append' ? 'selected' : '' ?>>Append to File</option>
                                <option value="htaccess-bot-blocker" <?= $options['mode'] === 'htaccess-bot-blocker' ? 'selected' : '' ?>>.htaccess Bot Blocker</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="findGroup">
                            <label for="find">Find</label>
                            <textarea id="find" name="find" placeholder="Text to search for..."><?= htmlspecialchars($options['find']) ?></textarea>
                            <div class="help-text" id="findHelp">Exact string to search.</div>
                        </div>
                        
                        <div class="form-group" id="replaceGroup">
                            <label for="replace">Replace</label>
                            <textarea id="replace" name="replace" placeholder="Replacement text..."><?= htmlspecialchars($options['replace']) ?></textarea>
                            <div class="help-text">Text to substitute. Can be empty to erase found content.</div>
                        </div>
                        
                        <div class="checkbox-wrapper" id="runWrapper">
                            <input type="checkbox" id="run" name="run" <?= $options['run'] ? 'checked' : '' ?>>
                            <label for="run" class="checkbox-label">Apply changes (Live Run)</label>
                        </div>
                        
                        <button type="submit" id="submitBtn" class="btn <?= $options['run'] ? 'btn-danger' : '' ?>">
                            <?= $options['run'] ? 'Apply Changes (Write Files)' : 'Scan & Preview Changes' ?>
                        </button>
                    </form>
                </div>
                
                <!-- Right Pane: Results -->
                <div>
                    <?php if ($results): ?>
                        <div class="results-header">
                            <h2 class="results-title">Results Preview</h2>
                            <?php if ($options['run']): ?>
                                <span class="badge badge-success" style="padding: 6px 12px; font-size: 0.85rem;">Live Executed</span>
                            <?php else: ?>
                                <span class="badge badge-modified" style="padding: 6px 12px; font-size: 0.85rem; background-color: rgba(99, 102, 241, 0.15); color: #818cf8; border-color: rgba(99, 102, 241, 0.3);">Dry-Run Simulation</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="summary-bar">
                            <div class="summary-item">
                                <div class="summary-num blue"><?= $results['total_files'] ?></div>
                                <div class="summary-label">Total Files Found</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-num yellow"><?= $results['modified_count'] ?></div>
                                <div class="summary-label">Proposed/Modified</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-num gray"><?= $results['skipped_count'] ?></div>
                                <div class="summary-label">Unchanged</div>
                            </div>
                        </div>
                        
                        <?php 
                        $has_changes = false;
                        foreach ($results['files'] as $f): 
                            if ($f['status'] === 'modified' || $f['status'] === 'created' || $f['status'] === 'error'):
                                $has_changes = true;
                        ?>
                            <div class="file-result-card status-<?= htmlspecialchars($f['status']) ?>">
                                <div class="file-header">
                                    <?php
                                    $display_full = isset($f['absolute']) ? $f['absolute'] : $f['path'];
                                    $cwd = getcwd();
                                    if ($cwd !== false && isset($f['absolute'])) {
                                        $real_cwd = realpath($cwd);
                                        if ($real_cwd !== false && strpos($f['absolute'], $real_cwd) === 0) {
                                            $display_full = '.' . substr($f['absolute'], strlen($real_cwd));
                                        }
                                    }
                                    ?>
                                    <span class="file-name" title="<?= htmlspecialchars(isset($f['absolute']) ? $f['absolute'] : $f['path']) ?>"><?= htmlspecialchars($display_full) ?></span>
                                    <div style="display: flex; align-items: center;">
                                        <?php if ($f['status'] === 'error'): ?>
                                            <span class="badge badge-unchanged" style="color: #f87171; border-color: rgba(239, 68, 68, 0.3);">Error: <?= htmlspecialchars($f['message']) ?></span>
                                        <?php else: ?>
                                            <?php if ($options['run'] && $f['write_success']): ?>
                                                <span class="badge badge-success">Saved</span>
                                            <?php elseif ($options['run']): ?>
                                                <span class="badge badge-unchanged" style="color: #f87171; border-color: rgba(239, 68, 68, 0.3);">Save Failed</span>
                                            <?php else: ?>
                                                <?php if ($f['status'] === 'created'): ?>
                                                    <span class="badge badge-created">To Be Created</span>
                                                <?php else: ?>
                                                    <span class="badge badge-modified">Proposed Changes</span>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn-apply-single" onclick="applySingleFile(this, <?= htmlspecialchars(json_encode($f['absolute'])) ?>)">Apply Change</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($f['diff'])): ?>
                                    <div class="diff-viewer"><?php
                                        // HTML Diff Preview with Context
                                        $context = 3;
                                        $total_lines = count($f['diff']);
                                        $to_print = array_fill(0, $total_lines, false);
                                        for ($i = 0; $i < $total_lines; $i++) {
                                            if ($f['diff'][$i]['type'] !== 'same') {
                                                for ($c = -$context; $c <= $context; $c++) {
                                                    $idx = $i + $c;
                                                    if ($idx >= 0 && $idx < $total_lines) $to_print[$idx] = true;
                                                }
                                            }
                                        }
                                        
                                        $in_gap = false;
                                        for ($i = 0; $i < $total_lines; $i++) {
                                            if ($to_print[$i]) {
                                                if ($in_gap) {
                                                    echo '<span class="diff-line diff-gap">@@ ... @@</span>';
                                                    $in_gap = false;
                                                }
                                                $item = $f['diff'][$i];
                                                $line_html = htmlspecialchars($item['line']);
                                                if ($item['type'] === 'add') {
                                                    echo '<span class="diff-line diff-add">+ ' . $line_html . '</span>';
                                                } elseif ($item['type'] === 'del') {
                                                    echo '<span class="diff-line diff-del">- ' . $line_html . '</span>';
                                                } else {
                                                    echo '<span class="diff-line diff-same">  ' . $line_html . '</span>';
                                                }
                                            } else {
                                                $in_gap = true;
                                            }
                                        }
                                    ?></div>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                        
                        <?php if (!$has_changes): ?>
                            <div class="no-results">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p style="font-weight: 600; font-size: 1.1rem; margin-bottom: 0.25rem;">Scan Completed - No changes needed</p>
                                <p style="font-size: 0.9rem;">All found files already match the targeted state.</p>
                            </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <!-- Default empty state -->
                        <div class="no-results">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 21h7a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v11m0 5l4.879-4.879m0 0a3 3 0 104.243-4.242 3 3 0 00-4.243 4.242z" />
                            </svg>
                            <p style="font-weight: 600; font-size: 1.1rem; margin-bottom: 0.25rem;">No active scan</p>
                            <p style="font-size: 0.9rem;">Fill out the parameters on the left and click scan to preview replacements.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script>
            function toggleModeFields() {
                var mode = document.getElementById('mode').value;
                var findGroup = document.getElementById('findGroup');
                var replaceGroup = document.getElementById('replaceGroup');
                var findHelp = document.getElementById('findHelp');
                var replaceLabel = document.querySelector('label[for="replace"]');
                var replaceHelp = replaceGroup.querySelector('.help-text');
                
                if (mode === 'htaccess-bot-blocker') {
                    findGroup.style.display = 'none';
                    replaceGroup.style.display = 'block';
                    replaceLabel.textContent = 'Custom Blocker Rule (Optional)';
                    replaceHelp.textContent = 'Optional custom rule(s) to inject. If left blank, the default crawler bot blocker rules will be used.';
                } else if (mode === 'prepend' || mode === 'append') {
                    findGroup.style.display = 'none';
                    replaceGroup.style.display = 'block';
                    replaceLabel.textContent = 'Value to Add';
                    replaceHelp.textContent = 'Value to add/inject into the file.';
                } else {
                    findGroup.style.display = 'block';
                    replaceGroup.style.display = 'block';
                    replaceLabel.textContent = 'Replace';
                    replaceHelp.textContent = 'Text to substitute. Can be empty to erase found content.';
                    if (mode === 'regex') {
                        findHelp.textContent = 'PCRE regex pattern, including delimiters (e.g. /<a href="[^"]+">/i).';
                    } else {
                        findHelp.textContent = 'Exact string to search.';
                    }
                }
            }
            
            // Set up form colors and titles dynamically when live run checkbox is toggled
            document.getElementById('run').addEventListener('change', function() {
                var btn = document.getElementById('submitBtn');
                if (this.checked) {
                    btn.textContent = 'Apply Changes (Write Files)';
                    btn.classList.add('btn-danger');
                } else {
                    btn.textContent = 'Scan & Preview Changes';
                    btn.classList.remove('btn-danger');
                }
            });
            
            function applySingleFile(btn, filePath) {
                if (!confirm('Are you sure you want to apply changes to this file?')) {
                    return;
                }
                
                btn.disabled = true;
                btn.innerText = 'Applying...';
                
                const form = document.querySelector('form');
                const formData = new FormData(form);
                
                // Override action and add file path
                formData.set('action', 'apply_single');
                formData.set('file', filePath);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(text => {
                    const startIdx = text.indexOf('{');
                    const endIdx = text.lastIndexOf('}');
                    if (startIdx === -1 || endIdx === -1) {
                        throw new Error('Invalid response from server: ' + text);
                    }
                    const data = JSON.parse(text.substring(startIdx, endIdx + 1));
                    if (data.success) {
                        btn.innerText = 'Applied';
                        btn.className = 'badge badge-success';
                        btn.style.backgroundColor = 'rgba(16, 185, 129, 0.15)';
                        btn.style.color = '#34d399';
                        btn.style.border = '1px solid rgba(16, 185, 129, 0.3)';
                        btn.disabled = true;
                        
                        // Find the sibling status badge and update it
                        const container = btn.parentElement;
                        const originalBadge = container.querySelector('.badge');
                        if (originalBadge) {
                            originalBadge.innerText = 'Saved';
                            originalBadge.className = 'badge badge-success';
                            originalBadge.style.backgroundColor = '';
                            originalBadge.style.color = '';
                            originalBadge.style.borderColor = '';
                        }
                        
                        // Find the card container and update border left to green
                        const card = container.closest('.file-result-card');
                        if (card) {
                            card.className = 'file-result-card'; // clear status class
                            card.style.borderLeft = '4px solid #34d399'; // green success
                        }
                    } else {
                        alert('Error: ' + data.error);
                        btn.disabled = false;
                        btn.innerText = 'Apply Change';
                    }
                })
                .catch(error => {
                    console.error(error);
                    alert('An unexpected error occurred.');
                    btn.disabled = false;
                    btn.innerText = 'Apply Change';
                });
            }
            
            // Sites Root: browse subdirectories and let checkboxes drive the Target Directory field
            function loadSites() {
                var root = document.getElementById('sites-root').value.trim();
                var container = document.getElementById('siteListContainer');
                if (!root) {
                    container.innerHTML = '<div class="help-text">Enter a sites root path first.</div>';
                    return;
                }
                container.innerHTML = '<div class="help-text">Loading...</div>';

                var formData = new FormData();
                formData.set('action', 'list_sites');
                formData.set('sites-root', root);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(text => {
                    var startIdx = text.indexOf('{');
                    var endIdx = text.lastIndexOf('}');
                    if (startIdx === -1 || endIdx === -1) {
                        throw new Error('Invalid response from server: ' + text);
                    }
                    var data = JSON.parse(text.substring(startIdx, endIdx + 1));
                    if (!data.success) {
                        container.innerHTML = '<div class="help-text">' + escapeHtml(data.error) + '</div>';
                        return;
                    }
                    renderSiteList(data.dirs);
                })
                .catch(error => {
                    console.error(error);
                    container.innerHTML = '<div class="help-text">Failed to load directory list.</div>';
                });
            }

            function renderSiteList(dirs) {
                var container = document.getElementById('siteListContainer');
                if (!dirs || dirs.length === 0) {
                    container.innerHTML = '<div class="help-text">No subdirectories found in this path.</div>';
                    return;
                }
                var currentDirs = getDirFieldEntries();
                var subpathVal = document.getElementById('site-subpath').value;
                var html = '';
                dirs.forEach(function(d) {
                    var targetPath = combineSitePath(d.path, subpathVal);
                    var checked = (currentDirs.indexOf(d.path) !== -1 || currentDirs.indexOf(targetPath) !== -1) ? 'checked' : '';
                    html += '<label class="site-list-item">' +
                        '<input type="checkbox" class="site-checkbox" data-path="' + escapeHtml(d.path) + '" onchange="updateDirField()" ' + checked + '>' +
                        '<span>' + escapeHtml(d.name) + '</span>' +
                        '</label>';
                });
                container.innerHTML = html;
            }

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function getDirFieldEntries() {
                var dirField = document.getElementById('dir');
                return dirField.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
            }

            function selectAllSites() {
                var checkboxes = document.querySelectorAll('.site-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.checked = true;
                });
                updateDirField();
            }

            function combineSitePath(base, subpath) {
                subpath = (subpath || '').trim().replace(/^\/+|\/+$/g, '');
                if (subpath === '' || subpath === '.') {
                    return base;
                }
                return base + '/' + subpath;
            }

            function updateDirField() {
                var dirField = document.getElementById('dir');
                var subpathVal = document.getElementById('site-subpath').value;
                var checkboxes = document.querySelectorAll('.site-checkbox');
                var knownBases = [];
                var checkedTargets = [];
                checkboxes.forEach(function(cb) {
                    var base = cb.getAttribute('data-path');
                    knownBases.push(base);
                    if (cb.checked) {
                        checkedTargets.push(combineSitePath(base, subpathVal));
                    }
                });

                var currentEntries = getDirFieldEntries();
                // Keep manual entries not tied to any known site (base dir or base dir + previous subpath), then add current targets for checked ones
                var kept = currentEntries.filter(function(e) {
                    return !knownBases.some(function(base) {
                        return e === base || e.indexOf(base + '/') === 0;
                    });
                });
                var merged = kept.concat(checkedTargets);

                var seen = {};
                var result = [];
                merged.forEach(function(e) {
                    if (!seen[e]) {
                        seen[e] = true;
                        result.push(e);
                    }
                });
                dirField.value = result.join(',');
            }

            // Trigger mode fields check on load
            toggleModeFields();
        </script>
    </body>
    </html>
    <?php
    exit(0);
}
