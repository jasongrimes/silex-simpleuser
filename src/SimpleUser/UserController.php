<?php

namespace SimpleUser;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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

    protected $layoutTemplate = '@user/layout.twig';
    protected $loginTemplate = '@user/login.twig';
    protected $registerTemplate = '@user/register.twig';
    protected $viewTemplate = '@user/view.twig';
    protected $editTemplate = '@user/edit.twig';
    protected $listTemplate = '@user/list.twig';

    protected $isUsernameRequired = false;

    protected $controllerOptions = array();

    /**
     * Constructor.
     *
     * @param UserManager $userManager
     * @param array $options
     */
    public function __construct(UserManager $userManager, $options = array())
    {
        $this->userManager = $userManager;

        if (!empty($options)) {
            $this->setOptions($options);
        }
    }

    /**
     * @param array $options
     */
    public function setOptions(array $options)
    {
        foreach (array('layoutTemplate', 'loginTemplate', 'registerTemplate', 'viewTemplate', 'editTemplate', 'listTemplate', 'isUsernameRequired')
                 as $property)
        {
            if (array_key_exists($property, $options)) {
                $this->$property = $options[$property];
            }
        }

        if (array_key_exists('controllers', $options)) {
            $this->controllerOptions = $options['controllers'];
        }
    }

    /**
     * @param string $layoutTemplate
     */
    public function setLayoutTemplate($layoutTemplate)
    {
        $this->layoutTemplate = $layoutTemplate;
    }

    /**
     * @param string $editTemplate
     */
    public function setEditTemplate($editTemplate)
    {
        $this->editTemplate = $editTemplate;
    }

    /**
     * @param string $listTemplate
     */
    public function setListTemplate($listTemplate)
    {
        $this->listTemplate = $listTemplate;
    }

    /**
     * @param string $loginTemplate
     */
    public function setLoginTemplate($loginTemplate)
    {
        $this->loginTemplate = $loginTemplate;
    }

    /**
     * @param string $registerTemplate
     */
    public function setRegisterTemplate($registerTemplate)
    {
        $this->registerTemplate = $registerTemplate;
    }

    /**
     * @param string $viewTemplate
     */
    public function setViewTemplate($viewTemplate)
    {
        $this->viewTemplate = $viewTemplate;
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
        return $app['twig']->render($this->loginTemplate, array(
            'layout_template' => $this->layoutTemplate,
            'error' => $app['security.last_error']($request),
            'last_username' => $app['session']->get('_security.last_username'),
            'allowRememberMe' => isset($app['security.remember_me.response_listener']),
        ));
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
                $this->userManager->insert($user);
                $app['session']->getFlashBag()->set('alert', 'Account created.');

                // Log the user in to the new account.
                if (null !== ($current_token = $app['security']->getToken())) {
                    $providerKey = method_exists($current_token, 'getProviderKey') ? $current_token->getProviderKey() : $current_token->getKey();
                    $token = new UsernamePasswordToken($user, null, $providerKey);
                    $app['security']->setToken($token);
                }

                return $app->redirect($app['url_generator']->generate('user.view', array('id' => $user->getId())));

            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $app['twig']->render($this->registerTemplate, array(
            'layout_template' => $this->layoutTemplate,
            'error' => isset($error) ? $error : null,
            'name' => $request->request->get('name'),
            'email' => $request->request->get('email'),
            'username' => $request->request->get('username'),
            'isUsernameRequired' => $this->isUsernameRequired,
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

        return $app['twig']->render($this->viewTemplate, array(
            'layout_template' => $this->layoutTemplate,
            'user' => $user,
            'imageUrl' => $this->getGravatarUrl($user->getEmail()),
        ));

    }

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

        $options = array_key_exists('edit', $this->controllerOptions) ? $this->controllerOptions['edit'] : array();
        $customFields = array_key_exists('customFields', $options) ? $options['customFields'] : array();

        if ($request->isMethod('POST')) {
            $user->setName($request->request->get('name'));
            $user->setEmail($request->request->get('email'));
            if ($request->request->has('username')) {
                $user->setUsername($request->request->get('username'));
            }
            if ($request->request->get('password')) {
                if ($request->request->get('password') != $request->request->get('confirm_password')) {
                    $errors['password'] = 'Passwords don\'t match.';
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

        return $app['twig']->render($this->editTemplate, array(
            'layout_template' => $this->layoutTemplate,
            'error' => implode("\n", $errors),
            'user' => $user,
            'available_roles' => array('ROLE_USER', 'ROLE_ADMIN'),
            'image_url' => $this->getGravatarUrl($user->getEmail()),
            'customFields' => $customFields,
            'isUsernameRequired' => $this->isUsernameRequired,
        ));
    }

    public function listAction(Application $app, Request $request)
    {
        $order_by = $request->get('order_by') ?: 'name';
        $order_dir = $request->get('order_dir') == 'DESC' ? 'DESC' : 'ASC';
        $limit = (int)($request->get('limit') ?: 50);
        $page = (int)($request->get('page') ?: 1);
        $offset = ($page - 1) * $limit;

        $users = $this->userManager->findBy(array(), array(
            'limit' => array($offset, $limit),
            'order_by' => array($order_by, $order_dir),
        ));
        $numResults = $this->userManager->findCount();

        $paginator = new Paginator($numResults, $limit, $page,
            $app['url_generator']->generate('user.list') . '?page=(:num)&limit=' . $limit . '&order_by=' . $order_by . '&order_dir=' . $order_dir
        );

        foreach ($users as $user) {
            $user->imageUrl = $this->getGravatarUrl($user->getEmail(), 40);
        }

        return $app['twig']->render($this->listTemplate, array(
            'layout_template' => $this->layoutTemplate,
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
}
