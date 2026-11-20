.DEFAULT_GOAL := build

NPM ?= npm
ADMIN_DIR := apps/admin
DEPS_STAMP := $(ADMIN_DIR)/node_modules/.make-install

# Usage: make release VERSION=0.5.1 (publishes the current branch and release tag to origin).
.PHONY: release
release: export SWF_RELEASE_VERSION = $(VERSION)
release:
	bash bin/release.sh

# Typecheck, then build the admin SPA and the frontend assets.
.PHONY: build
build: $(DEPS_STAMP)
	cd $(ADMIN_DIR) && $(NPM) run build

$(DEPS_STAMP): $(ADMIN_DIR)/package.json $(ADMIN_DIR)/package-lock.json
	cd $(ADMIN_DIR) && $(NPM) ci --include=dev
	touch "$@"
