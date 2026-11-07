# Everything runs in containers; no local PHP needed (like the WordPress plugin).
# Override with: make test CONTAINER=docker COMPOSE=docker-compose
CONTAINER ?= podman
COMPOSE   ?= podman-compose
IMAGE     ?= docker.io/library/composer:2
# The composer image has no ext-intl, which typo3/cms-core requires; Classes/Feed only uses it optionally.
COMPOSER_FLAGS ?= --ignore-platform-req=ext-intl

# Local TYPO3 installation to work with: 14 (v14.3, http://localhost:8014) or 13 (v13.4, :8013).
TYPO3 ?= 14
URL   := http://localhost:80$(TYPO3)
TYPO3_CLI = $(COMPOSE) exec -T --user www-data typo3-$(TYPO3)

SCHEMA_DIR := $(abspath $(CURDIR)/../schema)
comma := ,
SCHEMA_MOUNT := $(if $(wildcard $(SCHEMA_DIR)/v1/ojobpub.json),-v $(SCHEMA_DIR):/schema:ro$(comma)Z -e OJOBPUB_SCHEMA=/schema/v1/ojobpub.json)
TTY := $(shell [ -t 0 ] && echo -t)
RUN = $(CONTAINER) run --rm -i $(TTY) -v $(CURDIR):/app:Z $(SCHEMA_MOUNT) -w /app $(IMAGE)
# Functional tests need ext-intl, which the composer image lacks: use the dev image of TYPO3 14 (PHP 8.5).
DEV_IMAGE := localhost/ojobpub-dev:php8.5

.PHONY: help install test test-functional lint shell zip up down reset setup seed typo3 feed issues check-tca validate validate-all

help:
	@echo "make install         composer install (dev dependencies: phpunit, testing-framework, opis/json-schema)"
	@echo "make test            unit tests; validates against ../schema/v1/ojobpub.json if present"
	@echo "make test-functional  functional tests (TYPO3 + SQLite, in the dev image)"
	@echo "make lint            php -l over all PHP files"
	@echo "make zip             TER/manual-install archive in dist/"
	@echo
	@echo "Local TYPO3 (TYPO3=14 or TYPO3=13, default 14):"
	@echo "make up              start TYPO3 $(TYPO3) on $(URL)"
	@echo "make setup           install TYPO3, this extension and the dev site (idempotent)"
	@echo "make seed            replace all job records with sample jobs"
	@echo "make typo3 ARGS=...  run a TYPO3 CLI command, e.g. make typo3 ARGS='cache:flush'"
	@echo "make feed            print $(URL)/.well-known/ojobpub.json"
	@echo "make issues          jobs left out of the feed and why"
	@echo "make check-tca       fail if the TCA needs migrations in this TYPO3 version"
	@echo "make validate        validate the feed against the oJobPub schema"
	@echo "make validate-all    up, setup, seed, validate, issues, check-tca for TYPO3 13 and 14"
	@echo "make down            stop containers (data stays)"
	@echo "make reset           stop containers and delete all data"

install:
	$(RUN) composer install --no-interaction $(COMPOSER_FLAGS)

test: vendor
	$(RUN) vendor/bin/phpunit $(ARGS)

test-functional: vendor
	@$(CONTAINER) image inspect $(DEV_IMAGE) >/dev/null 2>&1 || $(COMPOSE) build typo3-14
	$(CONTAINER) run --rm -i $(TTY) -v $(CURDIR):/app:Z $(SCHEMA_MOUNT) -e typo3DatabaseDriver=pdo_sqlite -w /app $(DEV_IMAGE) \
		vendor/bin/phpunit -c phpunit.functional.xml.dist $(ARGS)

lint:
	$(RUN) sh -c 'find Classes Configuration Tests Build ext_*.php -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax"; true'

shell:
	$(RUN) sh

zip:
	mkdir -p dist && git archive --format=zip --prefix=ojobpub/ -o dist/ojobpub-$$(git describe --tags --always).zip HEAD

vendor:
	$(MAKE) install

up:
	$(COMPOSE) up -d --build db typo3-$(TYPO3)

down:
	$(COMPOSE) down

reset:
	$(COMPOSE) down -v

setup:
	$(TYPO3_CLI) /ext/Build/dev/setup.sh
	@echo "Backend: $(URL)/typo3 (admin / Password1!)"
	@echo "Feed:    $(URL)/.well-known/ojobpub.json"

seed:
	$(TYPO3_CLI) vendor/bin/typo3 ojobpub-dev:seed

typo3:
	$(TYPO3_CLI) vendor/bin/typo3 $(ARGS)

feed:
	@curl -fsS $(URL)/.well-known/ojobpub.json

issues:
	$(TYPO3_CLI) vendor/bin/typo3 ojobpub:feed main --issues --no-cache

check-tca:
	$(TYPO3_CLI) vendor/bin/typo3 ojobpub-dev:check-tca

validate:
	@test -f $(SCHEMA_DIR)/v1/ojobpub.json || { echo "Clone github.com/letsemploy/schema next to this repository."; exit 1; }
	@mkdir -p dist
	curl -fsS -D dist/feed-$(TYPO3).headers $(URL)/.well-known/ojobpub.json -o dist/feed-$(TYPO3).json
	$(CONTAINER) run --rm -v $(CURDIR)/dist:/data:Z -v $(SCHEMA_DIR)/v1/ojobpub.json:/schema.json:ro,Z \
		docker.io/library/python:3.13-slim sh -c \
		"pip install -q --root-user-action=ignore check-jsonschema && check-jsonschema --schemafile /schema.json /data/feed-$(TYPO3).json"

validate-all:
	@for v in 13 14; do \
		echo "== TYPO3 $$v"; \
		$(MAKE) -s TYPO3=$$v up setup seed validate issues check-tca || exit 1; \
	done
