<?php

namespace SimpleUser\Tests;

use SimpleUser\User;

class UserTest extends \PHPUnit_Framework_TestCase
{
    public function testNewUserHasInitialValues()
    {
        $user = new User('email@example.com');

        $this->assertGreaterThan(0, $user->getTimeCreated());
        $this->assertNotEmpty($user->getSalt());
        $this->assertTrue($user->hasRole('ROLE_USER'));
    }


    public function getValidUser()
    {

        $user = new User('email@example.com');
        $user->setPassword('test');

        return $user;
    }

    /**
     * @dataProvider getValidUserData
     */
    public function testValidationSuccess($data)
    {
        $user = $this->getValidUser();

        $this->assertEmpty($user->validate());

        foreach ($data as $setter => $val) {
            $user->$setter($val);
        }

        $this->assertEmpty($user->validate());
    }

    public function getValidUserData()
    {
        return array(
            array(array('setEmail' => str_repeat('x', 88) . '@example.com')), // 100 character email is valid
            array(array('setPassword' => str_repeat('x', 255)), array('password')), // 255 character password is valid
            array(array('setName' => str_repeat('x', 100)), array('name')),
        );
    }

    /**
     * @dataProvider getInvalidUserData
     */
    public function testValidationFailure($data, $expectedErrors)
    {
        $user = $this->getValidUser();

        foreach ($data as $setter => $val) {
            $user->$setter($val);
        }

        $errors = $user->validate();
        foreach ($expectedErrors as $expected) {
            $this->assertArrayHasKey($expected, $errors);
        }
    }

    public function getInvalidUserData()
    {
        // Format: array(array($setterMethod => $value, ...), array($expectedErrorKey, ...))
        return array(
            array(array('setEmail' => null), array('email')),
            array(array('setEmail' => ''), array('email')),
            array(array('setEmail' => 'invalidEmail'), array('email')),
            array(array('setEmail' => str_repeat('x', 89) . '@example.com'), array('email')), // 101 character email is invalid
            array(array('setPassword' => null), array('password')),
            array(array('setPassword' => ''), array('password')),
            array(array('setPassword' => str_repeat('x', 256)), array('password')), // 256 character password 256 character password is invalid
            array(array('setName' => str_repeat('x', 101)), array('name')),
        );
    }

    public function testUserIsEnabledByDefault()
    {
        $user = new User('test@example.com');

        $this->assertTrue($user->isEnabled());
    }

    public function testUserIsDisabled()
    {
        $user = new User('test@example.com');

        $user->setEnabled(false);
        $this->assertFalse($user->isEnabled());

        $user->setEnabled(true);
        $this->assertTrue($user->isEnabled());
    }
}
