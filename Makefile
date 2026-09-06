.PHONY: help build install link unlink doctor shell sherpa test stop logs setup-env config-dir

SERVICE = app

-include .env
export

USER     ?= $(shell whoami)
USER_ID  ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)
HOME_PATH ?= $(HOME)

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

setup-env: ## Copy .env.dist to .env and inject current user
	@if [ ! -f .env ]; then cp .env.dist .env; fi
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
	DOCKER_BUILDKIT=0 docker compose build

install: setup-env config-dir build ## Build image, install Composer dependencies and set permissions
	docker compose run --rm $(SERVICE) bash -c "composer install && chmod +x bin/sherpa bin/console"

BIN_DIR ?= $(HOME)/.local/bin

link: ## Install a `sherpa` command in ~/.local/bin that works from anywhere
	@mkdir -p "$(BIN_DIR)"
	@sed "s|__SHERPA_HOME__|$(CURDIR)|" bin/sherpa.tmpl > "$(BIN_DIR)/sherpa"
	@chmod +x "$(BIN_DIR)/sherpa"
	@echo "✓ $(BIN_DIR)/sherpa → $(CURDIR)"
	@case ":$$PATH:" in \
	  *":$(BIN_DIR):"*) echo "✓ $(BIN_DIR) est déjà dans le PATH" ;; \
	  *) echo "⚠ $(BIN_DIR) n'est pas dans votre PATH."; \
	     echo "  Ajoutez à ~/.zshrc :  export PATH=\"$(BIN_DIR):\$$PATH\"" ;; \
	esac

unlink: ## Remove the installed sherpa command
	@rm -f "$(BIN_DIR)/sherpa"
	@echo "✓ $(BIN_DIR)/sherpa supprimé"

doctor: ## Check every prerequisite and say precisely what is missing
	@./bin/doctor.sh

shell: ## Open a shell inside the container
	docker compose run --rm $(SERVICE) bash

sherpa: ## Run php bin/console sherpa
	docker compose run --rm $(SERVICE) php bin/console sherpa

test: ## Run the test suites
	docker compose run --rm --no-deps $(SERVICE) php tests/run.php

stop: ## Stop and remove containers
	docker compose down

logs: ## Tail container logs
	docker compose logs -f $(SERVICE)
