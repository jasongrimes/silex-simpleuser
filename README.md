Simple user provider for Silex
==============================

A simple database-backed user provider for use with the Silex [SecurityServiceProvider](http://silex.sensiolabs.org/doc/providers/security.html).

In addition to the user provider, this package also includes a controller provider that can optionally set up simple routes and controllers for form-based authentication.

Overview
--------

SimpleUser is intended to be an easy way to get up and running with user authentication in the Silex PHP microframework.
Silex has built-in support for the Symfony 2 Security component, which is powerful,
but requires writing a lot of boilerplate user management code before it can be used.
SimpleUser provides a simple implementation of this missing user management piece for the Security component.

If your Silex application just needs a user authentication layer with a minimal user model,
SimpleUser may work fine for you as-is.
If you have more complex requirements, you may want to extend the SimpleUser classes,
or you may prefer to fork the project and use it as a reference implementation.
You should feel free to do either one (this is open source software under the BSD license).

The SimpleUser package provides the following features:

* A minimal `User` class which basically consists of an email, password, optional name, and some housekeeping.
* A `UserManager` class for managing `User` objects and their persistence in an SQL database. It serves as a user provider for the Security component.
* A `user` service for accessing the currently logged in user.
* A `UserController` and views for for handling form-based authentication and user management.
* An `EditUserVoter` class which provides security attributes (access control privileges) for managing users.
* A Silex service provider and controller provider for automatically configuring the features above.

Requirements
------------

SimpleUser depends on the [DoctrineServiceProvider](http://silex.sensiolabs.org/doc/providers/doctrine.html).
(This provides a basic DBAL--database abstraction layer--not the full Doctrine 2 ORM.)

In addition, if you want to use the optional controller provider to set up simple routes for form-based authentication and user management,
the [Session](http://silex.sensiolabs.org/doc/providers/session.html),
[Service Controller](http://silex.sensiolabs.org/doc/providers/service_controller.html),
[Url Generator](http://silex.sensiolabs.org/doc/providers/url_generator.html),
and [Twig](http://silex.sensiolabs.org/doc/providers/twig.html) service providers are also required.

These all come with the stock Silex distribution except for Twig, which must be added as a dependency in `composer.json` like this:

    "require": {
        "symfony/twig-bridge": "~2.1"
    }

Enable Doctrine something like this:

    use Silex\Provider;

    $app->register(new Provider\DoctrineServiceProvider(), array('db.options' => $config['db']));

Enable the additional service providers like this:

    $app->register(new Provider\SessionServiceProvider()); 
    $app->register(new Provider\ServiceControllerServiceProvider()); 
    $app->register(new Provider\UrlGeneratorServiceProvider()); 
    $app->register(new Provider\TwigServiceProvider());

Installing SimpleUser
---------------------

Add this dependency to your `composer.json` file:

    "jasongrimes/silex-simpleuser": "*"

Create the users database in MySQL (after downloading the package with composer):

    mysql -uUSER -pPASSWORD MYDBNAME < vendor/jasongrimes/sql/mysql.sql

Register the service in your Silex application:


    $userServiceProvider = new SimpleUser\UserServiceProvider();
    $app->register($userServiceProvider);

The following services will now be available:

* `user.manager`: A service for managing User instances.
* `user`: A User instance representing the currently authenticated user (or `null` if the user is not logged in).
* `user.controller`: A controller with actions for handling user management routes. See "Using the controller provider" below.

Configuring the Security service to use the SimpleUser user provider
--------------------------------------------------------------------

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

    // Register SimpleUser services.
    $userServiceProvider = new SimpleUser\UserServiceProvider();
    $app->register($userServiceProvider);

    // Mount SimpleUser routes.
    $app->mount('/user', $userServiceProvider);

The following routes are provided. (In this example they are mounted under `/user`, but that can be changed by altering the `mount()` parameter above.)

* `/user/login` (route name: `user.login`): The login form.
* `/user/login_check` (route name: `user.login_check`): Process the login submission. The login form POSTs here.
* `/user/logout` (route name: `user.logout`): Log out the current user.
* `/user/register` (route name: `user.register`): Form to create a new user.
* `/user` (route name: `user`): View the profile of the current user.
* `/user/{id}` (route name: `user.view`): View a user profile.
* `/user/{id}/edit` (route name: `user.edit`): Edit a user.
* `/user/list` (route name: `user.list`): List users.

Configure the firewall to use these routes for form-based authentication. (Replace `/user` with whatever mount point you used in `mount()` above).

    $app->register(new Silex\Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'your_firewall_name' => array(
                'pattern' => '^.*$',
                'anonymous' => true,
                'form' => array(
                    'login_path' => '/user/login',
                    'check_path' => '/user/login_check',
                ),
                'logout' => array(
                    'logout_path' => '/user/logout',
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

