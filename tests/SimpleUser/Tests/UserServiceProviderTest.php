<?php

namespace SimpleUser\Tests;

use Silex\Application;
use Silex\Provider;
use SimpleUser\UserServiceProvider;

class UserServiceProviderTest extends \PHPUnit_Framework_TestCase
{
    protected function getMinimalApp()
    {
        $app = new Application();

        $app->register(new Provider\SecurityServiceProvider(),
            array('security.firewalls' => array('dummy-firewall' => array('form' => array())))
        );
        $app->register(new Provider\DoctrineServiceProvider());
        $app->register(new UserServiceProvider());

        return $app;
    }

    protected function getAppWithAllDependencies()
    {
        $app = $this->getMinimalApp();

        $app->register(new Provider\RememberMeServiceProvider());
        $app->register(new Provider\SessionServiceProvider());
        $app->register(new Provider\ServiceControllerServiceProvider());
        $app->register(new Provider\UrlGeneratorServiceProvider());
        $app->register(new Provider\TwigServiceProvider());
        $app->register(new Provider\SwiftmailerServiceProvider());

        return $app;
    }

    public function testWithDefaults()
    {
        $app = $this->getMinimalApp();
        $app->boot();

        $this->assertInstanceOf('SimpleUser\UserManager', $app['user.manager']);
        $this->assertInstanceOf('SimpleUser\UserController', $app['user.controller']);
        $this->assertNull($app['user']);
    }

    public function testMailer()
    {
        $app = $this->getAppWithAllDependencies();
        $app->boot();

        $this->assertInstanceOf('SimpleUser\Mailer', $app['user.mailer']);
    }

}