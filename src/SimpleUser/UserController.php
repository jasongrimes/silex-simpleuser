<?php

namespace SimpleUser;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use InvalidArgumentException;
use JasonGrimes\Paginator;

/**
 * Controller with actions for handling form-based authentication and user management.
 *
 * @package SimpleUser
 */
class UserController
{
    /** @var UserManager */
    protected $userManager;

    protected $templates = array(
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
    );

    // Custom fields to support in the editAction().
    protected $editCustomFields = array();

    protected $isUsernameRequired = false;
    protected $isEmailConfirmationRequired = false;
    protected $isPasswordResetEnabled = true;

    /**
     * Constructor.
     *
     * @param UserManager $userManager
     * @param array $deprecated - Deprecated. No longer used.
     */
    public function __construct(UserManager $userManager, $deprecated = null)
    {
        $this->userManager = $userManager;
    }

    /**
     * @param string $key
     * @param string $template
     */
    public function setTemplate($key, $template)
    {
        $this->templates[$key] = $template;
    }

    /**
     * @param array $templates
     */
    public function setTemplates(array $templates)
    {
        foreach ($templates as $key => $val) {
            $this->setTemplate($key, $val);
        }
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function getTemplate($key)
    {
        return $this->templates[$key];
    }

    /**
     * Register action.
     *
     * @param Application $app
     * @param Request $request
     * @return Response
     */
    public function registerAction(Application $app, Request $request)
    {
        if ($request->isMethod('POST')) {
            try {
                $user = $this->createUserFromRequest($request);
                if ($error = $this->userManager->validatePasswordStrength($user, $request->request->get('password'))) {
                    throw new InvalidArgumentException($error);
                }
                if ($this->isEmailConfirmationRequired) {
                    $user->setEnabled(false);
                    $user->setConfirmationToken($app['user.tokenGenerator']->generateToken());
                }
                $this->userManager->insert($user);

                if ($this->isEmailConfirmationRequired) {
                    // Send email confirmation.
                    $app['user.mailer']->sendConfirmationMessage($user);

                    // Render the "go check your email" page.
                    return $app['twig']->render($this->getTemplate('register-confirmation-sent'), array(
                        'layout_template' => $this->getTemplate('layout'),
                        'email' => $user->getEmail(),
                    ));
                } else {
                    // Log the user in to the new account.
                    $this->userManager->loginAsUser($user);

                    $app['session']->getFlashBag()->set('alert', 'Account created.');

                    // Redirect to user's new profile page.
                    return $app->redirect($app['url_generator']->generate('user.view', array('id' => $user->getId())));
                }

            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $app['twig']->render($this->getTemplate('register'), array(
            'layout_template' => $this->getTemplate('layout'),
            'error' => isset($error) ? $error : null,
            'name' => $request->request->get('name'),
            'email' => $request->request->get('email'),
            'username' => $request->request->get('username'),
            'isUsernameRequired' => $this->isUsernameRequired,
        ));
    }

    /**
     * Action to handle email confirmation links.
     *
     * @param Application $app
     * @param Request $request
     * @param string $token
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function confirmEmailAction(Application $app, Request $request, $token)
    {
        $user = $this->userManager->findOneBy(array('confirmationToken' => $token));
        if (!$user) {
            $app['session']->getFlashBag()->set('alert', 'Sorry, your email confirmation link has expired.');

            return $app->redirect($app['url_generator']->generate('user.login'));
        }

        $user->setConfirmationToken(null);
        $user->setEnabled(true);
        $this->userManager->update($user);

        $this->userManager->loginAsUser($user);

        $app['session']->getFlashBag()->set('alert', 'Thank you! Your account has been activated.');

        return $app->redirect($app['url_generator']->generate('user.view', array('id' => $user->getId())));
    }

    /**
     * Login action.
     *
     * @param Application $app
     * @param Request $request
     * @return Response
     */
    public function loginAction(Application $app, Request $request)
    {
        $authException = $app['user.last_auth_exception']($request);

        if ($authException instanceof DisabledException) {
            // This exception is thrown if (!$user->isEnabled())
            // Warning: Be careful not to disclose any user information besides the email address at this point.
            // The Security system throws this exception before actually checking if the password was valid.
            $user = $this->userManager->refreshUser($authException->getUser());

            return $app['twig']->render($this->getTemplate('login-confirmation-needed'), array(
                'layout_template' => $this->getTemplate('layout'),
                'email' => $user->getEmail(),
                'fromAddress' => $app['user.mailer']->getFromAddress(),
                'resendUrl' => $app['url_generator']->generate('user.resend-confirmation'),
            ));
        }

        return $app['twig']->render($this->getTemplate('login'), array(
            'layout_template' => $this->getTemplate('layout'),
            'error' => $authException ? $authException->getMessageKey() : null,
            'last_username' => $app['session']->get('_security.last_username'),
            'allowRememberMe' => isset($app['security.remember_me.response_listener']),
            'allowPasswordReset' => $this->isPasswordResetEnabled(),
        ));
    }

    /**
     * Action to resend an email confirmation message.
     *
     * @param Application $app
     * @param Request $request
     * @return mixed
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function resendConfirmationAction(Application $app, Request $request)
    {
        $email = $request->request->get('email');
        $user = $this->userManager->findOneBy(array('email' => $email));
        if (!$user) {
            throw new NotFoundHttpException('No user account was found with that email address.');
        }

        if (!$user->getConfirmationToken()) {
            $user->setConfirmationToken($app['user.tokenGenerator']->generateToken());
            $this->userManager->update($user);
        }

        $app['user.mailer']->sendConfirmationMessage($user);

        // Render the "go check your email" page.
        return $app['twig']->render($this->getTemplate('register-confirmation-sent'), array(
            'layout_template' => $this->getTemplate('layout'),
            'email' => $user->getEmail(),
        ));
    }

    /**
     * @param Application $app
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function forgotPasswordAction(Application $app, Request $request)
    {
        if (!$this->isPasswordResetEnabled()) {
            throw new NotFoundHttpException('Password resetting is not enabled.');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $user = $this->userManager->findOneBy(array('email' => $email));
            if ($user) {
                // Initialize and send the password reset request.
                $user->setTimePasswordResetRequested(time());
                if (!$user->getConfirmationToken()) {
                    $user->setConfirmationToken($app['user.tokenGenerator']->generateToken());
                }
                $this->userManager->update($user);

                $app['user.mailer']->sendResetMessage($user);
                $app['session']->getFlashBag()->set('alert', 'Instructions for resetting your password have been emailed to you.');
                $app['session']->set('_security.last_username', $email);

                return $app->redirect($app['url_generator']->generate('user.login'));
            }
            $error = 'No user account was found with that email address.';

        } else {
            $email = $request->request->get('email') ?: ($request->query->get('email') ?: $app['session']->get('_security.last_username'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        }

        return $app['twig']->render($this->getTemplate('forgot-password'), array(
            'layout_template' => $this->getTemplate('layout'),
            'email' => $email,
            'fromAddress' => $app['user.mailer']->getFromAddress(),
            'error' => $error,
        ));
    }

    /**
     * @param Application $app
     * @param Request $request
     * @param string $token
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function resetPasswordAction(Application $app, Request $request, $token)
    {
        if (!$this->isPasswordResetEnabled()) {
            throw new NotFoundHttpException('Password resetting is not enabled.');
        }

        $tokenExpired = false;

        $user = $this->userManager->findOneBy(array('confirmationToken' => $token));
        if (!$user) {
            $tokenExpired = true;
        } else if ($user->isPasswordResetRequestExpired($app['user.options']['passwordReset']['tokenTTL'])) {
            $tokenExpired = true;
        }

        if ($tokenExpired) {
            $app['session']->getFlashBag()->set('alert', 'Sorry, your password reset link has expired.');
            return $app->redirect($app['url_generator']->generate('user.login'));
        }

        $error = '';
        if ($request->isMethod('POST')) {
            // Validate the password
            $password = $request->request->get('password');
            if ($password != $request->request->get('confirm_password')) {
                $error = 'Passwords don\'t match.';
            } else if ($error = $this->userManager->validatePasswordStrength($user, $password)) {
                ;
            } else {
                // Set the password and log in.
                $this->userManager->setUserPassword($user, $password);
                $user->setConfirmationToken(null);
                $user->setEnabled(true);
                $this->userManager->update($user);

                $this->userManager->loginAsUser($user);

                $app['session']->getFlashBag()->set('alert', 'Your password has been reset and you are now signed in.');

                return $app->redirect($app['url_generator']->generate('user.view', array('id' => $user->getId())));
            }
        }

        return $app['twig']->render($this->getTemplate('reset-password'), array(
            'layout_template' => $this->getTemplate('layout'),
            'user' => $user,
            'token' => $token,
            'error' => $error,
        ));
    }

    /**
     * @param Request $request
     * @return User
     * @throws InvalidArgumentException
     */
    protected function createUserFromRequest(Request $request)
    {
        if ($request->request->get('password') != $request->request->get('confirm_password')) {
            throw new InvalidArgumentException('Passwords don\'t match.');
        }

        $user = $this->userManager->createUser(
            $request->request->get('email'),
            $request->request->get('password'),
            $request->request->get('name') ?: null);

        if ($username = $request->request->get('username')) {
            $user->setUsername($username);
        }

        $errors = $this->userManager->validate($user);
        if (!empty($errors)) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return $user;
    }

    /**
     * View user action.
     *
     * @param Application $app
     * @param Request $request
     * @param int $id
     * @return Response
     * @throws NotFoundHttpException if no user is found with that ID.
     */
    public function viewAction(Application $app, Request $request, $id)
    {
        $user = $this->userManager->getUser($id);

        if (!$user) {
            throw new NotFoundHttpException('No user was found with that ID.');
        }

        if (!$user->isEnabled() && !$app['security']->isGranted('ROLE_ADMIN')) {
            throw new NotFoundHttpException('That user is disabled (pending email confirmation).');
        }

        return $app['twig']->render($this->getTemplate('view'), array(
            'layout_template' => $this->getTemplate('layout'),
            'user' => $user,
            'imageUrl' => $this->getGravatarUrl($user->getEmail()),
        ));

    }

    /**
     * @param Application $app
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function viewSelfAction(Application $app) {
        if (!$app['user']) {
            return $app->redirect($app['url_generator']->generate('user.login'));
        }

        return $app->redirect($app['url_generator']->generate('user.view', array('id' => $app['user']->getId())));
    }

    /**
     * @param string $email
     * @param int $size
     * @return string
     */
    protected function getGravatarUrl($email, $size = 80)
    {
        // See https://en.gravatar.com/site/implement/images/ for available options.
        return '//www.gravatar.com/avatar/' . md5(strtolower(trim($email))) . '?s=' . $size . '&d=identicon';
    }

    /**
     * Edit user action.
     *
     * @param Application $app
     * @param Request $request
     * @param int $id
     * @return Response
     * @throws NotFoundHttpException if no user is found with that ID.
     */
    public function editAction(Application $app, Request $request, $id)
    {
        $errors = array();

        $user = $this->userManager->getUser($id);
        if (!$user) {
            throw new NotFoundHttpException('No user was found with that ID.');
        }

        $customFields = $this->editCustomFields ?: array();

        if ($request->isMethod('POST')) {
            $user->setName($request->request->get('name'));
            $user->setEmail($request->request->get('email'));
            if ($request->request->has('username')) {
                $user->setUsername($request->request->get('username'));
            }
            if ($request->request->get('password')) {
                if ($request->request->get('password') != $request->request->get('confirm_password')) {
                    $errors['password'] = 'Passwords don\'t match.';
                } else if ($error = $this->userManager->validatePasswordStrength($user, $request->request->get('password'))) {
                    $errors['password'] = $error;
                } else {
                    $this->userManager->setUserPassword($user, $request->request->get('password'));
                }
            }
            if ($app['security']->isGranted('ROLE_ADMIN') && $request->request->has('roles')) {
                $user->setRoles($request->request->get('roles'));
            }

            foreach (array_keys($customFields) as $customField) {
                if ($request->request->has($customField)) {
                    $user->setCustomField($customField, $request->request->get($customField));
                }
            }

            $errors += $this->userManager->validate($user);

            if (empty($errors)) {
                $this->userManager->update($user);
                $msg = 'Saved account information.' . ($request->request->get('password') ? ' Changed password.' : '');
                $app['session']->getFlashBag()->set('alert', $msg);
            }
        }

        return $app['twig']->render($this->getTemplate('edit'), array(
            'layout_template' => $this->getTemplate('layout'),
            'error' => implode("\n", $errors),
            'user' => $user,
            'available_roles' => array('ROLE_USER', 'ROLE_ADMIN'),
            'image_url' => $this->getGravatarUrl($user->getEmail()),
            'customFields' => $customFields,
            'isUsernameRequired' => $this->isUsernameRequired,
        ));
    }

    /**
     * Specify custom fields to support in the editAction().
     *
     * @param array $editCustomFields
     */
    public function setEditCustomFields(array $editCustomFields)
    {
        $this->editCustomFields = $editCustomFields;
    }


    public function listAction(Application $app, Request $request)
    {
        $order_by = $request->get('order_by') ?: 'name';
        $order_dir = $request->get('order_dir') == 'DESC' ? 'DESC' : 'ASC';
        $limit = (int)($request->get('limit') ?: 50);
        $page = (int)($request->get('page') ?: 1);
        $offset = ($page - 1) * $limit;

        $criteria = array();
        if (!$app['security']->isGranted('ROLE_ADMIN')) {
            $criteria['isEnabled'] = true;
        }

        $users = $this->userManager->findBy($criteria, array(
            'limit' => array($offset, $limit),
            'order_by' => array($order_by, $order_dir),
        ));
        $numResults = $this->userManager->findCount($criteria);

        $paginator = new Paginator($numResults, $limit, $page,
            $app['url_generator']->generate('user.list') . '?page=(:num)&limit=' . $limit . '&order_by=' . $order_by . '&order_dir=' . $order_dir
        );

        foreach ($users as $user) {
            $user->imageUrl = $this->getGravatarUrl($user->getEmail(), 40);
        }

        return $app['twig']->render($this->getTemplate('list'), array(
            'layout_template' => $this->getTemplate('layout'),
            'users' => $users,
            'paginator' => $paginator,

            // The following variables are no longer used in the default template,
            // but are retained for backward compatibility.
            'numResults' => $paginator->getTotalItems(),
            'nextUrl' => $paginator->getNextUrl(),
            'prevUrl' => $paginator->getPrevUrl(),
            'firstResult' => $paginator->getCurrentPageFirstItem(),
            'lastResult' => $paginator->getCurrentPageLastItem(),
        ));
    }

    /**
     * @param boolean $passwordResetEnabled
     */
    public function setPasswordResetEnabled($passwordResetEnabled)
    {
        $this->isPasswordResetEnabled = (bool) $passwordResetEnabled;
    }

    /**
     * @return boolean
     */
    public function isPasswordResetEnabled()
    {
        return $this->isPasswordResetEnabled;
    }

    /**
     * @param bool $isUsernameRequired
     */
    public function setUsernameRequired($isUsernameRequired)
    {
        $this->isUsernameRequired = (bool) $isUsernameRequired;
    }

    public function setEmailConfirmationRequired($isRequired)
    {
        $this->isEmailConfirmationRequired = (bool) $isRequired;
    }

    // ---------------------------------------------------------------------------
    //
    // Deprecated methods.
    //
    // Retained for backwards compatibility.
    //
    // ---------------------------------------------------------------------------

    /**
     * @param string $layoutTemplate
     * @deprecated Use setTemplate() or setTemplates() instead.
     */
    public function setLayoutTemplate($layoutTemplate)
    {
        $this->setTemplate('layout', $layoutTemplate);
    }

    /**
     * @deprecated Use setTemplate() or setTemplates() instead.
     * @param string $editTemplate
     */
    public function setEditTemplate($editTemplate)
    {
        $this->setTemplate('edit', $editTemplate);
    }

    /**
     * @deprecated Use setTemplate() or setTemplates() instead.
     * @param string $listTemplate
     */
    public function setListTemplate($listTemplate)
    {
        $this->setTemplate('list', $listTemplate);
    }

    /**
     * @deprecated Use setTemplate() or setTemplates() instead.
     * @param string $loginTemplate
     */
    public function setLoginTemplate($loginTemplate)
    {
        $this->setTemplate('login', $loginTemplate);
    }

    /**
     * @deprecated Use setTemplate() or setTemplates() instead.
     * @param string $registerTemplate
     */
    public function setRegisterTemplate($registerTemplate)
    {
        $this->setTemplate('register', $registerTemplate);
    }

    /**
     * @deprecated Use setTemplate() or setTemplates() instead.
     * @param string $viewTemplate
     */
    public function setViewTemplate($viewTemplate)
    {
        $this->setTemplate('view', $viewTemplate);
    }
}
