# PHP Batch File Replacer Makefile

.PHONY: help start start-remote test clean curl-test composer-install remote-cli-help

# Default target: display help instructions
help:
	@echo "===================================================="
	@echo "              PHP Batch File Replacer               "
	@echo "===================================================="
	@echo "Available commands:"
	@echo "  make start            - Start PHP development server for the Web UI (http://127.0.0.1:8080)"
	@echo "  make start-remote     - Start PHP development server for the Remote FTP/SFTP Web UI (http://127.0.0.1:8081)"
	@echo "  make composer-install - Install phpseclib (required for SFTP support in replacer_remote.php)"
	@echo "  make test             - Run the E2E test suite and rebuild mock site examples"
	@echo "  make clean            - Remove the tests sandbox directory"
	@echo "  make cli-help         - Display PHP Replacer CLI command options"
	@echo "  make remote-cli-help  - Display Remote (FTP/SFTP) Replacer CLI command options"
	@echo "  make curl-test        - Simulate a bot request against a live URL (URL=... BOT=...)"
	@echo "===================================================="

# Start the built-in PHP web server
start:
	@echo "Starting PHP Web server on http://127.0.0.1:8080..."
	@echo "Press Ctrl+C to stop."
	php -S 127.0.0.1:8080

# Start the built-in PHP web server for the remote (FTP/SFTP) variant
start-remote:
	@echo "Starting PHP Web server on http://127.0.0.1:8081..."
	@echo "Press Ctrl+C to stop."
	php -S 127.0.0.1:8081 replacer_remote.php

# Install optional Composer dependency (phpseclib) needed for SFTP support
composer-install:
	composer install

# Run the automated E2E tests
test:
	php tests/run_e2e.php

# Clean the test sandbox
clean:
	@echo "Cleaning sandbox directory..."
	rm -rf tests/sandbox
	@echo "Clean complete."

# View script CLI help options
cli-help:
	php replacer.php --help

# View the remote (FTP/SFTP) variant's CLI help options
remote-cli-help:
	php replacer_remote.php --help

# Simulate a crawler bot hitting a live URL to verify .htaccess bot-blocker rules.
# Usage: make curl-test URL=http://example.com/ BOT=GPTBot
#        make curl-test URL=http://example.com/ BOT=all
curl-test:
	tests/curl_test.sh --url=$(URL) --bot=$(BOT)
