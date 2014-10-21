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
use Symfony\Component\Security\Core\SecurityContextInterface;

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
        // Default options.
        $app['user.options.default'] = array(

            // Specify custom view templates here.
            'templates' => array(
                'layout' => '@user/layout.twig',
                'register' => '@user/register.twig',
                'register-confirmation-sent' => '@user/register-confirmation-sent.twig',
                'login' => '@user/login.twig',
                'login-confirmation-needed' => '@user/login-confirmation-needed.twig',
                'forgot-password' => '@user/forgot-password.twig',
                'reset-password' => '@user/reset-password.twig',
                'view' => '@user/view.twig',
                'edit' => '@user/edit.twig',
                'list' => '@user/list.twig',
            ),

            // Configure the user mailer for sending password reset and email confirmation messages.
            'mailer' => array(
                'enabled' => true, // When false, email notifications are not sent (they're silently discarded).
                'fromEmail' => array(
                    'address' => 'do-not-reply@' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : gethostname()),
                    'name' => null,
                ),
            ),

            'emailConfirmation' => array(
                'required' => false, // Whether to require email confirmation before enabling new accounts.
                'template' => '@user/email/confirm-email.twig',
            ),

            'passwordReset' => array(
                'template' => '@user/email/reset-password.twig',
                'tokenTTL' => 86400, // How many seconds the reset token is valid for. Default: 1 day.
            ),

            // Set this to use a custom User class.
            'userClass' => 'SimpleUser\User',

            // Whether to require that users have a username (default: false).
            // By default, users sign in with their email address instead.
            'isUsernameRequired' => false,

            // A list of custom fields to support in the edit controller.
            'editCustomFields' => array(),

            // Override table names, if necessary.
            'userTableName' => 'users',
            'userCustomFieldsTableName' => 'user_custom_fields',
        );

        // Initialize $app['user.options'].
        $app['user.options.init'] = $app->protect(function() use ($app) {
            $options = $app['user.options.default'];
            if (isset($app['user.options'])) {
                // Merge default and configured options
                $options = array_replace_recursive($options, $app['user.options']);

                // Migrate deprecated options for backward compatibility
                if (isset($app['user.options']['layoutTemplate']) && !isset($app['user.options']['templates']['layout'])) {
                    $options['templates']['layout'] = $app['user.options']['layoutTemplate'];
                }
                if (isset($app['user.options']['loginTemplate']) && !isset($app['user.options']['templates']['login'])) {
                    $options['templates']['login'] = $app['user.options']['loginTemplate'];
                }
                if (isset($app['user.options']['registerTemplate']) && !isset($app['user.options']['templates']['register'])) {
                    $options['templates']['register'] = $app['user.options']['registerTemplate'];
                }
                if (isset($app['user.options']['viewTemplate']) && !isset($app['user.options']['templates']['view'])) {
                    $options['templates']['view'] = $app['user.options']['viewTemplate'];
                }
                if (isset($app['user.options']['editTemplate']) && !isset($app['user.options']['templates']['edit'])) {
                    $options['templates']['edit'] = $app['user.options']['editTemplate'];
                }
                if (isset($app['user.options']['listTemplate']) && !isset($app['user.options']['templates']['list'])) {
                    $options['templates']['list'] = $app['user.options']['listTemplate'];
                }
            }
            $app['user.options'] = $options;
        });

        // Token generator.
        $app['user.tokenGenerator'] = $app->share(function($app) { return new TokenGenerator($app['logger']); });

        // User manager.
        $app['user.manager'] = $app->share(function($app) {
            $app['user.options.init']();

            $userManager = new UserManager($app['db'], $app);
            $userManager->setUserClass($app['user.options']['userClass']);
            $userManager->setUsernameRequired($app['user.options']['isUsernameRequired']);
            $userManager->setUserTableName($app['user.options']['userTableName']);
            $userManager->setUserCustomFieldsTableName($app['user.options']['userCustomFieldsTableName']);

            return $userManager;
        });

        // Current user.
        $app['user'] = $app->share(function($app) {
            return ($app['user.manager']->getCurrentUser());
        });

        // User controller service.
        $app['user.controller'] = $app->share(function ($app) {
            $app['user.options.init']();

            $controller = new UserController($app['user.manager']);
            $controller->setUsernameRequired($app['user.options']['isUsernameRequired']);
            $controller->setEmailConfirmationRequired($app['user.options']['emailConfirmation']['required']);
            $controller->setTemplates($app['user.options']['templates']);
            $controller->setEditCustomFields($app['user.options']['editCustomFields']);

            return $controller;
        });

        // User mailer.
        $app['user.mailer'] = $app->share(function($app) {
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

        // Helper function to get the last authentication exception thrown for the given request.
        // It does the same thing as $app['security.last_error'](),
        // except it returns the whole exception instead of just $exception->getMessage()
        $app['user.last_auth_exception'] = $app->protect(function (Request $request) {
            if ($request->attributes->has(SecurityContextInterface::AUTHENTICATION_ERROR)) {
                return $request->attributes->get(SecurityContextInterface::AUTHENTICATION_ERROR);
            }

            $session = $request->getSession();
            if ($session && $session->has(SecurityContextInterface::AUTHENTICATION_ERROR)) {
                $exception = $session->get(SecurityContextInterface::AUTHENTICATION_ERROR);
                $session->remove(SecurityContextInterface::AUTHENTICATION_ERROR);

                return $exception;
            }
        });
    }

    /**
     * Bootstraps the application.
     *
     * This method is called after all services are registered
     * and should be used for "dynamic" configuration (whenever
     * a service must be requested).
     */
    public function boot(Application $app)
    {
        // Add twig template path.
        if (isset($app['twig.loader.filesystem'])) {
            $app['twig.loader.filesystem']->addPath(__DIR__ . '/views/', 'user');
        }

        // Validate the mailer configuration.
        $app['user.options.init']();
        try {
            $mailer = $app['user.mailer'];
            $mailerExists = true;
        } catch (\RuntimeException $e) {
            $mailerExists = false;
            $mailerError = $e->getMessage();
        }
        if ($app['user.options']['emailConfirmation']['required'] && !$mailerExists) {
            throw new \RuntimeException('Invalid configuration. Cannot require email confirmation because user mailer is not available. ' . $mailerError);
        }
        if ($app['user.options']['mailer']['enabled'] && !$app['user.options']['mailer']['fromEmail']['address']) {
            throw new \RuntimeException('Invalid configuration. Mailer fromEmail address is required when mailer is enabled.');
        }
        if (!$mailerExists) {
            $app['user.controller']->setPasswordResetEnabled(false);
        }

        if (isset($app['user.passwordStrengthValidator'])) {
            $app['user.manager']->setPasswordStrengthValidator($app['user.passwordStrengthValidator']);
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

        $controllers->get('/confirm-email/{token}', 'user.controller:confirmEmailAction')
            ->bind('user.confirm-email');

        $controllers->post('/resend-confirmation', 'user.controller:resendConfirmationAction')
            ->bind('user.resend-confirmation');

        $controllers->get('/login', 'user.controller:loginAction')
            ->bind('user.login');

        $controllers->method('GET|POST')->match('/forgot-password', 'user.controller:forgotPasswordAction')
            ->bind('user.forgot-password');

        $controllers->get('/reset-password/{token}', 'user.controller:resetPasswordAction')
            ->bind('user.reset-password');

        // login_check and logout are dummy routes so we can use the names.
        // The security provider should intercept these, so no controller is needed.
        $controllers->method('GET|POST')->match('/login_check', function() {})
            ->bind('user.login_check');
        $controllers->get('/logout', function() {})
            ->bind('user.logout');

        return $controllers;
    }
}
