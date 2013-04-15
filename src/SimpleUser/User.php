<?php

namespace SimpleUser;

use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface
{
    protected $id;
    protected $email;
    protected $password;
    protected $salt;
    protected $roles = array();
    protected $name = '';
    protected $timeCreated;

    public function __construct($email)
    {
        $this->email = $email;
        $this->timeCreated = time();
        $this->salt = base_convert(sha1(uniqid(mt_rand(), true)), 16, 36);
    }

    /**
     * Returns the roles granted to the user.
     *
     * <code>
     * public function getRoles()
     * {
     *     return array('ROLE_USER');
     * }
     * </code>
     *
     * Alternatively, the roles might be stored on a ``roles`` property,
     * and populated in any number of different ways when the user object
     * is created.
     *
     * @return Role[] The user roles
     */
    public function getRoles()
    {
        return $this->roles;
    }

    public function setRoles(array $roles)
    {
        $this->roles = array();

        foreach ($roles as $role) {
            $this->addRole($role);
        }

        return $this;
    }

    public function hasRole($role)
    {
        return in_array(strtoupper($role), $this->roles, true);
    }

    public function addRole($role)
    {
        if (!$this->hasRole($role)) {
            $this->roles[] = strtoupper($role);
        }
    }

    public function removeRole($role)
    {
        if (false !== $key = array_search(strtoupper($role), $this->roles, true)) {
            unset($this->roles[$key]);
            $this->roles = array_values($this->roles);
        }

        return $this;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * Returns the password used to authenticate the user.
     *
     * This should be the encoded password. On authentication, a plain-text
     * password will be salted, encoded, and then compared to this value.
     *
     * @return string The password
     */
    public function getPassword()
    {
        return $this->password;
    }

    public function setPassword($password)
    {
        $this->password = $password;
    }

    public function setSalt($salt)
    {
        $this->salt = $salt;
    }

    /**
     * Returns the salt that was originally used to encode the password.
     *
     * This can return null if the password was not encoded using a salt.
     *
     * @return string The salt
     */
    public function getSalt()
    {
        return $this->salt;
    }

    /**
     * Returns the username used to authenticate the user.
     *
     * @return string The username
     */
    public function getUsername()
    {
        return $this->email;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setTimeCreated($timeCreated)
    {
        $this->timeCreated = $timeCreated;
    }

    public function getTimeCreated()
    {
        return $this->timeCreated;
    }

    /**
     * Removes sensitive data from the user.
     *
     * This is important if, at any given point, sensitive information like
     * the plain-text password is stored on this object.
     *
     * @return void
     */
    public function eraseCredentials()
    {
        // No-op, required by UserInterface. We never store the plain text credentials in this object.
    }

    public function validate()
    {
        $errors = array();

        if (!$this->getEmail()) {
            $errors['email'] = 'Email address is required.';
        } else if (!strpos($this->getEmail(), '@')) {
            // Basic email format sanity check. Real validation comes from sending them an email with a link they have to click.
            $errors['email'] = 'Email address appears to be invalid.';
        } else if (strlen($this->getEmail()) > 100) {
            $errors['email'] = 'Email address can\'t be longer than 100 characters.';
        }

        if (!$this->getPassword()) {
            $errors['password'] = 'Password is required.';
        } else if (strlen($this->getPassword()) > 255) {
            $errors['password'] = 'Password can\'t be longer than 255 characters.';
        }

        if (strlen($this->getName()) > 100) {
            $errors['name'] = 'Name can\'t be longer than 100 characters.';
        }

        return $errors;
    }
}