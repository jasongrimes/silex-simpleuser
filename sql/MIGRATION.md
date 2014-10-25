Migrating the database between SimpleUser major versions
========================================================

## Migrating from SimpleUser 1.x to 2.x

In SimpleUser 1.x, newly added features stored data as custom fields instead of altering the users table,
to avoid breaking backward compatibility.

In version 2.0, these custom fields were moved into new columns in the users table.
These custom fields were: username, su:isEnabled, su:confirmationToken, and su:timePasswordResetRequested.

Changes made in version 2.0:
- Add columns to the users table: username, isEnabled, confirmationToken, timePasswordResetRequested
- Move data for those columns from custom fields to the users table, and delete those custom field rows.
- Make the users.time_created column unsigned (to avoid the "year 2038 problem")

Before migrating, make sure to back up your existing database. Ex. for mysql:

    mysqldump -uroot -p --opt MYDB users user_custom_fields > users.sql

A migration class is available to help migrate the database from version 1.x to 2.x,
and to revert from 2.x back to 1.x: [SimpleUser\Migration\MigrateV1ToV2](src/SimpleUser/Migration/MigrateV1ToV2.php).

The migration class supports altering the schema for two database platforms: mysql and sqlite.
For other platforms, you can add the columns manually and then just migrate the data (see below).
(Pull requests would be appreciated to add DDL for other platforms.)

To run the migration, make a small script that exercises the migration class.
You can just run the migration all at once (<code>$migrate->up();</code>),
or you can print the SQL commands so that you can examine them and then run them yourself.

Running the migration:

    <?php

    // Set up the Doctrine DBAL Connection.
    // (The database user must have permission to ALTER the tables.)
    $app = new Silex\Application();
    $app->register(new DoctrineServiceProvider(), array(
        'db.options' => array(...),
    ));

    // Instantiate the migration class.
    // (If you're using custom table names for the "users" and "user_custom_fields" tables,
    // pass them as the optional second and third constructor arguments.)
    $migrate = new SimpleUser\Migration\MigrateV1toV2($app['db']);

    // To upgrade the database from v1 to v2 (both the schema and the data format):
    $migrate->up();

Reverting back to the previous version:

    // To revert the schema and data from v2 back to v1:
    $migrate->down();

Printing the SQL instead of executing it:

    // To just print the SQL commands that would be run by $migrate->up(), but not actually run them:
    echo implode(";\n", $migrate->sqlUp());

    // To print the SQL commands that would be run by $migrate->down(), but not actually run them:
    echo implode(";\n", $migrate->sqlDown());

Migrating just the data (useful if you had to add the columns manually because your database platform is not supported):

    // To just print the SQL commands for migrating the data, without ALTERing the table:
    echo implode(";\n", $migrate->sqlUpData());


