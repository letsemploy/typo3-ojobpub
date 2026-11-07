#!/bin/sh
# Installs TYPO3 $TYPO3_VERSION in /var/www/project with this extension as a path
# repository, then creates the dev site. Runs inside the typo3-* container as www-data
# (make setup). Idempotent: existing steps are skipped.
set -eu
cd /var/www/project
DB="typo3_${TYPO3_VERSION%%.*}"

until php -r 'new mysqli("db", "typo3", "typo3", "'"$DB"'");' >/dev/null 2>&1; do
    echo "waiting for the database..."
    sleep 2
done

if [ ! -f composer.json ]; then
    rm -rf public
    composer create-project --no-interaction --no-progress "typo3/cms-base-distribution:^$TYPO3_VERSION" .
    composer config repositories.ojobpub \
        '{"type": "path", "url": "/ext", "options": {"symlink": true, "versions": {"letsemploy/typo3-ojobpub": "0.1.0"}}}'
    composer config repositories.ojobpub-dev \
        '{"type": "path", "url": "/ext/Build/dev/ojobpub_dev", "options": {"symlink": true, "versions": {"letsemploy/ojobpub-dev": "0.1.0"}}}'
    composer require --no-interaction --no-progress 'letsemploy/typo3-ojobpub:*' 'letsemploy/ojobpub-dev:*'
fi

if [ ! -f config/system/settings.php ]; then
    vendor/bin/typo3 setup --no-interaction --force \
        --driver=mysqli --host=db --port=3306 --dbname="$DB" --username=typo3 --password=typo3 \
        --admin-username=admin --admin-user-password='Password1!' --admin-email=admin@example.com \
        --project-name="oJobPub dev (TYPO3 $TYPO3_VERSION)" --server-type=apache
fi

cat > config/system/additional.php <<'PHP'
<?php
// Written by Build/dev/setup.sh: settings for the local dev installation only.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] = '.*';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'] = '*';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['displayErrors'] = 1;
$GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
PHP

vendor/bin/typo3 extension:setup
vendor/bin/typo3 ojobpub-dev:site --base="$TYPO3_BASE"
vendor/bin/typo3 cache:flush
