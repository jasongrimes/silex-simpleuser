Simple user provider for Silex
==============================

[![Build Status](https://travis-ci.org/jasongrimes/silex-simpleuser.svg?branch=master)](https://travis-ci.org/jasongrimes/silex-simpleuser)
[![Total Downloads](https://poser.pugx.org/jasongrimes/silex-simpleuser/downloads.svg)](https://packagist.org/packages/jasongrimes/silex-simpleuser)
[![Latest Stable Version](https://poser.pugx.org/jasongrimes/silex-simpleuser/v/stable.svg)](https://packagist.org/packages/jasongrimes/silex-simpleuser)
[![Latest Unstable Version](https://poser.pugx.org/jasongrimes/silex-simpleuser/v/unstable.svg)](https://packagist.org/packages/jasongrimes/silex-simpleuser)

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

* A minimal `User` class which basically consists of an email, password, optional name, and support for custom fields.
* A `UserManager` class for managing `User` objects and their persistence in an SQL database. It serves as a user provider for the Security component.
* A controller and views for optionally handling form-based authentication and user management.
* An `EDIT_USER` security attribute that can be used with the Security component's `isGranted()` method to allow users to edit their own accounts.
* A Silex service provider and controller provider for automatically configuring the features above.

Quick start example config
--------------------------

This configuration should work out of the box to get you up and running quickly. See below for additional details.

Add this to your composer.json and then run `composer update`:

    "require": {
        "silex/silex": "~1.0",
        "symfony/twig-bridge": "~2.3",
        "jasongrimes/silex-simpleuser": "~1.0"
    }

Set up your Silex application something like this:


    <?php

    use Silex\Application;
    use Silex\Provider;

    //
    // Application setup
    //

    $app = new Application();
    $app->register(new Provider\DoctrineServiceProvider());
    $app->register(new Provider\SecurityServiceProvider());
    $app->register(new Provider\RememberMeServiceProvider());
    $app->register(new Provider\SessionServiceProvider());
    $app->register(new Provider\ServiceControllerServiceProvider());
    $app->register(new Provider\UrlGeneratorServiceProvider());
    $app->register(new Provider\TwigServiceProvider());

    // Register the SimpleUser service provider.
    $simpleUserProvider = new SimpleUser\UserServiceProvider();
    $app->register($simpleUserProvider);

    // ...

    //
    // Controllers
    //

    $app->mount('/user', $simpleUserProvider);

    $app->get('/', function () use ($app) {
        return $app['twig']->render('index.twig', array());
    });

    // ...

    //
    // Configuration
    //

    $app['user.options'] = array();

    $app['security.firewalls'] = array(
        // Ensure that the login page is accessible to all
        'login' => array(
            'pattern' => '^/user/login$',
        ),
        'secured_area' => array(
            'pattern' => '^.*$',
            'anonymous' => true,
            'remember_me' => array(),
            'form' => array(
                'login_path' => '/user/login',
                'check_path' => '/user/login_check',
            ),
            'logout' => array(
                'logout_path' => '/user/logout',
            ),
            'users' => $app->share(function($app) { return $app['user.manager']; }),
        ),
    );

    $app['db.options'] = array(
        'driver'   => 'pdo_mysql',
        'host' => 'localhost',
        'dbname' => 'mydbname',
        'user' => 'mydbuser',
        'password' => 'mydbpassword',
    );

    return $app;

Create the user database:

    mysql -uUSER -pPASSWORD MYDBNAME < vendor/jasongrimes/silex-simpleuser/sql/mysql.sql

You should now be able to create an account at the `/user/register` URL.
Make the new account an administrator by editing the record directly in the database and setting the `users.roles` column to `ROLE_USER,ROLE_ADMIN`.
(After you have one admin account, it can grant the admin role to others via the web interface.)

Config options
--------------

    $app['user.options'] = array(
        // Custom user class
        'userClass' => 'My\User',

        // Custom templates
        'layoutTemplate'   => 'layout.twig',
        'loginTemplate'    => 'login.twig',
        'registerTemplate' => 'register.twig',
        'viewTemplate'     => 'view.twig',
        'editTemplate'     => 'edit.twig',
        'listTemplate'     => 'list.twig',

        // Controller options
        'controllers' => array(
            'edit' => array(
                'customFields' => array('field' => 'Label'),
            ),
        ),
    );

More information
----------------

For more information, see the [Silex SimpleUser tutorial](http://jasongrimes.org/?p=678).

