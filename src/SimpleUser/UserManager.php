<?php

namespace SimpleUser;

use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Symfony\Component\Security\Core\Encoder\PasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Doctrine\DBAL\Connection;
use Silex\Application;

class UserManager implements UserProviderInterface
{
    /** @var Connection */
    protected $conn;

    /** @var \Silex\Application */
    protected $app;

    /**
     * Constructor.
     *
     * @param Connection $conn
     * @param Application $app
     */
    public function __construct(Connection $conn, Application $app)
    {
        $this->conn = $conn;
        $this->app = $app;
    }

    // ----- UserProviderInterface -----

    /**
     * Loads the user for the given username.
     *
     * @param string $username The username
     * @return UserInterface
     * @throws UsernameNotFoundException if the user is not found
     */
    public function loadUserByUsername($username)
    {
        $user = $this->findOneBy(array('email' => $username));
        if (!$user) {
            throw new UsernameNotFoundException(sprintf('Email "%s" does not exist.', $username));
        }

        return $user;
    }

    /**
     * Refreshes the user for the account interface.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @param UserInterface $user
     * @return UserInterface
     * @throws UnsupportedUserException if the account is not supported
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        return $this->getUser($user->getId());
    }

    /**
     * Whether this provider supports the given user class
     *
     * @param string $class
     * @return Boolean
     */
    public function supportsClass($class)
    {
        return $class === 'SimpleUser\User';
    }

    // ----- End UserProviderInterface -----

    protected function hydrateUser(array $data)
    {
        $user = new User($data['email']);

        $user->setId($data['id']);
        $user->setPassword($data['password']);
        $user->setSalt($data['salt']);
        $user->setName($data['name']);
        if ($roles = explode(',', $data['roles'])) {
            $user->setRoles($roles);
        }
        $user->setTimeCreated($data['time_created']);

        return $user;
    }

    /**
     * Factory method for creating a new User instance.
     *
     * @param string $email
     * @param string $plainPassword
     * @param string $name
     * @param array $roles
     * @return User
     */
    public function createUser($email, $plainPassword, $name = null, $roles = array())
    {
        $user = new User($email);

        $this->setUserPassword($user, $plainPassword);

        if ($name !== null) {
            $user->setName($name);
        }
        if (!empty($roles)) {
            $user->setRoles($roles);
        }

        return $user;
    }

    /**
     * Get the password encoder to use for the given user object.
     *
     * @param UserInterface $user
     * @return PasswordEncoderInterface
     */
    protected function getEncoder(UserInterface $user)
    {
        return $this->app['security.encoder_factory']->getEncoder($user);
    }

    public function encodeUserPassword(User $user, $password)
    {
        $encoder = $this->getEncoder($user);
        return $encoder->encodePassword($password, $user->getSalt());
    }

    public function setUserPassword(User $user, $password)
    {
        $user->setPassword($this->encodeUserPassword($user, $password));
    }

    public function checkUserPassword(User $user, $password)
    {
        return $user->getPassword() === $this->encodeUserPassword($user, $password);
    }

    /**
     * @return UserInterface|null
     */
    public function getCurrentUser()
    {
        if ($this->isLoggedIn()) {
            return $this->app['security']->getToken()->getUser();
        }

        return null;
    }

    /**
     * @return boolean
     */
    function isLoggedIn()
    {
        $token = $this->app['security']->getToken();
        if (null === $token) {
            return false;
        }

        return $this->app['security']->isGranted('IS_AUTHENTICATED_FULLY');
    }


    /**
     * @param array $criteria
     * @return array An array of matching User instances, or an empty array if no matching users were found.
     */
    public function findBy(array $criteria = array())
    {
        $params = array();

        $sql = 'SELECT * FROM users ';

        $first_crit = true;
        foreach ($criteria as $key => $val) {
            $sql .= ($first_crit ? 'WHERE' : 'AND') . ' ' . $key . ' = :' . $key . ' ';
            $params[$key] = $val;
            $first_crit = false;
        }

        $data = $this->conn->fetchAll($sql, $params);

        $users = array();
        foreach ($data as $userData) {
            $users[] = $this->hydrateUser($userData);
        }

        return $users;
    }

    /**
     * @param array $criteria
     * @return User|null
     */
    public function findOneBy(array $criteria)
    {
        $users = $this->findBy($criteria);

        if (empty($users)) {
            return null;
        }

        return reset($users);
    }

    public function getUser($id)
    {
        return $this->findOneBy(array('id' => $id));
    }

    public function insert(User $user)
    {
        $sql = 'INSERT INTO users (email, password, salt, name, roles, time_created) VALUES (:email, :password, :salt, :name, :roles, :timeCreated) ';

        $params = array(
            'email' => $user->getEmail(),
            'password' => $user->getPassword(),
            'salt' => $user->getSalt(),
            'name' => $user->getName(),
            'roles' => implode(',', $user->getRoles()),
            'timeCreated' => $user->getTimeCreated(),
        );

        $this->conn->executeUpdate($sql, $params);

        $user->setId($this->conn->lastInsertId());
    }

    public function update(User $user)
    {
        $sql = 'UPDATE users
            SET email = :email
            , password = :password
            , salt = :salt
            , name = :name
            , roles = :roles
            , time_created = :timeCreated
            WHERE id = :id';

        $params = array(
            'email' => $user->getEmail(),
            'password' => $user->getPassword(),
            'salt' => $user->getSalt(),
            'name' => $user->getName(),
            'roles' => implode(',', $user->getRoles()),
            'timeCreated' => $user->getTimeCreated(),
            'id' => $user->getId(),
        );

        $this->conn->executeUpdate($sql, $params);
    }

    public function delete(User $user)
    {
        $this->conn->executeUpdate('DELETE FROM users WHERE id = ?', array($user->getId()));
    }
}
