#!/usr/bin/env bash
# Installe WordPress et sa suite de tests PHPUnit pour les tests d'intégration.
# Usage : bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
set -euo pipefail

if [ $# -lt 3 ]; then
	echo "Usage : $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

TMPDIR=${TMPDIR:-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress}

download() {
	curl -fsSL "$1" -o "$2"
}

# Version exacte à installer.
if [ "$WP_VERSION" = "latest" ]; then
	download https://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
	WP_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | head -1 | sed 's/"version":"//')
fi
if [ -z "$WP_VERSION" ]; then
	echo "Version de WordPress introuvable."
	exit 1
fi
# La suite de tests est publiée par version mineure dans les tags (ex. 6.4 → tags/6.4).
WP_TESTS_TAG="tags/$WP_VERSION"
echo "WordPress $WP_VERSION"

install_wp() {
	if [ -d "$WP_CORE_DIR/wp-includes" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" "$TMPDIR/wordpress.tar.gz"
	tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
}

install_test_suite() {
	if [ ! -d "$WP_TESTS_DIR/includes" ]; then
		mkdir -p "$WP_TESTS_DIR"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/$WP_TESTS_TAG/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/$WP_TESTS_TAG/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download "https://develop.svn.wordpress.org/$WP_TESTS_TAG/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		WP_CORE_DIR_ESCAPED=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR_ESCAPED/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s:__DIR__ . '/src/':'$WP_CORE_DIR_ESCAPED/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

install_db() {
	local host="${DB_HOST%%:*}"
	local port="${DB_HOST#*:}"
	local extra=(--host="$host" --protocol=tcp)
	if [ "$port" != "$DB_HOST" ]; then
		extra+=(--port="$port")
	fi
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" "${extra[@]}" 2>/dev/null || echo "Base $DB_NAME déjà présente."
}

install_wp
install_test_suite
install_db
