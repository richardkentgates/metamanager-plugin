.PHONY: install test test-unit test-integration lint analyse clean help

WP_TESTS_DIR ?= /tmp/wordpress-tests-lib
WP_CORE_DIR  ?= /tmp/wordpress
DB_NAME      ?= metamanager_test
DB_USER      ?= root
DB_PASS      ?= root
DB_HOST      ?= 127.0.0.1
WP_VERSION   ?= latest

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------------------
# Environment
# ---------------------------------------------------------------------------

install: ## Set up WP test suite (MySQL + WP core + test libs)
	bash tests/bin/install-wp-tests.sh $(DB_NAME) $(DB_USER) $(DB_PASS) $(DB_HOST) $(WP_VERSION) false

install-deps: ## Install Composer dev dependencies
	composer install --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
# Testing
# ---------------------------------------------------------------------------

test: test-unit test-integration ## Run all tests

test-unit: ## Run unit tests (no database required)
	vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit

test-integration: ## Run integration tests (requires WP test DB)
	vendor/bin/phpunit --configuration phpunit.xml --testsuite Integration

test-one: ## Run a single test class (usage: make test-one CLASS=Test_MM_DB)
	vendor/bin/phpunit --configuration phpunit.xml --filter $(CLASS)

test-coverage: ## Run tests with coverage report
	vendor/bin/phpunit --configuration phpunit.xml --coverage-html build/coverage

# ---------------------------------------------------------------------------
# Static analysis
# ---------------------------------------------------------------------------

lint: ## PHP lint all files
	@find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" -not -path "./stubs/*" | while read f; do \
		php -l "$$f" 2>&1; \
	done | grep -v "No syntax errors" || echo "All files OK"

analyse: ## PHPStan static analysis
	vendor/bin/phpstan analyse --configuration phpstan.neon --no-progress

shellcheck: ## ShellCheck on .sh files
	@find . -name "*.sh" -not -path "./vendor/*" -not -path "./tests/*" | while read f; do \
		shellcheck -S error "$$f"; \
	done || true

# ---------------------------------------------------------------------------
# Docker (local WordPress environment)
# ---------------------------------------------------------------------------

up: ## Start Docker WordPress + MySQL environment
	docker compose up -d

down: ## Stop Docker environment
	docker compose down

logs: ## Tail Docker logs
	docker compose logs -f

# ---------------------------------------------------------------------------
# Cleanup
# ---------------------------------------------------------------------------

clean: ## Remove test artifacts and caches
	rm -rf build/
	rm -f .phpunit.result.cache tests/.phpunit.result.cache
	rm -rf /tmp/wordpress /tmp/wordpress-tests-lib

clean-all: clean ## Clean everything including Docker volumes
	docker compose down -v
