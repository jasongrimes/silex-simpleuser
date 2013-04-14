<?php

namespace SimpleUser;

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
}