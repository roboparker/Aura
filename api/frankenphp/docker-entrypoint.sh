#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q 'SELECT 1' 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if [ "$( find ./migrations -iname '*.php' -print -quit )" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	# www-data must own writes under var/. Walk var/ but prune var/media: the
	# worker mounts it read-only for the backup pass, and setfacl on a
	# read-only mount fails — under `set -e` that crash-loops the entrypoint.
	# The web container keeps media writable and gets its ACLs in the guarded
	# pass below.
	find var -path var/media -prune -o -print0 \
		| xargs -0 setfacl -m u:www-data:rwX -m u:"$(whoami)":rwX
	find var -path var/media -prune -o -type d -print0 \
		| xargs -0 setfacl -d -m u:www-data:rwX -m u:"$(whoami)":rwX

	# Apply the same ACLs to var/media only when it is writable, so the web
	# container's read-write upload mount is covered while the worker's
	# read-only mount is skipped (failures ignored as a belt-and-braces guard).
	setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var/media 2>/dev/null || true
	setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var/media 2>/dev/null || true

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
