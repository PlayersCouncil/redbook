#!/bin/sh
# First-time setup, run once against an empty database:
#   docker compose --profile init run --rm init
#
# Creates the schema, loads the default data (the placeholder faction,
# tournament tiers) into an empty database, imports the cards from
# lotrtcg-card-data, and optionally creates an admin account.
#
# Loading the default data empties the database, so it only happens when
# there are no cards yet (or with INIT_FORCE=1). The card import itself runs
# every time: run init again with a new CARD_DATA_REF to update the cards.
set -e

cd /var/www/html

console() {
    runuser -u www-data -- php bin/console "$@"
}

php /usr/local/lib/app/dbcheck.php wait

echo "== Running migrations"
console doctrine:migrations:migrate --no-interaction --allow-no-migration

if php /usr/local/lib/app/dbcheck.php has-cards && [ "${INIT_FORCE:-0}" != "1" ]; then
    echo "== Cards already present; keeping the default data (INIT_FORCE=1 to wipe and reload)"
else
    echo "== Loading default data"
    console doctrine:fixtures:load --no-interaction
fi

# The card data: lotrtcg-card-data at CARD_DATA_REF (a branch, tag or commit). Importing is safe to
# repeat, so running init again with a new CARD_DATA_REF updates the cards.
data_dir="${CARD_DATA_DIR:-/srv/carddata}"
ref="${CARD_DATA_REF:-main}"
git config --global --add safe.directory "$data_dir"
if [ ! -d "$data_dir/.git" ]; then
    if [ -n "$(ls -A "$data_dir" 2>/dev/null)" ]; then
        echo "== Using the card data already in $data_dir (not a git checkout)"
    else
        git init -q "$data_dir"
        git -C "$data_dir" remote add origin "${CARD_DATA_REPO}"
    fi
fi
if [ -d "$data_dir/.git" ]; then
    echo "== Fetching card data ${CARD_DATA_REPO} @ ${ref}"
    git -C "$data_dir" remote set-url origin "${CARD_DATA_REPO}"
    if git -C "$data_dir" fetch -q --depth 1 origin "$ref"; then
        git -C "$data_dir" checkout -q --force FETCH_HEAD
    else
        echo "(fetch failed; using the copy on disk)"
    fi
fi
echo "== Importing cards"
console app:import:cards "$data_dir" --no-interaction

if [ -n "${ADMIN_USERNAME:-}" ]; then
    echo "== Admin account ${ADMIN_USERNAME}"
    if php /usr/local/lib/app/dbcheck.php has-user "${ADMIN_USERNAME}"; then
        echo "(already exists; left unchanged)"
    else
        console fos:user:create "${ADMIN_USERNAME}" "${ADMIN_EMAIL}" "${ADMIN_PASSWORD}" --no-interaction
        console fos:user:activate "${ADMIN_USERNAME}"
        console fos:user:promote --super "${ADMIN_USERNAME}"
    fi
fi

echo "== Done"
