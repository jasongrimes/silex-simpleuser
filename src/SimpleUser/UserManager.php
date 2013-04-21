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

    /** @var User[] */
    protected $identityMap = array();

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
        if (!$this->supportsClass(get_class($user))) {
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

    /**
     * Reconstitute a User object from stored data.
     *
     * @param array $data
     * @return User
     */
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

        if (!empty($plainPassword)) {
            $this->setUserPassword($user, $plainPassword);
        }

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

    /**
     * Encode a plain text password for a given user. Hashes the password with the given user's salt.
     *
     * @param User $user
     * @param string $password A plain text password.
     * @return string An encoded password.
     */
    public function encodeUserPassword(User $user, $password)
    {
        $encoder = $this->getEncoder($user);
        return $encoder->encodePassword($password, $user->getSalt());
    }

    /**
     * Encode a plain text password and set it on the given User object.
     *
     * @param User $user
     * @param string $password A plain text password.
     */
    public function setUserPassword(User $user, $password)
    {
        $user->setPassword($this->encodeUserPassword($user, $password));
    }

    /**
     * Test whether a given plain text password matches a given User's encoded password.
     *
     * @param User $user
     * @param string $password
     * @return bool
     */
    public function checkUserPassword(User $user, $password)
    {
        return $user->getPassword() === $this->encodeUserPassword($user, $password);
    }

    /**
     * Get a User instance for the currently logged in User, if any.
     *
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
     * Test whether the current user is authenticated.
     *
     * @return boolean
     */
    function isLoggedIn()
    {
        $token = $this->app['security']->getToken();
        if (null === $token) {
            return false;
        }

        return $this->app['security']->isGranted('IS_AUTHENTICATED_REMEMBERED');
    }

    /**
     * Get a User instance by its ID.
     *
     * @param int $id
     * @return User|null The User, or null if there is no User with that ID.
     */
    public function getUser($id)
    {
        return $this->findOneBy(array('id' => $id));
    }

    /**
     * Get a single User instance that matches the given criteria. If more than one User matches, the first result is returned.
     *
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

    /**
     * Find User instances that match the given criteria.
     *
     * @param array $criteria
     * @param array $options An array of the following options (all optional):<pre>
     *      limit (int|array) The maximum number of results to return, or an array of (offset, limit).
     *      order_by (string|array) The name of the column to order by, or an array of column name and direction, ex. array(time_created, DESC)
     * </pre>
     * @return User[] An array of matching User instances, or an empty array if no matching users were found.
     */
    public function findBy(array $criteria = array(), array $options = array())
    {
        // Check the identity map first.
        if (array_key_exists('id', $criteria) && array_key_exists($criteria['id'], $this->identityMap)) {
            return array($this->identityMap[$criteria['id']]);
        }

        list ($common_sql, $params) = $this->createCommonFindSql($criteria);

        $sql = 'SELECT * ' . $common_sql;

        if (array_key_exists('order_by', $options)) {
            list ($order_by, $order_dir) = is_array($options['order_by']) ? $options['order_by'] : array($options['order_by']);
            $sql .= 'ORDER BY ' . $this->conn->quoteIdentifier($order_by) . ' ' . ($order_dir == 'DESC' ? 'DESC' : 'ASC') . ' ';
        }
        if (array_key_exists('limit', $options)) {
            list ($offset, $limit) = is_array($options['limit']) ? $options['limit'] : array(0, $options['limit']);
            $sql .= 'LIMIT ' . (int) $offset . ', ' . (int) $limit . ' ';
        }

        $data = $this->conn->fetchAll($sql, $params);

        $users = array();
        foreach ($data as $userData) {
            if (array_key_exists($userData['id'], $this->identityMap)) {
                $user = $this->identityMap[$userData['id']];
            } else {
                $user = $this->hydrateUser($userData);
                $this->identityMap[$user->getId()] = $user;
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * Get SQL query fragment common to both find and count querires.
     *
     * @param array $criteria
     * @return array An array of SQL and query parameters, in the form array($sql, $params)
     */
    protected function createCommonFindSql(array $criteria = array())
    {
        $params = array();

        $sql = 'FROM users ';

        $first_crit = true;
        foreach ($criteria as $key => $val) {
            $sql .= ($first_crit ? 'WHERE' : 'AND') . ' ' . $key . ' = :' . $key . ' ';
            $params[$key] = $val;
            $first_crit = false;
        }

        return array ($sql, $params);
    }

    /**
     * Count users that match the given criteria.
     *
     * @param array $criteria
     * @return int The number of users that match the criteria.
     */
    public function findCount(array $criteria = array())
    {
        list ($common_sql, $params) = $this->createCommonFindSql($criteria);

        $sql = 'SELECT COUNT(*) ' . $common_sql;

        return $this->conn->fetchColumn($sql, $params) ?: 0;
    }

    /**
     * Insert a new User instance into the database.
     *
     * @param User $user
     */
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

        $this->identityMap[$user->getId()] = $user;
    }

    /**
     * Update data in the database for an existing user.
     *
     * @param User $user
     */
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

    /**
     * Delete a User from the database.
     *
     * @param User $user
     */
    public function delete(User $user)
    {
        $this->clearIdentityMap($user);

        $this->conn->executeUpdate('DELETE FROM users WHERE id = ?', array($user->getId()));
    }

    /**
     * Validate a user object.
     *
     * Invokes User::validate(), and additionally tests that the User's email address isn't associated with another User.
     *
     * @param User $user
     * @return array An array of error messages, or an empty array if the User is valid.
     */
    public function validate(User $user)
    {
        $errors = $user->validate();

        $duplicates = $this->findBy(array('email' => $user->getEmail()));
        if (!empty($duplicates)) {
            foreach ($duplicates as $dup) {
                if ($user->getId() && $dup->getId() == $user->getId()) {
                    continue;
                }
                $errors['email'] = 'An account with that email address already exists.';
            }
        }

        return $errors;
    }

    /**
     * Clear User instances from the identity map, so that they can be read again from the database.
     *
     * Call with no arguments to clear the entire identity map.
     * Pass a single user to remove just that user from the identity map.
     *
     * @param mixed $user Either a User instance, an integer user ID, or null.
     */
    public function clearIdentityMap($user = null)
    {
        if ($user === null) {
            $this->identityMap = array();
        } else if ($user instanceof User && array_key_exists($user->getId(), $this->identityMap)) {
            unset($this->identityMap[$user->getId()]);
        } else if (is_numeric($user) && array_key_exists($user, $this->identityMap)) {
            unset($this->identityMap[$user]);
        }
    }
}
