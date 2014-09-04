<?php

namespace SimpleUser\Tests;

use SimpleUser\UserManager;
use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

class UserManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var UserManager
     */
    protected $userManager;

    /**
     * @var Connection
     */
    protected $conn;

    public function setUp()
    {
        $app = new Application();
        $app->register(new SecurityServiceProvider());
        $app->register(new DoctrineServiceProvider(), array(
            'db.options' => array(
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ),
        ));
        $app['db']->executeUpdate(file_get_contents(__DIR__ . '/../../../sql/sqlite.sql'));

        $this->userManager = new UserManager($app['db'], $app);
        $this->conn = $app['db'];
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

    public function testDeleteUser()
    {
        $email = 'test@example.com';

        $user = $this->userManager->createUser($email, 'password');
        $this->userManager->insert($user);
        $this->assertEquals($user, $this->userManager->findOneBy(array('email' => $email)));

        $this->userManager->delete($user);
        $this->assertNull($this->userManager->findOneBy(array('email' => $email)));
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

        // Search by two custom fields.
        $foundUser = $this->userManager->findOneBy(array('customFields' => array('field1' => 'foo', 'field2' => 'bar')));
        $this->assertEquals($user, $foundUser);

        // Search by one custom field and one standard property.
        $foundUser = $this->userManager->findOneBy(array('id' => $user->getId(), 'customFields' => array('field2' => 'bar')));
        $this->assertEquals($user, $foundUser);

        // Failed search returns null.
        $foundUser = $this->userManager->findOneBy(array('customFields' => array('field1' => 'foo', 'field2' => 'does-not-exist')));
        $this->assertNull($foundUser);
    }

    public function testLoadUserByUsername()
    {
        $email = 'test@example.com';

        $user = $this->userManager->createUser($email, 'password');
        $this->userManager->insert($user);

        $foundUser = $this->userManager->loadUserByUsername($email);
        $this->assertEquals($foundUser, $user);
    }

    /**
     * @expectedException Symfony\Component\Security\Core\Exception\UsernameNotFoundException
     */
    public function testLoadUserByUsernameThrowsExceptionIfUserNotFound()
    {
        $this->userManager->loadUserByUsername('does-not-exist@example.com');
    }

    public function testFindAndCount()
    {
        $customField = 'foo';
        $customVal = 'bar';
        $email1 = 'test1@example.com';
        $email2 = 'test2@example.com';

        $user1 = $this->userManager->createUser($email1, 'password');
        $user1->setCustomField($customField, $customVal);
        $this->userManager->insert($user1);

        $user2 = $this->userManager->createUser($email2, 'password');
        $user2->setCustomField($customField, $customVal);
        $this->userManager->insert($user2);

        $criteria = array('email' => $email1);
        $results = $this->userManager->findBy($criteria);
        $numResults = $this->userManager->findCount($criteria);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $numResults);
        $this->assertEquals($user1, reset($results));

        $criteria = array('customFields' => array($customField => $customVal));
        $results = $this->userManager->findBy($criteria);
        $numResults = $this->userManager->findCount($criteria);
        $this->assertCount(2, $results);
        $this->assertEquals(2, $numResults);
        $this->assertContains($user1, $results);
        $this->assertContains($user2, $results);
    }

    public function testValidationFailsOnDuplicateEmail()
    {
        $email = 'test@example.com';

        $user1 = $this->userManager->createUser($email, 'password');
        $this->userManager->insert($user1);
        $errors = $this->userManager->validate($user1);
        $this->assertEmpty($errors);

        // Validation fails because a different user already exists in the database with that email address.
        $user2 = $this->userManager->createUser($email, 'password');
        $errors = $this->userManager->validate($user2);
        $this->arrayHasKey('email', $errors);
    }

}
