<?php

namespace SimpleUser;

use InvalidArgumentException;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class UserController
{
    /** @var UserManager */
    protected $userManager;

    protected $layoutTemplate = '@user/layout.twig';

    public function __construct(UserManager $userManager, $options = array())
    {
        $this->userManager = $userManager;

        if (!empty($options)) {
            $this->setOptions($options);
        }
    }

    public function setOptions(array $options)
    {
        if (array_key_exists('layout_template', $options)) {
            $this->layoutTemplate = $options['layout_template'];
        }
    }

    public function setLayoutTemplate($layoutTemplate)
    {
        $this->layoutTemplate = $layoutTemplate;
    }

    public function loginAction(Application $app, Request $request)
    {
        return $app['twig']->render('@user/login.twig', array(
            'layout_template' => $this->layoutTemplate,
            'error' => $app['security.last_error']($request),
            'last_username' => $app['session']->get('_security.last_username'),
        ));
    }

    public function registerAction(Application $app, Request $request)
    {
        if ($request->isMethod('POST')) {
            try {
                $user = $this->createUserFromRequest($request);
                $this->userManager->insert($user);
                $app['session']->getFlashBag()->set('alert', 'Account created.');

                return $app->redirect($app['url_generator']->generate('user.view', array('id' => $user->getId())));

            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $app['twig']->render('@user/register.twig', array(
            'layout_template' => $this->layoutTemplate,
            'error' => isset($error) ? $error : null,
            'name' => $request->request->get('name'),
            'email' => $request->request->get('email'),
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

        $errors = $user->validate();
        if (!empty($errors)) {
            throw new InvalidArgumentException(implode('<br>', $errors));
        }

        if ($this->userManager->findBy(array('email' => $user->getEmail()))) {
            throw new InvalidArgumentException('An account with that email address already exists.');
        }

        return $user;
    }

    public function viewAction(Application $app, Request $request, $id)
    {
        $user = $this->userManager->getUser($id);

        return $app['twig']->render('@user/view.twig', array(
            'layout_template' => $this->layoutTemplate,
            'user' => $user,
        ));

    }
}