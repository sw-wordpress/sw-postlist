.DEFAULT_GOAL := build

NPM ?= npm
ADMIN_DIR := apps/admin
DEPS_STAMP := $(ADMIN_DIR)/node_modules/.make-install

.PHONY: build
build: $(DEPS_STAMP)
	cd $(ADMIN_DIR) && $(NPM) run build

$(DEPS_STAMP): $(ADMIN_DIR)/package.json $(ADMIN_DIR)/package-lock.json
	cd $(ADMIN_DIR) && $(NPM) ci --include=dev
	touch "$@"

# Local quality gate: PHP (cs, stan, unit), WordPress suite, admin app (typecheck, lint, tests).
.PHONY: check
check: $(DEPS_STAMP)
	composer check
	bin/test-db.sh start
	composer test:wp
	composer test:wp65
	cd $(ADMIN_DIR) && $(NPM) run check
