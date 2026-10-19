#!/usr/bin/env bash
# Runs once, on the first start of an empty MySQL volume: the Pest test database (.env.testing).

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS support_desk_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON \`support_desk_test\`.* TO '$MYSQL_USER'@'%';
EOSQL
