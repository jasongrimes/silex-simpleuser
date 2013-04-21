<?php

namespace SimpleUser;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Silex\ControllerProviderInterface;
use Silex\ControllerCollection;
use Silex\ServiceControllerResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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

        // Add a custom security voter to support testing user attributes.
        $app['security.voters'] = $app->extend('security.voters', function($voters) use ($app) {
            foreach ($voters as $voter) {
                if ($voter instanceof RoleHierarchyVoter) {
                    $roleHierarchyVoter = $voter;
                    break;
                }
            }
            $voters[] = new EditUserVoter($roleHierarchyVoter);
            return $voters;
        });

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
        // Add twig template path.
        if ($app->offsetExists('twig.loader.filesystem')) {
            $app['twig.loader.filesystem']->addPath(__DIR__ . '/views/', 'user');
        }

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

        $controllers->get('/', 'user.controller:viewSelfAction')
            ->bind('user')
            ->before(function(Request $request) use ($app) {
                // Require login. This should never actually cause access to be denied,
                // but it causes a login form to be rendered if the viewer is not logged in.
                if (!$app['user']) {
                    throw new AccessDeniedException();
                }
            });

        $controllers->get('/{id}', 'user.controller:viewAction')
            ->bind('user.view')
            ->assert('id', '\d+');

        $controllers->method('GET|POST')->match('/{id}/edit', 'user.controller:editAction')
            ->bind('user.edit')
            ->before(function(Request $request) use ($app) {
                if (!$app['security']->isGranted('EDIT_USER_ID', $request->get('id'))) {
                    throw new AccessDeniedException();
                }
            });

        $controllers->get('/list', 'user.controller:listAction')
            ->bind('user.list');

        $controllers->method('GET|POST')->match('/register', 'user.controller:registerAction')
            ->bind('user.register');

        $controllers->get('/login', 'user.controller:loginAction')
            ->bind('user.login');

        // login_check and logout are dummy routes so we can use the names.
        // The security provider should intercept these, so no controller is needed.
        $controllers->method('GET|POST')->match('/login_check', function() {})
            ->bind('user.login_check');
        $controllers->get('/logout', function() {})
            ->bind('user.logout');

        return $controllers;
    }
}
