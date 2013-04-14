<?php

namespace SimpleUser;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Silex\ControllerProviderInterface;
use Silex\ControllerCollection;
use Silex\ServiceControllerResolver;

class UserServiceProvider implements ServiceProviderInterface, ControllerProviderInterface
{
    /**
     * Registers services on the given app.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Application $app An Application instance
     */
    public function register(Application $app)
    {
        $app['user.manager'] = $app->share(function($app) { return new UserManager($app['db'], $app); });

        $app['user'] = $app->share(function($app) {
            return ($app['user.manager']->getCurrentUser());
        });

        $app['user.controller'] = $app->share(function ($app) {
            return new UserController($app['user.manager']);
        });

        // Add twig template path.
        if ($app->offsetExists('twig.loader.filesystem')) {
            $app['twig.loader.filesystem']->addPath(__DIR__ . '/views/', 'user');
        }
    }

    /**
     * Bootstraps the application.
     *
     * This method is called after all services are registers
     * and should be used for "dynamic" configuration (whenever
     * a service must be requested).
     */
    public function boot(Application $app)
    {
    }


    /**
     * Returns routes to connect to the given application.
     *
     * @param Application $app An Application instance
     *
     * @return ControllerCollection A ControllerCollection instance
     * @throws \LogicException if ServiceController service provider is not registered.
     */
    public function connect(Application $app)
    {
        if (!$app['resolver'] instanceof ServiceControllerResolver) {
            // using RuntimeException crashes PHP?!
            throw new \LogicException('You must enable the ServiceController service provider to be able to use these routes.');
        }

        /** @var ControllerCollection $controllers */
        $controllers = $app['controllers_factory'];

        $controllers->match('/register', 'user.controller:registerAction')->bind('user.register')->method('GET|POST');
        $controllers->get('/', 'user.controller:listAction')->bind('user.list');
        $controllers->get('/{id}', 'user.controller:viewAction')->bind('user.view')->assert('id', '\d+');
        $controllers->match('/{id}/edit', 'user.controller:editAction')->bind('user.edit')->method('GET|POST');

        $controllers->get('/login', 'user.controller:loginAction')->bind('user.login');
        $controllers->match('/reset-password', 'user.controller:resetPasswordAction')->bind('user.reset_password')->method('GET|POST');

        // Dummy routes so we can use the names. The security provider intercepts these so no controller is needed.
        $controllers->match('/login_check', function() {})->bind('user.login_check');
        $controllers->get('/logout', function() {})->bind('user.logout');

        return $controllers;
    }
}
