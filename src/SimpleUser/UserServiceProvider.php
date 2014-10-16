<?php

namespace SimpleUser;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Silex\ControllerProviderInterface;
use Silex\ControllerCollection;
use Silex\ServiceControllerResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\AuthenticationEvents;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Event\AuthenticationEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use \Swift_Mailer;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\SecurityContextInterface;

class UserServiceProvider implements ServiceProviderInterface, ControllerProviderInterface
{
    protected $warnings = array();

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
        $app['user.options.default'] = array(
            'userClass' => 'SimpleUser\User',

            // Whether to require that users have a username (default: false).
            // By default, users sign in with their email address instead.
            'isUsernameRequired' => false,

            'layoutTemplate'   => '@user/layout.twig',
            'loginTemplate'    => '@user/login.twig',
            'registerTemplate' => '@user/register.twig',
            'registerConfirmationSentTemplate' => '@user/register-confirmation-sent.twig',
            'confirmationNeededTemplate' => '@user/confirmation-needed.twig',
            'viewTemplate'     => '@user/view.twig',
            'editTemplate'     => '@user/edit.twig',
            'listTemplate'     => '@user/list.twig',

            'controllers' => array(
                'edit' => array(
                    'customFields' => array(),
                ),
            ),

            'mailer' => array(
                'enabled' => true, // Set to false to disable sending email notifications.
                'fromEmail' => array(
                    'address' => null,
                    'name' => null,
                ),
            ),

            'emailConfirmation' => array(
                'required' => false, // Whether to require email confirmation before enabling new accounts.
                'template' => '@user/email/confirm-email.twig',
            ),

            // Password reset options.
            'passwordReset' => array(
                'template' => '@user/email/reset-password.twig',
                'tokenTTL' => 86400, // How many seconds the reset token is valid for. Default: 1 day.
            ),
        );

        $app['user.options.init'] = $app->protect(function() use ($app) {
            $options = $app['user.options.default'];
            if (isset($app['user.options'])) {
                $options = array_replace_recursive($options, $app['user.options']);
            }
            $app['user.options'] = $options;
        });

        $app['user.tokenGenerator'] = $app->share(function($app) { return new TokenGenerator($app['logger']); });

        $app['user.manager'] = $app->share(function($app) {
            $app['user.options.init']();

            $userManager = new UserManager($app['db'], $app);
            $userManager->setUserClass($app['user.options']['userClass']);
            $userManager->setUsernameRequired($app['user.options']['isUsernameRequired']);

            return $userManager;
        });

        $app['user'] = $app->share(function($app) {
            return ($app['user.manager']->getCurrentUser());
        });

        $app['user.controller'] = $app->share(function ($app) {
            $app['user.options.init']();

            $controller = new UserController($app['user.manager'], $app['user.options']);
            $controller->setEmailConfirmationRequired($app['user.options']['emailConfirmation']['required']);

            return $controller;
        });

        $app['user.mailer'] = $app->share(function($app) use (&$warning) {
            $app['user.options.init']();

            $missingDeps = array();
            if (!isset($app['mailer'])) $missingDeps[] = 'SwiftMailerServiceProvider';
            if (!isset($app['url_generator'])) $missingDeps[] = 'UrlGeneratorServiceProvider';
            if (!isset($app['twig'])) $missingDeps[] = 'TwigServiceProvider';
            if (!empty($missingDeps)) {
                throw new \RuntimeException('To access the SimpleUser mailer you must enable the following missing dependencies: ' . implode(', ', $missingDeps));
            }

            $mailer = new Mailer($app['mailer'], $app['url_generator'], $app['twig']);
            $mailer->setFromAddress($app['user.options']['mailer']['fromEmail']['address'] ?: null);
            $mailer->setFromName($app['user.options']['mailer']['fromEmail']['name'] ?: null);
            $mailer->setConfirmationTemplate($app['user.options']['emailConfirmation']['template']);
            $mailer->setResetTemplate($app['user.options']['passwordReset']['template']);
            $mailer->setResetTokenTtl($app['user.options']['passwordReset']['tokenTTL']);
            if (!$app['user.options']['mailer']['enabled']) {
                $mailer->setNoSend(true);
            }

            return $mailer;
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
        if (isset($app['twig.loader.filesystem'])) {
            $app['twig.loader.filesystem']->addPath(__DIR__ . '/views/', 'user');
        }

        $app['user.options.init']();
        if ($app['user.options']['emailConfirmation']['required']) {
            if (!$app['user.mailer']) {
                throw new \RuntimeException('Invalid configuration. Cannot require email confirmation because user mailer is not available.');
            }
            if (!$app['user.options']['mailer']['fromEmail']['address']) {
                throw new \RuntimeException('Invalid configuration. Mailer fromEmail address is required when email confirmation is required.');
            }
        }

        // Get the last authentication exception thrown for the given request.
        // A replacement for $app['security.last_error']
        // Exactly the same except it returns the whole exception instead of just $exception->getMessage()
        $app['user.last_auth_exception'] = $app->protect(function (Request $request) {
            if ($request->attributes->has(SecurityContextInterface::AUTHENTICATION_ERROR)) {
                return $request->attributes->get(SecurityContextInterface::AUTHENTICATION_ERROR);
            }

            $session = $request->getSession();
            if ($session && $session->has(SecurityContextInterface::AUTHENTICATION_ERROR)) {
                $error = $session->get(SecurityContextInterface::AUTHENTICATION_ERROR);
                $session->remove(SecurityContextInterface::AUTHENTICATION_ERROR);

                return $error;
            }
        });
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

        $controllers->get('/confirm-email/{token}', 'user.controller:confirmEmailAction')
            ->bind('user.confirm-email');

        $controllers->post('/resend-confirmation', 'user.controller:resendConfirmationAction')
            ->bind('user.resend-confirmation');

        // login_check and logout are dummy routes so we can use the names.
        // The security provider should intercept these, so no controller is needed.
        $controllers->method('GET|POST')->match('/login_check', function() {})
            ->bind('user.login_check');
        $controllers->get('/logout', function() {})
            ->bind('user.logout');

        return $controllers;
    }
}
