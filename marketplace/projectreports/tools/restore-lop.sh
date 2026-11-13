#!/usr/bin/env bash
# Restore the LOP price lists from a GLPI database backup into
# Price lists (LOP) > supplier > "Previous versions".
#
# Nothing in the active LOPs is changed: each restored LOP is added as a previous
# version, which you can then view, export, make active or delete in GLPI.
#
# Usage:  ./restore-lop.sh /path/to/glpi-backup.sql      (.sql or .sql.gz)
# Needs:  the updated Project reports plugin (with "Previous versions"),
#         the glpi-db container running, and the glpi database password.
set -euo pipefail

DB_CONTAINER="${DB_CONTAINER:-glpi-db}"
DB_NAME="${DB_NAME:-glpi}"
DB_USER="${DB_USER:-glpi}"

DUMP="${1:-}"
if [ -z "$DUMP" ] || [ ! -f "$DUMP" ]; then
    echo "Usage: $0 /path/to/glpi-backup.sql   (or .sql.gz)" >&2
    exit 1
fi

T=glpi_plugin_projectreports_lopprices
R=${T}_restore
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

cat_dump() { case "$DUMP" in *.gz) zcat -- "$DUMP" ;; *) cat -- "$DUMP" ;; esac; }

# 1. The LOP table from the backup, renamed so it does not touch the live one
cat_dump | awk -v t="$T" '
    index($0, "Table structure for table `" t "`") { on = 1 }
    on && /Table structure for table `/ && !index($0, "`" t "`") { exit }
    on { print }
' | sed "s/\`$T\`/\`$R\`/g" > "$WORK/table.sql"

if ! grep -q "CREATE TABLE \`$R\`" "$WORK/table.sql"; then
    echo "The backup has no LOP table ($T). Nothing to restore." >&2
    exit 1
fi
if ! grep -q "INSERT INTO \`$R\`" "$WORK/table.sql"; then
    echo "The LOP table in the backup is empty. Nothing to restore." >&2
    exit 1
fi

# 2. The LOP names and VAT saved in GLPI's settings (plugin:projectreports / lop_info), still SQL-escaped
LOP_INFO=$(cat_dump | grep -oP "'plugin:projectreports','lop_info','(\\\\.|[^'\\\\])*'" | head -1 \
    | sed "s/^'plugin:projectreports','lop_info',//" || true)
[ -n "$LOP_INFO" ] || LOP_INFO=NULL

read -r -s -p "Password of the '$DB_USER' database user: " DB_PASS
echo
run_sql() { docker exec -i -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" mariadb --default-character-set=utf8mb4 -u "$DB_USER" "$DB_NAME" "$@"; }

# 3. Load the backup copy and show what it holds
run_sql < "$WORK/table.sql"
cat > "$WORK/prepare.sql" <<SQL
ALTER TABLE \`$R\` ADD COLUMN IF NOT EXISTS \`device\` varchar(20) NOT NULL DEFAULT '';
ALTER TABLE \`$R\` ADD COLUMN IF NOT EXISTS \`spare\` varchar(255) NOT NULL DEFAULT '';
ALTER TABLE \`$R\` ADD COLUMN IF NOT EXISTS \`qty\` int NOT NULL DEFAULT '1';
ALTER TABLE \`$R\` ADD COLUMN IF NOT EXISTS \`qty_per_device\` int DEFAULT NULL;
SET @j = $LOP_INFO;
SELECT r.suppliers_id AS supplier_id,
       COALESCE(s.name, '?') AS supplier,
       COALESCE(JSON_VALUE(@j, CONCAT('\$."', r.suppliers_id, '".name')), '(no name)') AS lop_in_backup,
       COUNT(*) AS parts
FROM \`$R\` r LEFT JOIN glpi_suppliers s ON s.id = r.suppliers_id
GROUP BY r.suppliers_id, s.name ORDER BY r.suppliers_id;
SQL
echo
echo "LOPs found in the backup:"
run_sql -t < "$WORK/prepare.sql"

echo
read -r -p "Supplier IDs to restore (e.g. 7 or 7 12), or press Enter for all: " IDS
FILTER=""
if [ -n "${IDS// /}" ]; then
    if ! [[ "$IDS" =~ ^[0-9\ ]+$ ]]; then
        run_sql -e "DROP TABLE IF EXISTS \`$R\`"
        echo "Only numbers separated by spaces, please. Nothing restored." >&2
        exit 1
    fi
    FILTER="AND r.suppliers_id IN ($(echo $IDS | tr ' ' ','))"
fi

# 4. Add them as previous versions (tables created here if the LOP page was not opened yet)
cat > "$WORK/restore.sql" <<SQL
CREATE TABLE IF NOT EXISTS \`glpi_plugin_projectreports_lopversions\` (
  \`id\` int unsigned NOT NULL AUTO_INCREMENT,
  \`suppliers_id\` int unsigned NOT NULL DEFAULT '0',
  \`name\` varchar(255) NOT NULL DEFAULT '',
  \`vat\` decimal(5,2) NOT NULL DEFAULT '0.00',
  \`parts\` int NOT NULL DEFAULT '0',
  \`imported\` timestamp NULL DEFAULT NULL,
  \`archived\` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (\`id\`),
  KEY \`suppliers_id\` (\`suppliers_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
CREATE TABLE IF NOT EXISTS \`glpi_plugin_projectreports_lopversionlines\` (
  \`id\` int unsigned NOT NULL AUTO_INCREMENT,
  \`lopversions_id\` int unsigned NOT NULL DEFAULT '0',
  \`part\` varchar(255) NOT NULL DEFAULT '',
  \`description\` text,
  \`price\` decimal(20,6) NOT NULL DEFAULT '0.000000',
  \`device\` varchar(20) NOT NULL DEFAULT '',
  \`spare\` varchar(255) NOT NULL DEFAULT '',
  \`qty\` int NOT NULL DEFAULT '1',
  \`qty_per_device\` int DEFAULT NULL,
  PRIMARY KEY (\`id\`),
  KEY \`lopversions_id\` (\`lopversions_id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

SET @j = $LOP_INFO;
SET @stamp = NOW();
START TRANSACTION;
INSERT INTO glpi_plugin_projectreports_lopversions (suppliers_id, name, vat, parts, imported, archived)
SELECT r.suppliers_id,
       LEFT(CONCAT(COALESCE(JSON_VALUE(@j, CONCAT('\$."', r.suppliers_id, '".name')), 'LOP'), ' (restored from backup)'), 255),
       COALESCE(CAST(JSON_VALUE(@j, CONCAT('\$."', r.suppliers_id, '".vat')) AS DECIMAL(5,2)), 0),
       COUNT(*),
       NULLIF(JSON_VALUE(@j, CONCAT('\$."', r.suppliers_id, '".imported')), ''),
       @stamp
FROM \`$R\` r WHERE r.suppliers_id > 0 $FILTER
GROUP BY r.suppliers_id;
INSERT INTO glpi_plugin_projectreports_lopversionlines (lopversions_id, part, description, price, device, spare, qty, qty_per_device)
SELECT v.id, r.part, r.description, r.price, r.device, r.spare, r.qty, r.qty_per_device
FROM \`$R\` r
JOIN glpi_plugin_projectreports_lopversions v ON v.suppliers_id = r.suppliers_id AND v.archived = @stamp
WHERE r.suppliers_id > 0 $FILTER;
COMMIT;
DROP TABLE \`$R\`;
SELECT v.id AS version_id, v.suppliers_id AS supplier_id, v.name, v.parts, COUNT(l.id) AS lines_restored
FROM glpi_plugin_projectreports_lopversions v
LEFT JOIN glpi_plugin_projectreports_lopversionlines l ON l.lopversions_id = v.id
WHERE v.archived = @stamp GROUP BY v.id ORDER BY v.suppliers_id;
SQL
echo
echo "Restored:"
run_sql -t < "$WORK/restore.sql"
echo
echo "Done. In GLPI open Price lists (LOP) > View the supplier > Previous versions."
echo "From there you can view, export, make active or delete each restored LOP."
