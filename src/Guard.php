<?php
namespace Slim\Csrf;

use ArrayAccess;
use Countable;
use Traversable;
use RuntimeException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * CSRF protection middleware.
 */
class Guard
{
    const ANGULAR_CSRF_COOKIE = 'XSRF-TOKEN';

    /**
     * Prefix for CSRF parameters (omit trailing "_" underscore)
     *
     * @var string
     */
    protected $prefix;

    /**
     * CSRF storage
     *
     * Should be either an array or an object. If an object is used, then it must
     * implement ArrayAccess and should implement Countable and Iterator (or
     * IteratorAggregate) if storage limit enforcement is required.
     *
     * @var array|ArrayAccess
     */
    protected $storage;

    /**
     * Number of elements to store in the storage array
     *
     * Default is 200, set via constructor
     *
     * @var integer
     */
    protected $storageLimit;

    /**
     * CSRF Strength
     *
     * @var int
     */
    protected $strength;

    /**
     * Callable to be executed if the CSRF validation fails
     *
     * Signature of callable is:
     *    function($request, $response, $next)
     * and a $response must be returned.
     *
     * @var callable
     */
    protected $failureCallable;

    /**
     * Determines whether or not we should persist the token throughout the duration of the user's session.
     *
     * For security, Slim-Csrf will *always* reset the token if there is a validation error.
     * @var bool True to use the same token throughout the session (unless there is a validation error),
     * false to get a new token with each request.
     */
    protected $persistentTokenMode;

    /**
     * Stores the latest key-pair generated by the class
     *
     * @var array
     */
    protected $keyPair;

    /**
     * PSR Logger for logging and debugging
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Default implementation checks the request body for the CSRF tokens. Setting this property to true will
     * check for Angular's X-XSRF-TOKEN header instead.
     * @var bool
     */
    protected $useAngularCsrf;

    /**
     * Flag to whether or not check for CSRF
     * Use when the authentication is not session based (api instead of browser)
     * @var bool
     */
    protected $byPass = false;

    /**
     * Create new CSRF guard
     *
     * @param string                 $prefix
     * @param null|array|ArrayAccess $storage
     * @param null|callable          $failureCallable
     * @param integer                $storageLimit
     * @param integer                $strength
     * @throws RuntimeException if the session cannot be found
     */
    public function __construct(
        $prefix = 'csrf',
        &$storage = null,
        callable $failureCallable = null,
        $storageLimit = 200,
        $strength = 16,
        $persistentTokenMode = false
    ) {
        $this->prefix = rtrim($prefix, '_');
        if ($strength < 16) {
            throw new RuntimeException('CSRF middleware failed. Minimum strength is 16.');
        }
        $this->strength = $strength;
        $this->storage = &$storage;

        $this->setFailureCallable($failureCallable);
        $this->setStorageLimit($storageLimit);

        $this->setPersistentTokenMode($persistentTokenMode);

        $this->keyPair = null;
        $this->useAngularCsrf = false;
    }

    /**
     * Retrieve token name key
     *
     * @return string
     */
    public function getTokenNameKey()
    {
        return $this->prefix . '_name';
    }

    /**
     * Retrieve token value key
     *
     * @return string
     */
    public function getTokenValueKey()
    {
        return $this->prefix . '_value';
    }

    /**
     * Invoke middleware
     *
     * @param  ServerRequestInterface  $request  PSR7 request object
     * @param  ResponseInterface $response PSR7 response object
     * @param  callable          $next     Next middleware callable
     *
     * @return ResponseInterface PSR7 response object
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        // if we want to bypass verification
        if ($this->isByPass()) {
            return $next($request, $response);
        }

        $cookieName = $this->prefix . self::ANGULAR_CSRF_COOKIE;
        $this->validateStorage();

        // Validate POST, PUT, DELETE, PATCH requests
        if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $name = $value = false;

            // check the request header if we're using Angular, otherwise check the request body for the csrf token
            if ($this->useAngularCsrf) {
                $body = $request->getParams();

                if ($request->getMethod() == 'POST' && empty($body)) {
                    $body = $_POST;
                }

                if (array_key_exists('X-' . $cookieName, $body)) {
                    $token = $body['X-' . $cookieName];
                } else {
                    $token = null;
                }

                if (!empty($token)) {
                    $parts = explode(':', $token);
                    $name = trim($parts[0]);
                    $value = trim($parts[1]);
                }
            } else {
                $body = $request->getParsedBody();
                $body = $body ? (array)$body : [];
                $name = isset($body[$this->prefix . '_name']) ? $body[$this->prefix . '_name'] : false;
                $value = isset($body[$this->prefix . '_value']) ? $body[$this->prefix . '_value'] : false;
            }

            if (!$name || !$value || !$this->validateToken($name, $value)) {
                // Need to regenerate a new token, as the validateToken removed the current one.
                $request = $this->generateNewToken($request);

                $failureCallable = $this->getFailureCallable();
                return $failureCallable($request, $response, $next);
            }
        }

        // Generate new CSRF token if persistentTokenMode is false, or if a valid keyPair has not yet been stored
        if (!$this->persistentTokenMode || !$this->loadLastKeyPair()) {
            $request = $this->generateNewToken($request);
        }

        // Enforce the storage limit
        $this->enforceStorageLimit();

        // If using Angular we need to set a specific cookie with the token name/value
        if ($this->useAngularCsrf) {
            $token = $this->getTokenName() . ':' . $this->getTokenValue();
            if (empty($_COOKIE[$cookieName]) || $_COOKIE[$cookieName] != $token) {
                $this->logger->debug('Setting cookie: ' . $token);
                setcookie($cookieName, $token, 0, '/',
                    \RLS\Session::getCookieDomain($request->getServerParams()['HTTP_HOST']));
            }
        }

        return $next($request, $response);
    }

    /**
     * @param $prefix
     * @param $storage
     * @return mixed
     */
    private function validateStorage()
    {
        if (is_array($this->storage)) {
            return $this->storage;
        } elseif ($this->storage instanceof ArrayAccess) {
            return $this->storage;
        } else {
            if (!isset($_SESSION)) {
                throw new RuntimeException('CSRF middleware failed. Session not found.');
            }
            if (!array_key_exists($this->prefix, $_SESSION)) {
                $_SESSION[$this->prefix] = [];
            }
            $this->storage = &$_SESSION[$this->prefix];
            return $this->storage;
        }
    }

    /**
     * Generates a new CSRF token
     *
     * @return array
     */
    public function generateToken()
    {
        // Generate new CSRF token
        $name = uniqid($this->prefix);
        $value = $this->createToken();
        $this->saveToStorage($name, $value);

        $this->keyPair = [
            $this->prefix . '_name' => $name,
            $this->prefix . '_value' => $value
        ];

        return $this->keyPair;
    }

    /**
     * Generates a new CSRF token and attaches it to the Request Object
     *
     * @param  ServerRequestInterface $request PSR7 response object.
     *
     * @return ServerRequestInterface PSR7 response object.
     */
    public function generateNewToken(ServerRequestInterface $request) {

        $pair = $this->generateToken();

        $request = $request->withAttribute($this->prefix . '_name', $pair[$this->prefix . '_name'])
            ->withAttribute($this->prefix . '_value', $pair[$this->prefix . '_value']);

        return $request;
    }

    /**
     * Validate CSRF token from current request
     * against token value stored in $_SESSION
     *
     * @param  string $name  CSRF name
     * @param  string $value CSRF token value
     *
     * @return bool
     */
    public function validateToken($name, $value)
    {
        $token = $this->getFromStorage($name);
        if (function_exists('hash_equals')) {
            $result = ($token !== false && hash_equals($token, $value));
        } else {
            $result = ($token !== false && $token === $value);
        }

        // If we're not in persistent token mode, delete the token.
        if (!$this->persistentTokenMode) {
            $this->removeFromStorage($name);
        }

        return $result;
    }

    /**
     * Create CSRF token value
     *
     * @return string
     */
    protected function createToken()
    {
        return bin2hex(random_bytes($this->strength));
    }

    /**
     * Save token to storage
     *
     * @param  string $name  CSRF token name
     * @param  string $value CSRF token value
     */
    protected function saveToStorage($name, $value)
    {
        $this->storage[$name] = $value;
    }

    /**
     * Get token from storage
     *
     * @param  string      $name CSRF token name
     *
     * @return string|bool CSRF token value or `false` if not present
     */
    protected function getFromStorage($name)
    {
        return isset($this->storage[$name]) ? $this->storage[$name] : false;
    }

    /**
     * Get the most recent key pair from storage.
     *
     * @return string[]|null Array containing name and value if found, null otherwise
     */
    protected function getLastKeyPair()
    {
        // Use count, since empty ArrayAccess objects can still return false for `empty`
        if (count($this->storage) < 1) {
            return null;
        }

        foreach ($this->storage as $name => $value) {
            continue;
        }

        $keyPair = [
            $this->prefix . '_name' => $name,
            $this->prefix . '_value' => $value
        ];

        return $keyPair;
    }

    /**
     * Load the most recent key pair in storage.
     *
     * @return bool `true` if there was a key pair to load in storage, false otherwise.
     */
    protected function loadLastKeyPair()
    {
        $this->keyPair = $this->getLastKeyPair();

        if ($this->keyPair) {
            return true;
        }

        return false;
    }

    /**
     * Remove token from storage
     *
     * @param  string $name CSRF token name
     */
    protected function removeFromStorage($name)
    {
        $this->storage[$name] = ' ';
        unset($this->storage[$name]);
    }

    /**
     * Remove the oldest tokens from the storage array so that there
     * are never more than storageLimit tokens in the array.
     *
     * This is required as a token is generated every request and so
     * most will never be used.
     */
    protected function enforceStorageLimit()
    {
        if ($this->storageLimit < 1) {
            return;
        }

        // $storage must be an array or implement Countable and Traversable
        if (!is_array($this->storage)
            && !($this->storage instanceof Countable && $this->storage instanceof Traversable)
        ) {
            return;
        }

        if (is_array($this->storage)) {
            while (count($this->storage) > $this->storageLimit) {
                array_shift($this->storage);
            }
        } else {
            // array_shift() doesn't work for ArrayAccess, so we need an iterator in order to use rewind()
            // and key(), so that we can then unset
            $iterator = $this->storage;
            if ($this->storage instanceof \IteratorAggregate) {
                $iterator = $this->storage->getIterator();
            }
            while (count($this->storage) > $this->storageLimit) {
                $iterator->rewind();
                unset($this->storage[$iterator->key()]);
            }
        }
    }

    /**
     * Getter for failureCallable
     *
     * @return callable|\Closure
     */
    public function getFailureCallable()
    {
        if (is_null($this->failureCallable)) {
            $this->failureCallable = function (ServerRequestInterface $request, ResponseInterface $response, $next) {
                $body = new \Slim\Http\Body(fopen('php://temp', 'r+'));
                $body->write('Failed CSRF check!');
                return $response->withStatus(400)->withHeader('Content-type', 'text/plain')->withBody($body);
            };
        }
        return $this->failureCallable;
    }

    /**
     * Setter for failureCallable
     *
     * @param mixed $failureCallable Value to set
     * @return $this
     */
    public function setFailureCallable($failureCallable)
    {
        $this->failureCallable = $failureCallable;
        return $this;
    }

    /**
     * Setter for persistentTokenMode
     *
     * @param bool $persistentTokenMode True to use the same token throughout the session (unless there is a validation error),
     * false to get a new token with each request.
     * @return $this
     */
    public function setPersistentTokenMode($persistentTokenMode)
    {
        $this->persistentTokenMode = $persistentTokenMode;
        return $this;
    }

    /**
     * Setter for storageLimit
     *
     * @param integer $storageLimit Value to set
     * @return $this
     */
    public function setStorageLimit($storageLimit)
    {
        $this->storageLimit = (int)$storageLimit;
        return $this;
    }

    /**
     * Getter for persistentTokenMode
     *
     * @return bool
     */
    public function getPersistentTokenMode()
    {
        return $this->persistentTokenMode;
    }

    /**
     * @return string
     */
    public function getTokenName()
    {
        return isset($this->keyPair[$this->getTokenNameKey()]) ? $this->keyPair[$this->getTokenNameKey()] : null;
    }

    /**
     * @return string
     */
    public function getTokenValue()
    {
        return isset($this->keyPair[$this->getTokenValueKey()]) ? $this->keyPair[$this->getTokenValueKey()] : null;
    }

    /**
     * Use standard mode (default/false) or use Angular's CSRF protection (true)
     *
     * @param bool $useAngularCsrf
     * @return Guard
     */
    public function setUseAngluarCsrf($useAngularCsrf)
    {
        $this->useAngularCsrf = (bool) $useAngularCsrf;
        return $this;
    }

    /**
     * @param LoggerInterface $logger
     * @return Guard
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @param bool $byPass
     * @return $this
     */
    public function setByPass(bool $byPass)
    {
        $this->byPass = $byPass;
        return $this;
    }

    /**
     * @return bool
     */
    public function isByPass()
    {
        return $this->byPass;
    }
}
