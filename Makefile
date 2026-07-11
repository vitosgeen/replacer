# PHP Batch File Replacer Makefile

.PHONY: help start test clean

# Default target: display help instructions
help:
	@echo "===================================================="
	@echo "              PHP Batch File Replacer               "
	@echo "===================================================="
	@echo "Available commands:"
	@echo "  make start      - Start PHP development server for the Web UI (http://127.0.0.1:8080)"
	@echo "  make test       - Run the E2E test suite and rebuild mock site examples"
	@echo "  make clean      - Remove the tests sandbox directory"
	@echo "  make cli-help   - Display PHP Replacer CLI command options"
	@echo "===================================================="

# Start the built-in PHP web server
start:
	@echo "Starting PHP Web server on http://127.0.0.1:8080..."
	@echo "Press Ctrl+C to stop."
	php -S 127.0.0.1:8080

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
