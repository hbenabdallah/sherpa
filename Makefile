.PHONY: help build install link unlink doctor shell sherpa test lint bench rag-eval stop logs setup-env config-dir php release

SERVICE = app

# .env supplies defaults; whatever the shell already exports wins, the order
# docker compose uses. A plain `include .env` did the opposite — in make, a file
# assignment beats the environment — so the empty SHERPA_API_KEY= line of a
# fresh .env handed the container an empty key while the shell held a real one.
# Rewritten as ?= assignments (rule at the bottom), which only fill what is missing.
-include var/env.mk
export

USER     ?= $(shell whoami)
USER_ID  ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)
HOME_PATH ?= $(HOME)

# The service asks for a TTY, because the TUI needs one. Without a terminal on
# stdin — CI, a pipe, a cron job — docker refuses to allocate it and the target
# fails before running anything. -T there, nothing here.
RUN_TTY := $(shell [ -t 0 ] || echo -T)

# The legacy builder is a workaround for this laptop's daemon (see build), not
# a property of the project: CI and any healthy host pass BUILDKIT=1.
BUILDKIT ?= 0

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

setup-env: ## Create or update .env: this machine's lines, plus anything new in .env.dist
	@./bin/env-sync.sh $(if $(NO_PROMPT),--no-prompt,)
	@sed -i "s|^USER_ID=.*|USER_ID=$$(id -u)|" .env
	@sed -i "s|^GROUP_ID=.*|GROUP_ID=$$(id -g)|" .env
	@sed -i "s|^USER=.*|USER=$$(whoami)|" .env
	@sed -i "s|^HOME_PATH=.*|HOME_PATH=$$HOME|" .env
	@sed -i "s|^SHERPA_PROJECTS_ROOT=.*|SHERPA_PROJECTS_ROOT=$${SHERPA_PROJECTS_ROOT:-$$HOME/workspace}|" .env
	@echo "✓ .env configured for $$(whoami) (uid=$$(id -u))"

config-dir: ## Create ~/.config/sherpa owned by the current user
	@mkdir -p "$$HOME/.config/sherpa/projects"
	@echo "✓ $$HOME/.config/sherpa ready"

build: ## Build the Docker image
	# Legacy builder: BuildKit runs in its own network namespace and on this
	# host it cannot resolve registry-1.docker.io (daemon default-address-pools
	# is a single 172.30.0.0/24). The daemon's own pull path works fine.
	# Drop this override once BuildKit networking is fixed.
	DOCKER_BUILDKIT=$(BUILDKIT) docker compose build

install: setup-env config-dir build ## Build image, install Composer dependencies and set permissions
	docker compose run --rm $(RUN_TTY) $(SERVICE) bash -c "composer install --no-interaction && chmod +x bin/sherpa bin/console"

BIN_DIR ?= $(HOME)/.local/bin

# Sherpa's own PHP, for running on the host: a static build, pinned by checksum
# because it is an executable downloaded from the web. Kept out of PATH.
PHP_STATIC_VERSION ?= 8.4.23
PHP_STATIC_SHA256  ?= 1aeed5bc7967977ca5b1da7163acd91bf9ba3ac56037045d4e91ee2ff2712bb7
PHP_STATIC_URL     ?= https://dl.static-php.dev/static-php-cli/common/php-$(PHP_STATIC_VERSION)-cli-linux-x86_64.tar.gz
PHP_DIR            ?= $(HOME)/.local/share/sherpa

php: ## Install Sherpa's own PHP, to run it on the host (no root, not in PATH)
	@./bin/install-php.sh "$(PHP_STATIC_URL)" "$(PHP_STATIC_SHA256)" "$(PHP_DIR)"

# The single executable: Sherpa in a phar, appended to the same static PHP
# build's "micro" runtime. Pinned by checksum like the PHP above.
MICRO_SHA256 ?= 104659cc43c5812199b500384079dd7b235aa88fea91ca89b96fc8b5e029985f
MICRO_URL    ?= https://dl.static-php.dev/static-php-cli/common/php-$(PHP_STATIC_VERSION)-micro-linux-x86_64.tar.gz
VERSION      ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
RELEASE_DIR  ?= var/release

release: ## Build the single executable into var/release, then run it end to end (VERSION=x.y.z)
	@./bin/fetch-micro.sh "$(MICRO_URL)" "$(MICRO_SHA256)" var/micro
	@"$$(./bin/find-php.sh)" -d phar.readonly=0 bin/build-release.php var/micro/micro.sfx "$(RELEASE_DIR)" "$(VERSION)"
	@tests/release/smoke.sh "$(RELEASE_DIR)/sherpa-$(VERSION)-linux-x86_64"

link: ## Install a `sherpa` command in ~/.local/bin that works from anywhere
	@mkdir -p "$(BIN_DIR)"
	@sed "s|__SHERPA_HOME__|$(CURDIR)|" bin/sherpa.tmpl > "$(BIN_DIR)/sherpa"
	@chmod +x "$(BIN_DIR)/sherpa"
	@echo "✓ $(BIN_DIR)/sherpa → $(CURDIR)"
	@if php=$$(./bin/find-php.sh); then \
	  echo "✓ sherpa will run on this machine, with $$php"; \
	else \
	  echo "• sherpa will run in Docker: no PHP 8.4 with pdo_sqlite and pcntl here."; \
	  echo "  On the host, shell_exec runs your own tools. To switch:  make php"; \
	fi
	@case ":$$PATH:" in \
	  *":$(BIN_DIR):"*) echo "✓ $(BIN_DIR) is already in the PATH" ;; \
	  *) echo "⚠ $(BIN_DIR) is not in your PATH."; \
	     echo "  Add to ~/.zshrc:  export PATH=\"$(BIN_DIR):\$$PATH\"" ;; \
	esac

unlink: ## Remove the installed sherpa command
	@rm -f "$(BIN_DIR)/sherpa"
	@echo "✓ $(BIN_DIR)/sherpa removed"

doctor: ## Check every prerequisite and say precisely what is missing
	@./bin/doctor.sh

shell: ## Open a shell inside the container
	docker compose run --rm $(SERVICE) bash

sherpa: ## Run php bin/console sherpa
	docker compose run --rm $(SERVICE) php bin/console sherpa

# One shellcheck, the same one everywhere. CI used the runner's (0.9.0) while
# checks here ran 0.11.0, and the older one flags what the newer one lets
# through: "clean" locally, red on GitHub. Pinned, and run by both through this
# target.
SHELLCHECK_VERSION ?= v0.11.0
SHELL_SCRIPTS := bin/env-sync.sh bin/doctor.sh bin/find-php.sh bin/install-php.sh bin/fetch-micro.sh bin/sherpa.tmpl tests/release/smoke.sh

lint: ## Check the shell scripts, with the same shellcheck CI uses
	docker run --rm -v "$(CURDIR):/mnt" -w /mnt koalaman/shellcheck:$(SHELLCHECK_VERSION) $(SHELL_SCRIPTS)

test: ## Run the test suites
	docker compose run --rm --no-deps $(RUN_TTY) $(SERVICE) php tests/run.php

bench: ## Live benchmark against the API (costs a little): ARGS="--only=a,b --repeat=N --label=x"
	docker compose run --rm --no-deps -e SHERPA_BENCH_MODEL $(SERVICE) php tests/bench/bench.php $(ARGS)

rag-eval: ## Documentation search measured with a real embedding model (costs a fraction of a cent)
	docker compose run --rm --no-deps $(RUN_TTY) -e SHERPA_EMBEDDING_MODEL $(SERVICE) php tests/rag/eval_live.php

stop: ## Stop and remove containers
	docker compose down

logs: ## Tail container logs
	docker compose logs -f $(SERVICE)

var/env.mk: .env
	@mkdir -p var
	@sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=/\1 ?= /p' .env > $@
