# PHP Batch File Replacer

A single-file PHP tool for finding and replacing content across many files at once. It runs either as a **CLI script** or, when placed on a web server, as a **local web UI** — same engine, same options, two interfaces.

Everything lives in [replacer.php](replacer.php): argument parsing, file discovery, diffing, and rendering are all in that one file with no external dependencies (pure PHP, no Composer packages).

## Requirements

- PHP 7+ (CLI SAPI for terminal use, or any SAPI + built-in/Apache/Nginx server for the web UI)
- No extensions beyond core PHP (`posix_isatty` is used opportunistically for colored CLI output but is optional)

## Quick start

```bash
# CLI: dry-run preview
php replacer.php --dir=./site --template=*.html,*.php --mode=string --find="http://old.com" --replace="https://new.com"

# CLI: apply the change for real
php replacer.php --dir=./site --template=*.html,*.php --mode=string --find="http://old.com" --replace="https://new.com" --run

# Web UI (localhost only by default)
php -S 127.0.0.1:8080 replacer.php
# then open http://127.0.0.1:8080
```

A `Makefile` wraps the common commands:

| Command | Effect |
|---|---|
| `make start` | Starts the PHP built-in server on `127.0.0.1:8080` for the web UI |
| `make test` | Runs the E2E test suite (`tests/run_e2e.php`) and rebuilds the mock sandbox sites |
| `make clean` | Deletes `tests/sandbox` |
| `make cli-help` | Prints `php replacer.php --help` |

## How it works

Both interfaces funnel into the same core:

1. `get_expanded_target_dirs()` — resolves `--dir` (comma-separated, supports glob `*`/`?` and `regex:` matching) into real directory paths.
2. `find_files()` / `find_scanned_dirs()` — recursively walk each target directory (with depth limits and exclusions) collecting files that match `--template` and, for the `.htaccess` bot-blocker mode, every scanned directory (so brand-new `.htaccess` files can be created).
3. `apply_replacement()` — applies the chosen `--mode` to a file's content and returns the new content, normalizing line endings to match the original file.
4. `get_diff()` — an LCS-based diff (with prefix/suffix trimming for speed) that produces a line-level add/del/same diff between old and new content.
5. `execute_replacer()` — orchestrates the above for every matched file, and (if `--run` is set) writes the new content back to disk. Returns a structured results array consumed by both the CLI printer and the web UI renderer.

Everything is a dry-run (simulation) unless you explicitly opt into writing files.

## CLI usage

```
php replacer.php --dir=<path> --template=<glob> --mode=<mode> [options]
```

### Arguments

| Flag | Description |
|---|---|
| `--dir=<paths>` | Target directories, comma-separated (default: current directory `.`) |
| `--template=<glob>` | File pattern(s), comma-separated (e.g. `*.html,*.php`). Empty = all files. |
| `--mode=<mode>` | One of: `string`, `regex`, `prepend`, `append`, `htaccess-bot-blocker` |
| `--find=<pattern>` | Search string or regex (required for `string` / `regex`) |
| `--replace=<string>` | Replacement text, or the value to prepend/append/inject |
| `--max-depth=<n>` | Max recursion depth. `-1` = infinite (default), `0` = target dir only, `1` = one level down, etc. |
| `--depth-mode=<mode>` | `up_to` (default, everything ≤ max-depth) or `equal` (only exactly at max-depth) |
| `--exclude-dirs=<list>` | Comma-separated folder names to skip (default: `wp-admin,wp-includes,node_modules,vendor,.git`) |
| `--run` | Actually write changes. Without it, the tool only previews (dry-run). |
| `--help`, `-h` | Show help |

### `--dir` targeting options

Besides plain paths, `--dir` accepts:

- **Glob**: `--dir=./sites/*` — expands to every matching directory.
- **Regex**: `--dir=./sites|regex:^site\d+$` (or `--dir=regex:^site\d+$` to search from `.`) — recursively matches subdirectory relative paths against a PCRE pattern (auto-wrapped in `~...~i` delimiters if you don't supply your own), up to 5 levels deep.

### Modes

| Mode | Behavior |
|---|---|
| `string` | Plain `str_replace($find, $replace, $content)` |
| `regex` | `preg_replace($find, $replace, $content)` — `$find` must include PCRE delimiters, e.g. `/foo/i` |
| `prepend` | Inserts `$replace` at the top of the file (skipped if that exact text is already present) |
| `append` | Inserts `$replace` at the bottom of the file (skipped if already present) |
| `htaccess-bot-blocker` | Injects a `RewriteCond`/`RewriteRule` bot-blocking rule into `.htaccess` files. Uses a built-in default crawler blocklist (AhrefsBot, SemrushBot, GPTBot, ClaudeBot, etc.) unless `--replace` supplies a custom rule containing `RewriteCond`/`RewriteRule`. Smart about `RewriteEngine On`: inserts after it if present, otherwise prepends one. Skips files that already contain the block. Can also **create** a new `.htaccess` in any scanned directory that lacks one (only for this mode). |

In all modes, `--find`/`--replace` line endings are normalized to match each target file's own line-ending style (`\r\n` vs `\n`) before the operation runs.

### Examples

```bash
# Preview replacing a domain across HTML/PHP files
php replacer.php --dir=./public_html --template=*.html,*.php --mode=string --find="old-domain.com" --replace="new-domain.com"

# Regex replace, applied for real
php replacer.php --dir=./src --template=*.php --mode=regex --find='/console\.log\(.*?\);/' --replace='' --run

# Add a bot blocker to every .htaccess under multiple sites, only 2 levels deep
php replacer.php --dir=./sites/* --template=.htaccess --mode=htaccess-bot-blocker --max-depth=2 --run

# Dry-run bot blocker across dynamically matched site folders
php replacer.php --dir="./sites|regex:^site\d+$" --template=.htaccess --mode=htaccess-bot-blocker
```

Output includes a colorized unified-style diff (3 lines of context, collapsed gaps shown as `@@ ... @@`) per changed file, followed by a summary of total/modified/unchanged counts. Colors are automatically disabled when stdout isn't a TTY.

## Web UI

Running `replacer.php` under any PHP web server serves an interactive UI with the same options as a form (directory, template, exclude-dirs, depth/depth-mode, mode, find/replace, a "Live Run" checkbox) plus a live diff viewer per file.

- **Access is restricted to `127.0.0.1` / `::1` / `localhost` by default.** Requests from any other IP get an HTTP 403 page. To allow remote access, edit the `ALLOW_REMOTE_ACCESS` constant at the top of [replacer.php](replacer.php:12) — only do this if you understand the risk of letting remote users edit files on your server.
- Submitting the form runs a preview (or a live run if "Apply changes" is checked) and renders a results panel: summary counts, then one card per changed/created/error file with an inline diff.
- Each proposed change in a dry-run has an **"Apply Change"** button that fires an AJAX request (`action=apply_single`) to write just that one file, with a path-containment check ensuring the target stays inside the resolved target directories.

Because this exposes filesystem write access, treat the web UI as a local admin tool, not something to expose publicly.

## Testing

An E2E test suite lives in [tests/run_e2e.php](tests/run_e2e.php). It builds a sandbox of mock sites under `tests/sandbox/` and exercises the CLI against them.

```bash
make test    # runs tests/run_e2e.php
make clean   # removes tests/sandbox
```

### Live bot-blocker check with curl

`tests/run_e2e.php` only exercises the file-rewriting logic — it can't verify that the generated `.htaccess` rules actually block requests, since that requires `mod_rewrite` on a real Apache server (PHP's built-in `php -S` server ignores `.htaccess` entirely).

[tests/curl_test.sh](tests/curl_test.sh) fills that gap: it sends real HTTP requests to a live URL with a chosen bot's `User-Agent`, and checks the response status.

```bash
# Single bot, expects 403
tests/curl_test.sh --url=http://example.com/ --bot=GPTBot

# Every known bot in the default blocklist
tests/curl_test.sh --url=http://example.com/ --bot=all

# A normal browser, expects 200 (confirms real visitors aren't blocked)
tests/curl_test.sh --url=http://example.com/ --browser

# Any custom User-Agent
tests/curl_test.sh --url=http://example.com/ --ua="MyCustomBot/1.0"

# List known bot names
tests/curl_test.sh --list

# Or via Makefile
make curl-test URL=http://example.com/ BOT=GPTBot
```

Exits non-zero if any request's status doesn't match the expected code (override with `--expect=<code>`), so it can be wired into CI against a staging host.

## Remote (FTP/SFTP) Edition

[replacer_remote.php](replacer_remote.php) is a standalone sibling tool: the same search/replace engine (string, regex, prepend, append, htaccess-bot-blocker modes; glob/regex `--dir` targeting; depth limits; exclude-dirs; dry-run vs `--run`; line-level diff) applied over a remote **FTP or SFTP** connection instead of the local filesystem. Use it when you only have FTP/SFTP access to a site (typical shared hosting) rather than local or SSH shell access.

### Requirements

- FTP support needs only PHP's core `ftp` extension (present in most builds).
- SFTP support uses [phpseclib](https://github.com/phpseclib/phpseclib) (pure PHP, no `ssh2` system extension required). Install it once:

```bash
composer install    # or: make composer-install
```

If `vendor/autoload.php` isn't present, FTP still works; SFTP will report a clear error telling you to run `composer install`.

### CLI usage

```bash
# Dry-run over FTP
php replacer_remote.php --protocol=ftp --host=ftp.example.com --user=bob \
  --dir=/public_html --template=*.html,*.php --mode=string --find="old.com" --replace="new.com"

# Live run over SFTP with a private key
php replacer_remote.php --protocol=sftp --host=example.com --user=bob --key=~/.ssh/id_rsa \
  --dir=/var/www --template=*.php --mode=regex --find='/console\.log\(.*?\);/' --replace='' --run

# Password omitted → prompted interactively (hidden input), avoiding shell history/ps exposure
php replacer_remote.php --protocol=ftp --host=ftp.example.com --user=bob --dir=/public_html --template=*.html --mode=string --find=old --replace=new
```

Connection flags: `--protocol=ftp|sftp`, `--host`, `--port`, `--user`, `--password` (or `--password-env=VARNAME`, or omit to be prompted), `--key`/`--passphrase` (SFTP public-key auth), `--ssl` (explicit FTPS), `--active` (FTP active mode instead of passive), `--timeout`. All other flags (`--dir`, `--template`, `--mode`, `--find`, `--replace`, `--max-depth`, `--depth-mode`, `--exclude-dirs`, `--run`) match replacer.php exactly. Run `php replacer_remote.php --help` for the full list.

### Web UI

```bash
php -S 127.0.0.1:8081 replacer_remote.php   # or: make start-remote
```

Same dark-themed dry-run/diff/apply-single workflow as replacer.php's web UI, plus connection fields and a remote directory browser. It's a client-side (fetch-driven) single page: the form never reloads, so credentials typed in stay put between a preview scan and clicking "Apply Change" on individual files. Restricted to `127.0.0.1`/`::1` by default via the same `ALLOW_REMOTE_ACCESS` constant — since every request here also carries FTP/SFTP credentials, be extra cautious about enabling remote access.

## Security notes

- The web UI's localhost-only restriction is the primary safety net for the web interface — don't disable it on an internet-facing server without another access control layer (auth, firewall, VPN) in front of it.
- `--run` (CLI) and the "Apply changes" checkbox (web) are the only paths that mutate files; everything else is a simulation.
- `exclude-dirs` defaults to skipping `wp-admin,wp-includes,node_modules,vendor,.git` to avoid touching vendored/third-party code — override deliberately if you need to reach into those.
