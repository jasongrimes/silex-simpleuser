Simple user provider for Silex
==============================

A simple database-backed user provider for use with the Silex [SecurityServiceProvider](http://silex.sensiolabs.org/doc/providers/security.html).

In addition to the user provider, this package also includes a controller provider that can optionally set up simple routes and controllers for form-based authentication.

Requirements
------------

This service depends on Doctrine DBAL from the [DoctrineServiceProvider](http://silex.sensiolabs.org/doc/providers/doctrine.html).

Enable Doctrine something like this:

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

Some additional providers are required if you want to use the optional controller provider
to set up simple routes and controllers for form-based authentication and user management:
[Session](http://silex.sensiolabs.org/doc/providers/session.html),
[Service Controller](http://silex.sensiolabs.org/doc/providers/service_controller.html),
[Url Generator](http://silex.sensiolabs.org/doc/providers/url_generator.html),
and [Twig](http://silex.sensiolabs.org/doc/providers/twig.html).

Enable them like this:

    $app->register(new Provider\SessionServiceProvider()); 
    $app->register(new Provider\ServiceControllerServiceProvider()); 
    $app->register(new Provider\UrlGeneratorServiceProvider()); 
    $app->register(new Provider\TwigServiceProvider()); 

Installation
------------

Add this dependency to your `composer.json` file:

    "jasongrimes/silex-simpleuser": "~0.3"

Create the users database in MySQL (after downloading the package with composer):

    mysql -uUSER -pPASSWORD MYDBNAME < vendor/jasongrimes/sql/mysql.sql

Register the service in your Silex application:

    $app->register(new SimpleUser\UserServiceProvider());

This provides access to the following services:

* `user.manager`: Implements the Symfony Security component's `UserProviderInterface`, along with other user management functions.
* `user`: A `SimpleUser\User` instance for the user authenticated in the current request, or `null` if the user is not logged in.

Configuring the user provider
-----------------------------

To configure the Silex security service to use the `SimpleUser\UserManager` as its user provider, 
set the `users` key to the `user.manager` service like this:

    $app->register(new Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'your_firewall_name' => array(

                'users' => $app->share(function($app) { return $app['user.manager']; }),
                // ...
            ),
        ),
    ));

Using the controller provider
-----------------------------

In addition to registering services, the `SimpleUser\UserServiceProvider` also acts as a controller provider. 
It defines some routes that can be used for logging in and managing users.

You can mount the user routes like this:

    $app->register($u = new SimpleUser\UserServiceProvider());
    $app->mount('/user', $u);

The following routes are provided. (In this example they are mounted under `/user`, but that can be changed by altering the `mount()` parameter above.)

* `GET /user/login` (route name: `user.login`): The login form.
* `POST /user/login_check` (route name: `user.login_check`): Process the login submission. The login form POSTs here.
* `GET /user/logout` (route name: `user.logout`): Log out the current user.
* `GET|POST /user/register` (route name: `user.register`): Form to create a new user.
* `GET /user/{id}` (route name: `user.view`): View a user.
* `GET|POST /user/{id}/edit` (route name: `user.edit`): Edit a user.

Configure the firewall to use these routes for form-based authentication. (Replace `/user` with whatever mount point you used in `mount()` above).

    $app->register(new Silex\Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'your_firewall_name' => array(
                'pattern' => '^.*$',
                'anonymous' => true,
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

Access control
--------------

The `SimpleUser\UserServiceProvider` sets up custom access control attributes for testing whether the viewer can edit a user.

* `EDIT_USER`: Whether the current user is allowed to edit the given user object.
* `EDIT_USER_ID`: Whether the currently authenticated user is allowed to edit the user with the given user ID. Useful for controlling access in `before()` middlewares.

By default, users can edit their own user account, and those with `ROLE_ADMIN` can edit any user.
Override `SimpleUser\EditUserVoter` to change these privileges.

In a controller, control access like this:

    // If a User instance is available
    if ($app['security']->isGranted('EDIT_USER', $user)) { ... }

    // Control access in a before() middleware
    ...
    ->before(function(Request $request) use ($app) {
        if (!$app['security']->isGranted('EDIT_USER_ID', $request->get('id')) { 
            throw new AccessDeniedException();
        }
    });

In a Twig template, use them like this:

    {% if is_granted('EDIT_USER', user) %}
        ...
    {% endif %}


