# Everything runs in containers; no local PHP needed (like the WordPress plugin).
# Override with: make test CONTAINER=docker
CONTAINER ?= podman
IMAGE     ?= docker.io/library/composer:2
# The composer image has no ext-intl, which typo3/cms-core requires; Classes/Feed only uses it optionally.
COMPOSER_FLAGS ?= --ignore-platform-req=ext-intl

SCHEMA_DIR := $(abspath $(CURDIR)/../schema)
SCHEMA_MOUNT := $(if $(wildcard $(SCHEMA_DIR)/v1/ojobpub.json),-v $(SCHEMA_DIR):/schema:ro,Z -e OJOBPUB_SCHEMA=/schema/v1/ojobpub.json,)
TTY := $(shell [ -t 0 ] && echo -t)
RUN = $(CONTAINER) run --rm -i $(TTY) -v $(CURDIR):/app:Z $(SCHEMA_MOUNT) -w /app $(IMAGE)

.PHONY: install test lint shell zip

install:          ## composer install (dev dependencies: phpunit, opis/json-schema)
	$(RUN) composer install --no-interaction $(COMPOSER_FLAGS)

test: vendor      ## unit tests of Classes/Feed; validates against ../schema/v1/ojobpub.json if present
	$(RUN) vendor/bin/phpunit $(ARGS)

lint:             ## php -l over all PHP files
	$(RUN) sh -c 'find Classes Configuration Tests ext_*.php -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax"; true'

shell:
	$(RUN) sh

zip:              ## TER/manual-install archive in dist/
	mkdir -p dist && git archive --format=zip --prefix=ojobpub/ -o dist/ojobpub-$$(git describe --tags --always).zip HEAD

vendor:
	$(MAKE) install
