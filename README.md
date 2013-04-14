Simple user provider for Silex
==============================

A simple database-backed user provider for use with the Silex [SecurityServiceProvider](http://silex.sensiolabs.org/doc/providers/security.html).

In addition to the user provider, this package also includes some simple routes and controllers that can optionally be used for form-based authentication.

Requirements
------------

The simple user provider depends on the [DoctrineServiceProvider](http://silex.sensiolabs.org/doc/providers/doctrine.html).

Enable it something like this:

    use Silex\Provider;

    $app->register(new Provider\DoctrineServiceProvider(), array(
        'db.options' => array(
            'driver'   => 'pdo_mysql',
            'dbname' => $config['db_master']['dbname'],
            'host' => $config['db_master']['host'],
            'user' => $config['db_master']['user'],
            'password' => $config['db_master']['pass'],
        ),
    ));

To use the optional user controller for form-based authentication and user management,
the [Session](http://silex.sensiolabs.org/doc/providers/session.html),
[Service Controller](http://silex.sensiolabs.org/doc/providers/service_controller.html),
[Url Generator](http://silex.sensiolabs.org/doc/providers/url_generator.html),
and [Twig](http://silex.sensiolabs.org/doc/providers/twig.html)
service providers are required.

Enable them like this:

    $app->register(new Provider\SessionServiceProvider()); 
    $app->register(new Provider\ServiceControllerServiceProvider()); 
    $app->register(new Provider\UrlGeneratorServiceProvider()); 
    $app->register(new Provider\TwigServiceProvider()); 

Installation
------------

Add this dependency to your `composer.json` file:

    "jasongrimes/silex-simpleuser": "dev-master"

Create the users database in MySQL (after downloading the package with composer):

    mysql -uUSER -pPASSWORD MYDBNAME < vendor/jasongrimes/sql/mysql.sql

Register the service in your Silex application:

    $app->register(new SimpleUser\UserServiceProvider());

This provides access to the following services:

* `user.manager`: Implements the Symfony Security component's `UserProviderInterface`, along with other user management functions.
* `user`: An instance of `SimpleUser\User` for the "current user", i.e. the user authenticated in the current request. This value is `null` if the user is not logged in.

Configuring the user provider
-----------------------------

    $app->register(new Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'your_firewall_name' => array(

                'users' => $app->share(function($app) { return $app['user.manager']; }),

                // ...
            ),
        ),
    ));

Configuring the optional form-based user routes
-----------------------------------------------

In addition to registering services, the `SimpleUser\UserServiceProvider` can also be used to define routes for logging in and managing users. 

You can mount the user routes like this:

    $app->register($u = new SimpleUser\UserServiceProvider());
    $app->mount('/user', $u);

Then configure the firewall to use the simple user routes for form-based authentication:

    $app->register(new Silex\Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'your_firewall_name' => array(
                'pattern' => '^.*$',
                'anonymous' => true, // Needed as the login path is under the secured area. Could also configure a separate firewall for just the login path.
                'form' => array(
                    'login_path' => '/user/login',
                    'check_path' => '/user/login_check',
                    // 'default_target_path' => '/user/demo', // Redirect here after logging in if no other route was requested. Defaults to '/'
                ),
                'logout' => array(
                    'logout_path' => '/user/logout',
                    // 'target_url' => '/user/demo', // Redirect here after logging out. Defaults to '/'.
                ),
                'users' => $app->share(function($app) { return $app['user.manager']; }),
            ),
        ),
    ));


