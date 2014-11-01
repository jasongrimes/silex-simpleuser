Migrating the database between SimpleUser major versions
========================================================

## Migrating from SimpleUser 1.x to 2.x

In SimpleUser 1.x, newly added features were stored as custom fields instead of adding columns to the the users table,
to avoid breaking backward compatibility.

In version 2.0, those custom fields were moved into new columns in the users table.
The custom fields moved were: username, su:isEnabled, su:confirmationToken, and su:timePasswordResetRequested.

Changes made in version 2.0:
- Add columns to the users table: username, isEnabled, confirmationToken, timePasswordResetRequested
- Move data for those columns from custom fields to the users table, and delete those custom field rows.
- Make the users.time_created column unsigned (to avoid the "year 2038 problem")

A migration class is available to help migrate the database from version 1.x to 2.x,
and to revert from 2.x back to 1.x: [SimpleUser\Migration\MigrateV1ToV2](../src/SimpleUser/Migration/MigrateV1ToV2.php).

The migration class supports altering the schema for two database platforms: mysql and sqlite.
For other platforms, you can add the columns manually and then just migrate the data (see below).
(Pull requests would be appreciated to add DDL statements for other platforms.)

To run the migration, make a small script that exercises the migration class.
You can just run the migration all at once,
you can print the SQL commands so that you can examine them and then run them yourself,
or you can make the database changes manually and just migrate the data.

Before migrating, make sure to back up your existing database. Ex. for mysql:

    DBNAME=mydb
    mysqldump -uroot -p --opt $DBNAME users user_custom_fields > simpleuser-v1-bak.sql

Create a script for setting up the migration. Create a `bin/migrate-bootstrap.php` script with the following content:

    <?php
    /**
     * Bootstrap for database migration.
     *
     * See https://github.com/jasongrimes/silex-simpleuser/edit/master/sql/MIGRATION.md
     */
    
    require __DIR__ . '/../vendor/autoload.php';
    
    // Set up the Doctrine DBAL Connection.
    // (The database user must have permission to ALTER the tables.)
    $app = new Silex\Application();
    $app->register(new Silex\Provider\DoctrineServiceProvider());
    
    // Get $app['db.options'] from your config file, if you have one, something like this: 
    // require __DIR__ . '/../config/local.php';
    
    // Or, define $app['db.options'] explicitly:
    $app['db.options'] = array(
        'driver'   => 'pdo_mysql',
        'dbname' => 'simpleuser_demo',
        'host' => 'localhost',
        'user' => '',
        'password' => '',
    );

    // Instantiate the migration class.
    // (If you're using custom table names for the "users" and "user_custom_fields" tables,
    // pass them as the optional second and third constructor arguments.)
    $migrate = new SimpleUser\Migration\MigrateV1ToV2($app['db']);

Then, you can create simple one-liner migration scripts which include the bootstrap file,
or you can just run them with `php -r`.

To migrate up to version 2.0:

    php -r 'require "migrate-bootstrap.php"; $migrate->up();'

Reverting back to the previous version:

    php -r 'require "migrate-bootstrap.php"; $migrate->down();'

Printing the SQL instead of executing it:

    php -r 'require "migrate-bootstrap.php"; echo implode(";\n", $migrate->sqlUp());'

    php -r 'require "migrate-bootstrap.php"; echo implode(";\n", $migrate->sqlDown());'

Migrating just the data (useful if you had to add the columns manually because your database platform is not supported):

    php -r 'require "migrate-bootstrap.php"; echo implode(";\n", $migrate->sqlUpData());'


