<?php

namespace SimpleUser\Tests;

use SimpleUser\UserManager;
use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Doctrine\DBAL\Connection;

class UserManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var UserManager
     */
    protected $userManager;

    public function setUp()
    {
        $app = new Application();
        $app->register(new DoctrineServiceProvider(), array(
            'db.options' => array(
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ),
        ));
        $app->register(new SecurityServiceProvider());

        $createSql = file_get_contents(__DIR__ . '/../../../sql/sqlite.sql');

        /** @var Connection $conn */
        $conn = $app['db'];
        $conn->executeUpdate($createSql);

        // var_dump($conn->fetchAll('SELECT * FROM sqlite_master WHERE type="table"'));

        $this->userManager = new UserManager($app['db'], $app);

    }

    public function testCreateUser()
    {
        $user = $this->userManager->createUser('test@example.com', 'pass');

        $this->assertInstanceOf('Simpleuser\User', $user);
    }

    public function testStoreAndFetchUser()
    {
        $user = $this->userManager->createUser('test@example.com', 'password');
        $this->assertNull($user->getId());

        $this->userManager->insert($user);
        $this->assertGreaterThan(0, $user->getId());

        $storedUser = $this->userManager->getUser($user->getId());
        $this->assertEquals($storedUser, $user);
    }

    public function testUpdateUser()
    {
        $user = $this->userManager->createUser('test@example.com', 'pass');
        $this->userManager->insert($user);

        $user->setName('Foo');
        $this->userManager->update($user);

        $storedUser = $this->userManager->getUser($user->getId());

        $this->assertEquals('Foo', $storedUser->getName());
    }

    public function testCustomFields()
    {
        $user = $this->userManager->createUser('test@example.com', 'pass');
        $user->setCustomField('field1', 'foo');
        $user->setCustomField('field2', 'bar');

        $this->userManager->insert($user);

        $storedUser = $this->userManager->getUser($user->getId());
        $this->assertEquals('foo', $storedUser->getCustomField('field1'));
        $this->assertEquals('bar', $storedUser->getCustomField('field2'));

        $foundUser = $this->userManager->findOneBy(array('customFields' => array('field1' => 'foo', 'field2' => 'bar')));
        $this->assertEquals($user, $foundUser);
    }


}
