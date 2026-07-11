<?php
/**
 * PHP Batch File Replacer - E2E Test Suite & Examples Builder
 * Developed by Antigravity AI
 */

define('SANDBOX_DIR', __DIR__ . '/sandbox');
define('REPLACER_SCRIPT', dirname(__DIR__) . '/replacer.php');

// Helper to print colored CLI text
function test_color($text, $color_code) {
    return "\033[{$color_code}m{$text}\033[0m";
}

// Function to setup sandbox with realistic website file structures
function setup_sandbox() {
    if (is_dir(SANDBOX_DIR)) {
        // Recursively remove sandbox directory
        $it = new RecursiveDirectoryIterator(SANDBOX_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir(SANDBOX_DIR);
    }
    mkdir(SANDBOX_DIR, 0777, true);

    echo test_color("Setting up mock websites environment...\n", "34");

    // -------------------------------------------------------------
    // Site 1: Plain Web Store (HTML & PHP links/tags to replace)
    // -------------------------------------------------------------
    $site1 = SANDBOX_DIR . '/site1_store';
    mkdir("$site1/assets", 0777, true);
    mkdir("$site1/includes", 0777, true);
    
    file_put_contents("$site1/index.html", '
<!DOCTYPE html>
<html>
<head><title>My Store</title></head>
<body>
    <h1>Welcome to My Store</h1>
    <p>Check out our <a href="http://oldlink.com/products">products catalog</a>.</p>
    <footer><a href="http://oldlink.com/support">Support Page</a></footer>
</body>
</html>');

    file_put_contents("$site1/includes/header.php", '
<?php
echo "<header>";
echo "  <nav>";
echo "    <a href=\"http://oldlink.com/home\">Home</a>";
echo "    <a href=\"http://oldlink.com/contact\">Contact Us</a>";
echo "  </nav>";
echo "</header>";
');

    // -------------------------------------------------------------
    // Site 2: Blog (htaccess file with NO RewriteEngine On)
    // -------------------------------------------------------------
    $site2 = SANDBOX_DIR . '/site2_blog';
    mkdir($site2, 0777, true);
    file_put_contents("$site2/.htaccess", "# Blog Rules\n# Custom configs\nOptions -Indexes\n");

    // -------------------------------------------------------------
    // Site 3: Portfolio (htaccess file WITH RewriteEngine On)
    // -------------------------------------------------------------
    $site3 = SANDBOX_DIR . '/site3_portfolio';
    mkdir($site3, 0777, true);
    file_put_contents("$site3/.htaccess", "
# Portfolio rewrite rules
RewriteEngine On
RewriteBase /portfolio/

# Custom redirection
RewriteRule ^gallery$ projects.php [L,R=301]
");

    // -------------------------------------------------------------
    // Site 4: Secured Website (htaccess already has blocker rules)
    // -------------------------------------------------------------
    $site4 = SANDBOX_DIR . '/site4_secured';
    mkdir($site4, 0777, true);
    file_put_contents("$site4/.htaccess", "
# Already secured website rules
RewriteEngine On
RewriteCond %{HTTP_USER_AGENT} (AhrefsBot|SemrushBot|MJ12bot) [NC]
RewriteRule .* - [F,L]

RewriteBase /
");
    
    // -------------------------------------------------------------
    // Site 5: News Portal (No htaccess file)
    // -------------------------------------------------------------
    $site5 = SANDBOX_DIR . '/site5_news';
    mkdir("$site5/admin", 0777, true);
    file_put_contents("$site5/index.html", "<h1>Welcome to News Portal</h1>");
    file_put_contents("$site5/admin/dashboard.php", "<?php echo 'Admin Dashboard'; ?>");

    // -------------------------------------------------------------
    // Site 6: Photo Gallery (No htaccess file)
    // -------------------------------------------------------------
    $site6 = SANDBOX_DIR . '/site6_gallery';
    mkdir($site6, 0777, true);
    file_put_contents("$site6/index.html", "<h1>Photo Gallery</h1>");
    
    echo test_color("Mock websites created under: " . realpath(SANDBOX_DIR) . "\n\n", "32");
}

// Run helper using safe argument escaping to avoid shell variable expansion issues
function run_replacer($args_array) {
    $escaped = [];
    foreach ($args_array as $arg) {
        $escaped[] = escapeshellarg($arg);
    }
    $cmd = "php " . escapeshellarg(REPLACER_SCRIPT) . " " . implode(' ', $escaped);
    exec($cmd, $output, $return_var);
    return [
        'output' => implode("\n", $output),
        'code' => $return_var
    ];
}

// ---------------------------------------------------------
// TEST CASES
// ---------------------------------------------------------
$tests_passed = 0;
$tests_failed = 0;

function assert_test($name, $assertion) {
    global $tests_passed, $tests_failed;
    if ($assertion) {
        echo "[ " . test_color("PASS", "32") . " ] $name\n";
        $tests_passed++;
    } else {
        echo "[ " . test_color("FAIL", "31") . " ] $name\n";
        $tests_failed++;
    }
}

// Initialize environment
setup_sandbox();

// --- TEST 1: Dry-Run string replacement (site1_store) ---
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=*.html,*.php',
    '--mode=string',
    '--find=http://oldlink.com',
    '--replace=https://newlink.com'
]);
$file_contents = file_get_contents(SANDBOX_DIR . '/site1_store/index.html');
$contains_old = (strpos($file_contents, 'http://oldlink.com') !== false);
$contains_proposed = (strpos($res['output'], '[MODIFIED] index.html') !== false && strpos($res['output'], '[MODIFIED] includes/header.php') !== false);

assert_test("Dry-Run: Proposes correct changes but does not write to files", $contains_old && $contains_proposed);

// --- TEST 2: Live-Run string replacement (site1_store) ---
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=*.html,*.php',
    '--mode=string',
    '--find=http://oldlink.com',
    '--replace=https://newlink.com',
    '--run'
]);
$html_contents = file_get_contents(SANDBOX_DIR . '/site1_store/index.html');
$php_contents = file_get_contents(SANDBOX_DIR . '/site1_store/includes/header.php');
$replaced_html = (strpos($html_contents, 'https://newlink.com') !== false && strpos($html_contents, 'http://oldlink.com') === false);
$replaced_php = (strpos($php_contents, 'https://newlink.com') !== false && strpos($php_contents, 'http://oldlink.com') === false);

assert_test("Live-Run: Correctly writes string replacements to multiple file extensions", $replaced_html && $replaced_php);

// --- TEST 3: Max Depth Limit (site1_store) ---
setup_sandbox();
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=*.php',
    '--mode=string',
    '--find=http://oldlink.com',
    '--replace=https://newlink.com',
    '--max-depth=0',
    '--run'
]);
$php_contents = file_get_contents(SANDBOX_DIR . '/site1_store/includes/header.php');
$no_sub_change = (strpos($php_contents, 'http://oldlink.com') !== false);

assert_test("Max Depth: Depth limit restricts scanning to the specified directory nesting level", $no_sub_change);

// --- TEST 4: Bot Blocker - Prepend rewrite engine and blocker (site2_blog) ---
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site2_blog',
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--run'
]);
$htaccess = file_get_contents(SANDBOX_DIR . '/site2_blog/.htaccess');
$has_rewrite_on = (strpos($htaccess, 'RewriteEngine On') === 0);
$has_blocker = (strpos($htaccess, 'AhrefsBot') !== false);

assert_test("Bot Blocker: Prepends RewriteEngine On and blocker rules to empty .htaccess files", $has_rewrite_on && $has_blocker);

// --- TEST 5: Bot Blocker - Insert right after existing RewriteEngine On (site3_portfolio) ---
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site3_portfolio',
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--run'
]);
$htaccess = file_get_contents(SANDBOX_DIR . '/site3_portfolio/.htaccess');
$pattern = '/RewriteEngine\s+On\s+RewriteCond %{HTTP_USER_AGENT}/i';
$correct_insertion = preg_match($pattern, $htaccess);

assert_test("Bot Blocker: Inserts blocker conditions immediately after existing RewriteEngine On", $correct_insertion);

// --- TEST 6: Bot Blocker - Skip already secured files (site4_secured) ---
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site4_secured',
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--run'
]);
$contains_no_modified = (strpos($res['output'], 'Files proposed/modified: 0') !== false);

assert_test("Bot Blocker: Correctly skips files that are already secured", $contains_no_modified);

// --- TEST 7: Regex Search and Replace ---
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=*.html',
    '--mode=regex',
    '--find=/Welcome to ([a-zA-Z\s]+)/i',
    '--replace=Welcome to our premium $1!',
    '--run'
]);
$html = file_get_contents(SANDBOX_DIR . '/site1_store/index.html');
$has_regex_replace = (strpos($html, 'Welcome to our premium My Store!') !== false);

assert_test("Regex Mode: Successfully parses PCRE patterns and substitutes backreferences", $has_regex_replace);

// --- TEST 8: Multiple Directories Scan ---
setup_sandbox();
$res = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store,' . SANDBOX_DIR . '/site3_portfolio',
    '--template=*.html,*.htaccess',
    '--mode=string',
    '--find=http://oldlink.com',
    '--replace=https://newlink.com',
    '--run'
]);
$html = file_get_contents(SANDBOX_DIR . '/site1_store/index.html');
$contains_replaced = (strpos($html, 'https://newlink.com') !== false);
$contains_multi_log = (strpos($res['output'], 'site1_store/index.html') !== false);

assert_test("Multi-Directory: Successfully targets and modifies files across multiple selected directories", $contains_replaced && $contains_multi_log);

// --- TEST 9: Prepend Mode ---
setup_sandbox();
$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=*.html',
    '--mode=prepend',
    '--replace=<!-- PREPENDED HEADERS -->',
    '--run'
]);
$html = file_get_contents(SANDBOX_DIR . '/site1_store/index.html');
$starts_with_prepend = (strpos($html, '<!-- PREPENDED HEADERS -->') === 0);

assert_test("Prepend Mode: Prepends text at the beginning of matching files", $starts_with_prepend);

// --- TEST 10: Append Mode ---
$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=*.html',
    '--mode=append',
    '--replace=<!-- APPENDED FOOTER -->',
    '--run'
]);
$html = file_get_contents(SANDBOX_DIR . '/site1_store/index.html');
$ends_with_append = (substr(trim($html), -strlen('<!-- APPENDED FOOTER -->')) === '<!-- APPENDED FOOTER -->');

assert_test("Append Mode: Appends text to the end of matching files", $ends_with_append);

// --- TEST 11: Bot Blocker Custom Rules ---
setup_sandbox();
$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site2_blog',
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--replace=RewriteCond %{HTTP_USER_AGENT} (MyCustomBot) [NC]' . "\n" . 'RewriteRule .* - [F,L]',
    '--run'
]);
$htaccess = file_get_contents(SANDBOX_DIR . '/site2_blog/.htaccess');
$has_custom_bot = (strpos($htaccess, 'MyCustomBot') !== false);
$has_default_bot = (strpos($htaccess, 'AhrefsBot') !== false);

assert_test("Bot Blocker Custom Rules: Injects custom blocker rule instead of default rules", $has_custom_bot && !$has_default_bot);

// --- TEST 12: Bot Blocker Create htaccess If Missing ---
setup_sandbox();
$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--run'
]);
$htaccess_path = SANDBOX_DIR . '/site1_store/.htaccess';
$exists = file_exists($htaccess_path);
$contents = $exists ? file_get_contents($htaccess_path) : '';
$has_rewrite_on = (strpos($contents, 'RewriteEngine On') === 0);
$has_blocker = (strpos($contents, 'AhrefsBot') !== false);

assert_test("Bot Blocker Creation: Creates a new .htaccess file with blocker rules if it does not exist", $exists && $has_rewrite_on && $has_blocker);

// --- TEST 13: Bot Blocker Custom Rules Avoid Duplicating RewriteEngine ---
setup_sandbox();
$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site1_store',
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--replace=RewriteEngine On' . "\n" . 'RewriteCond %{HTTP_USER_AGENT} (MyCustomBot) [NC]' . "\n" . 'RewriteRule .* - [F,L]',
    '--run'
]);
$htaccess_path = SANDBOX_DIR . '/site1_store/.htaccess';
$contents = file_get_contents($htaccess_path);
$count = substr_count(strtolower($contents), 'rewriteengine on');

assert_test("Bot Blocker Engine De-duplication: Does not duplicate RewriteEngine On when present in custom rule", $count === 1);

// --- TEST 14: Bot Blocker Depth Limit Creation ---
setup_sandbox();
$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR,
    '--template=.htaccess',
    '--mode=htaccess-bot-blocker',
    '--max-depth=1',
    '--run'
]);
$site1_htaccess = SANDBOX_DIR . '/site1_store/.htaccess';
$site1_created = file_exists($site1_htaccess);

$admin_htaccess = SANDBOX_DIR . '/site5_news/admin/.htaccess';
$admin_not_created = !file_exists($admin_htaccess);

assert_test("Bot Blocker Depth Limit: Restricts htaccess creation to the specified search depth", $site1_created && $admin_not_created);

// --- TEST 15: String Replacement Line Endings Mismatch ---
setup_sandbox();
$htaccess_path = SANDBOX_DIR . '/site4_secured/.htaccess';
file_put_contents($htaccess_path, "RewriteEngine On\r\nRewriteCond %{HTTP_USER_AGENT} (AhrefsBot) [NC]\r\nRewriteRule .* - [F,L]\r\n");

$res_run = run_replacer([
    '--dir=' . SANDBOX_DIR . '/site4_secured',
    '--template=.htaccess',
    '--mode=string',
    '--find=' . "RewriteCond %{HTTP_USER_AGENT} (AhrefsBot) [NC]\nRewriteRule .* - [F,L]",
    '--replace=RewriteRule ^.*$ - [F,L]',
    '--run'
]);

$contents = file_get_contents($htaccess_path);
$replaced = (strpos($contents, 'RewriteRule ^.*$ - [F,L]') !== false);
assert_test("Line Ending Normalization: Successfully matches multi-line search string despite CRLF/LF line ending mismatch", $replaced);

// ---------------------------------------------------------
// FINAL SUMMARY
// ---------------------------------------------------------
echo "\n" . test_color("=== E2E Test Suite Summary ===", "1;36") . "\n";
echo "Total Tests Run: " . ($tests_passed + $tests_failed) . "\n";
echo "Passed: " . test_color($tests_passed, "32") . "\n";
echo "Failed: " . ($tests_failed > 0 ? test_color($tests_failed, "31") : "0") . "\n\n";

// Clean up sandbox after successful test run
if ($tests_failed === 0) {
    echo test_color("All tests completed successfully. Keeping sandbox directory as code examples.", "32") . "\n";
} else {
    exit(1);
}
