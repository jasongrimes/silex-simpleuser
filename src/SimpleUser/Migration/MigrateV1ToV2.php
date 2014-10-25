<?php

namespace SimpleUser\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;

/**
 * Migrate the database from SimpleUser v1.x to v2.x.
 *
 * See sql/MIGRATION.md for details.
 *
 * Usage:
 *
 * <code>
 * // Set up the Doctrine DBAL Connection.
 * $app = new Silex\Application();
 * $app->register(new DoctrineServiceProvider(), array(
 *     'db.options' => array(...),
 * ));
 *
 * // Instantiate this class.
 * // (If you're using custom table names for "users" and "user_custom_fields",
 * // pass them as the optional second and third constructor arguments.)
 * $migrate = new MigrateV1toV2($app['db']);
 *
 * // To upgrade the database from v1 to v2 (both the schema and the data format):
 * $migrate->up();
 *
 * // To just print the SQL commands that would be run by $migrate->up(), but not actually run them:
 * echo implode(";\n", $migrate->sqlUp());
 *
 * // To just print the SQL commands for migrating the data, not ALTERing the table
 * // (useful if you had to add the columns manually because the database platform is not supported):
 * echo implode(";\n", $migrate->sqlUpData());
 *
 * // To revert the schema and data from v2 back to v1:
 * $migrate->down();
 *
 * // To print the SQL commands that would be run by $migrate->down(), but not actually run them:
 * echo implode(";\n", $migrate->sqlDown());
 *
 * </code>
 *
 */
class MigrateV1ToV2
{
    /** @var \Doctrine\DBAL\Connection */
    protected $conn;

    // Map custom field name to the new column in the users table.
    protected $fieldmap = array(
        'username' => 'username',
        'su:isEnabled' => 'isEnabled',
        'su:confirmationToken' => 'confirmationToken',
        'su:timePasswordResetRequested' => 'timePasswordResetRequested',
    );

    protected $usersTable;
    protected $userCustomFieldsTable;

    /**
     * @param Connection $conn
     * @param string $usersTable Optional, default "users".
     * @param string $userCustomFieldsTable Optional, default "user_custom_fields".
     */
    public function __construct(Connection $conn, $usersTable = 'users', $userCustomFieldsTable = 'user_custom_fields')
    {
        $this->conn = $conn;
        $this->usersTable = $usersTable;
        $this->userCustomFieldsTable = $userCustomFieldsTable;
    }

    /**
     * Migrate database up to the new version.
     */
    public function up()
    {
        foreach ($this->sqlUp() as $sql) {
            $this->conn->executeUpdate($sql);
        }
    }

    /**
     * Get SQL queries to migrate database up to the new version.
     *
     * @return array An array of SQL query strings.
     */
    public function sqlUp()
    {
        return array_merge($this->sqlUpSchema(), $this->sqlUpData());
    }

    /**
     * Revert database back down to the previous version.
     */
    public function down()
    {
        foreach ($this->sqlDown() as $sql) {
            $this->conn->executeUpdate($sql);
        }
    }

    /**
     * Get SQL queries to revert database back down to the previous version.
     *
     * @return array An array of SQL query strings.
     */
    public function sqlDown()
    {
        return array_merge($this->sqlDownData(), $this->sqlDownSchema());
    }


    public function sqlUpSchema()
    {
        $method = 'sqlUpSchema' . strtoupper($this->getPlatformName());
        if (!method_exists($this, $method)) {
            throw new \RuntimeException('Unsupported platform "' . $this->getPlatformName() . '".');
        }

        return $this->$method();
    }

    protected function sqlUpSchemaMysql()
    {
        return array('
            ALTER TABLE ' . $this->conn->quoteIdentifier($this->usersTable) . '
            CHANGE COLUMN `time_created` `time_created` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN `username` VARCHAR(100),
            ADD COLUMN `isEnabled` TINYINT(1) NOT NULL DEFAULT 1,
            ADD COLUMN `confirmationToken` VARCHAR(100),
            ADD COLUMN `timePasswordResetRequested` INT(11) UNSIGNED,
            ADD UNIQUE KEY `username` (`username`)
        ');
    }

    protected function sqlUpSchemaSqlite()
    {
        $queries = array();
        $queries[] = 'ALTER TABLE ' . $this->conn->quoteIdentifier($this->usersTable) . ' ADD COLUMN username VARCHAR(100)';
        $queries[] ='ALTER TABLE ' . $this->conn->quoteIdentifier($this->usersTable) . ' ADD COLUMN isEnabled INTEGER NOT NULL DEFAULT 1';
        $queries[] ='ALTER TABLE ' . $this->conn->quoteIdentifier($this->usersTable) . ' ADD COLUMN confirmationToken VARCHAR(100)';
        $queries[] ='ALTER TABLE ' . $this->conn->quoteIdentifier($this->usersTable) . ' ADD COLUMN timePasswordResetRequested INT';
        $queries[] ='CREATE UNIQUE INDEX username ON ' . $this->conn->quoteIdentifier($this->usersTable) . ' (username)';

        return $queries;
    }

    public function sqlUpData()
    {
        // Get custom fields to migrate.
        $sql = 'SELECT * FROM ' . $this->conn->quoteIdentifier($this->userCustomFieldsTable) . '
            WHERE attribute IN ("' . implode('", "', array_keys($this->fieldmap)) . '")';
        $customFields = $this->conn->fetchAll($sql);

        $queries = array();
        foreach ($customFields as $customField) {
            // Copy custom field to users table.
            $queries[] = 'UPDATE ' . $this->conn->quoteIdentifier($this->usersTable)
                . ' SET ' . $this->conn->quoteIdentifier($this->fieldmap[$customField['attribute']]) . ' = ' . $this->conn->quote($customField['value'], Type::STRING)
                . ' WHERE id = ' . $this->conn->quote($customField['user_id'], Type::INTEGER);

            // Delete the custom field.
            $queries[] = 'DELETE FROM ' . $this->conn->quoteIdentifier($this->userCustomFieldsTable)
                . ' WHERE user_id = ' . $this->conn->quote($customField['user_id'], Type::INTEGER)
                . ' AND attribute = ' . $this->conn->quote($customField['attribute'], Type::STRING);
        }

        return $queries;
    }

    /**
     * Revert data to the previous version.
     */
    public function sqlDownData()
    {
        // Map columns to custom fields.
        $colmap = array();
        foreach ($this->fieldmap as $field => $col) {
            $colmap[$col] = $field;
        }

        // Test that the v2 columns actually exist.
        $existingCols = $this->conn->getSchemaManager()->listTableColumns($this->usersTable);
        $existingColnames = array_map(function($col) { return $col->getName(); }, $existingCols);
        foreach ($this->fieldmap as $col) {
            if (!in_array($col, $existingColnames)) {
                throw new \RuntimeException('Cannot migrate down because current schema is not v2. (Missing column "' . $this->usersTable . '.' . $col . '").');
            }
        }

        // Get user columns to revert back to custom fields.
        $userData = $this->conn->fetchAll('SELECT id AS user_id, ' . implode(', ', $this->fieldmap) . ' FROM ' . $this->conn->quoteIdentifier($this->usersTable));

        $queries = array();
        foreach ($userData as $row) {
            foreach ($this->fieldmap as $col) {
                if ($row[$col] !== null) {
                    $queries[] = 'INSERT INTO ' . $this->conn->quoteIdentifier($this->userCustomFieldsTable)
                        . ' (user_id, attribute, value) VALUES'
                        . ' (' . $this->conn->quote($row['user_id'], Type::INTEGER)
                        . ', ' . $this->conn->quote($colmap[$col], Type::STRING)
                        . ', ' . $this->conn->quote($row[$col], Type::STRING)
                        . ')';
                }
            }

        }

        return $queries;
    }

    public function sqlDownSchema()
    {
        $method = 'sqlDownSchema' . strtoupper($this->getPlatformName());
        if (!method_exists($this, $method)) {
            throw new \RuntimeException('Unsupported platform "' . $this->getPlatformName() . '".');
        }

        return $this->$method();
    }

    protected function sqlDownSchemaMysql()
    {
        return array('ALTER TABLE ' . $this->conn->quoteIdentifier($this->usersTable) . '
            DROP COLUMN username,
            DROP COLUMN isEnabled,
            DROP COLUMN confirmationToken,
            DROP COLUMN timePasswordResetRequested');
    }

    protected function sqlDownSchemaSqlite()
    {
        $queries = array();

        $newTable = $this->usersTable . '_' . uniqid();

        $queries[] = 'CREATE TABLE ' . $this->conn->quoteIdentifier($newTable) . ' (
            id INTEGER PRIMARY KEY,
            email VARCHAR(100) NOT NULL DEFAULT "" UNIQUE,
            password VARCHAR(255) DEFAULT NULL,
            salt VARCHAR(255) NOT NULL DEFAULT "",
            roles VARCHAR(255) NOT NULL DEFAULT "",
            name VARCHAR(100) NOT NULL DEFAULT "",
            time_created INT NOT NULL DEFAULT 0
        )';
        $queries[] = 'INSERT INTO ' . $this->conn->quoteIdentifier($newTable) . '
            SELECT id, email, password, salt, roles, name, time_created FROM ' . $this->conn->quoteIdentifier($this->usersTable);
        $queries[] = 'DROP TABLE ' . $this->conn->quoteIdentifier($this->usersTable);
        $queries[] = 'ALTER TABLE ' . $this->conn->quoteIdentifier($newTable) . ' RENAME TO ' . $this->conn->quoteIdentifier($this->usersTable);

        return $queries;
    }

    /**
     * Get the connection's database platform name (ex. "mysql", "sqlite").
     *
     * @return string
     */
    protected function getPlatformName()
    {
        return $this->conn->getDatabasePlatform()->getName();
    }

}