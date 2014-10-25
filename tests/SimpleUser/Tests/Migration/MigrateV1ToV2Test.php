<?php

namespace SimpleUser\Tests\Migration;

use Silex\Application;
use Doctrine\DBAL\Connection;
use Silex\Provider\DoctrineServiceProvider;
use SimpleUser\Migration\MigrateV1ToV2;
use SimpleUser\UserManager;
use SimpleUser\User;

class MigrateV1ToV2Test extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Connection
     */
    protected $conn;

    /** @var UserManager */
    protected $userManager;

    /** @var MigrateV1ToV2 */
    protected $migrator;

    public function setUp()
    {
        $app = new Application();
        $app->register(new DoctrineServiceProvider(), array(
            'db.options' => array(
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ),
        ));
        $this->conn = $app['db'];
        $this->userManager = new UserManager($app['db'], $app);
        $this->migrator = new MigrateV1ToV2($this->conn);

        // Set up v1 schema.
        $this->conn->executeUpdate(file_get_contents(__DIR__ . '/files/v1-sqlite.sql'));
    }

    protected function insertUserIntoV1($userId, $username, $isEnabled, $confirmationToken, $timePasswordResetRequested)
    {
        $this->conn->executeUpdate('INSERT INTO users (id) values (?)', array($userId));

        $this->insertCustomField($userId, 'username', $username);
        $this->insertCustomField($userId, 'su:isEnabled', $isEnabled);
        $this->insertCustomField($userId, 'su:confirmationToken', $confirmationToken);
        $this->insertCustomField($userId, 'su:timePasswordResetRequested', $timePasswordResetRequested);
    }

    protected function insertCustomField($userId, $field, $value)
    {
        $this->conn->executeUpdate('INSERT INTO user_custom_fields (user_id, attribute, value) VALUES (?, ?, ?)', array(
            $userId, $field, $value
        ));
    }

    protected function fetchCustomField($userId, $field)
    {
        return $this->conn->fetchColumn('SELECT value FROM user_custom_fields WHERE user_id = ? AND attribute = ?', array($userId, $field));
    }


    public function testMigrateUp()
    {
        $userId = 1;
        $username = 'foo';
        $isEnabled = true;
        $confirmationToken = 'toke';
        $timePasswordResetRequested = null;

        $this->insertUserIntoV1($userId, $username, $isEnabled, $confirmationToken, $timePasswordResetRequested);

        // echo implode(";\n", $this->migrator->sqlUp());
        $this->migrator->up();

        $user = $this->userManager->getUser($userId);
        $this->assertEquals($username, $user->getUsername());
        $this->assertEquals($isEnabled, $user->isEnabled());
        $this->assertEquals($confirmationToken, $user->getConfirmationToken());
        $this->assertEquals($timePasswordResetRequested, $user->getTimePasswordResetRequested());
    }

    public function testMigrateDown()
    {
        $username = 'foo';
        $isEnabled = true;
        $confirmationToken = 'toke';
        $timePasswordResetRequested = null;

        $this->migrator->up();

        $user = new User('test@example.com', 'password');
        $user->setUsername($username);
        $user->setEnabled($isEnabled);
        $user->setConfirmationToken($confirmationToken);
        $user->setTimePasswordResetRequested($timePasswordResetRequested);

        $this->userManager->insert($user);
        $userId = $user->getId();

        // echo implode(";\n", $this->migrator->sqlDown());
        $this->migrator->down();

        $this->assertEquals($username, $this->fetchCustomField($userId, 'username'));
        $this->assertEquals($isEnabled, $this->fetchCustomField($userId, 'su:isEnabled'));
        $this->assertEquals($confirmationToken, $this->fetchCustomField($userId, 'su:confirmationToken'));
        $this->assertEquals($timePasswordResetRequested, $this->fetchCustomField($userId, 'su:timePasswordResetRequested'));
    }

}
