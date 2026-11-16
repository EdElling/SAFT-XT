#!/usr/bin/env bash
# Legt in den Service-Containern der Pipeline Benutzer und Testdatenbanken an.
# Nur für Wegwerf-Container in GitHub Actions: feste Testpasswörter, keine echten Zugangsdaten.
#
# Umgebung: PG_CONTAINER, MYSQL_CONTAINER, MARIADB_CONTAINER, optional MSSQL_CONTAINER (Container-IDs).
set -euo pipefail

PASSWORD="saft-ci"
MSSQL_SA_PASSWORD="Saft-ci-2026!"

echo "PostgreSQL: Datenbanken"
for db in SAFT2_ext_test SAFT2_dusk SAFT2_ext_dusk; do
  docker exec "$PG_CONTAINER" createdb -U saft "$db"
done

for server in MYSQL MARIADB; do
  container_var="${server}_CONTAINER"
  container="${!container_var}"
  client=mysql
  [ "$server" = "MARIADB" ] && client=mariadb
  echo "$server: Benutzer saft mit allen Rechten (nur Wegwerf-Container)"
  docker exec "$container" "$client" -uroot -proot -e "
    CREATE USER IF NOT EXISTS 'saft'@'%' IDENTIFIED BY '${PASSWORD}';
    GRANT ALL PRIVILEGES ON *.* TO 'saft'@'%';
    FLUSH PRIVILEGES;
    CREATE DATABASE IF NOT EXISTS SAFT2_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS SAFT2_dusk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS SAFT2_ext_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS SAFT2_ext_dusk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
done

if [ -n "${MSSQL_CONTAINER:-}" ]; then
  echo "SQL Server: Login saft und Datenbanken"
  docker exec "$MSSQL_CONTAINER" /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P "$MSSQL_SA_PASSWORD" -b -Q "
    CREATE LOGIN saft WITH PASSWORD = '${PASSWORD}-Mssql1', CHECK_POLICY = OFF;
    CREATE DATABASE SAFT2_ext_test;
    CREATE DATABASE SAFT2_ext_dusk;"
  for db in SAFT2_ext_test SAFT2_ext_dusk; do
    docker exec "$MSSQL_CONTAINER" /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P "$MSSQL_SA_PASSWORD" -b -d "$db" -Q "
      CREATE USER saft FOR LOGIN saft;
      ALTER ROLE db_owner ADD MEMBER saft;"
  done
fi

echo "Datenbanken bereit."
