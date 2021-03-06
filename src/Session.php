<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Cawa\Session;

use Cawa\App\HttpFactory;
use Cawa\Core\DI;
use Cawa\Events\DispatcherFactory;
use Cawa\Events\TimerEvent;
use Cawa\Http\Cookie;
use Cawa\Session\SessionStorage\AbstractStorage;

class Session
{
    use HttpFactory;
    use DispatcherFactory;

    /**
     * @var AbstractStorage
     */
    private $storage;

    /**
     * SessionTrait constructor.
     */
    public function __construct()
    {
        // name
        $name = DI::config()->getIfExists('session/name') ?? 'SID';
        $this->setName($name);

        // storage
        $class = DI::config()->getIfExists('session/storage/class') ?? '\\Cawa\\Session\\SessionStorage\\FileStorage';
        $args = DI::config()->getIfExists('session/storage/arguments') ?? [];

        $this->storage = new $class(...$args);

        // disable internal session handler
        $callable = function () {
            // this exception will not be displayed, instead "FatalErrorException"
            // with "Error: session_start(): Failed to initialize storage module: user (path: /var/lib/php/sessions)"
            throw new \LogicException('Cannot used internal session in Сáша HttpApp');
        };
        session_set_save_handler($callable, $callable, $callable, $callable, $callable, $callable, $callable);
    }

    /**
     * @return bool
     */
    private function haveCookie() : bool
    {
        return !is_null(self::request()->getCookie($this->name));
    }

    /**
     *
     */
    public function init()
    {
        if (!self::$init) {
            $event = new TimerEvent('session.open');
            $this->storage->open();
            self::emit($event);

            if (self::request()->getCookie($this->name)) {
                $this->id = self::request()->getCookie($this->name)->getValue();
            }

            if (!$this->id) {
                $this->create();
            } else {
                $event = new TimerEvent('session.read');
                $readData = $this->storage->read($this->id);

                $maxDuration = $this->storage->getDuration();

                if ($readData === false) {
                    $this->create();
                } else {
                    list($this->data, $this->startTime, $this->accessTime, $length) = $readData;
                }

                if (isset($length)) {
                    $event->setData([
                        'length' => $length,
                        'startTime' => date('Y-m-d H:i:s', $this->startTime),
                        'accessTime' => date('Y-m-d H:i:s', $this->accessTime),
                    ]);
                }
                self::emit($event);

                if ($readData !== false && $this->accessTime + $maxDuration < time()) {
                    $this->storage->destroy($this->id);
                    $this->create();
                }
            }

            $this->addHeaders();

            self::dispatcher()->addListener('app.end', function () {
                $this->save();
            });

            self::$init = true;
        }
    }

    /**
     */
    private function create()
    {
        $this->id = md5(uniqid((string) rand(), true));
        $this->accessTime = null;
        $this->startTime = time();
        $this->data = [];
        self::response()->addCookie(new Cookie($this->name, $this->id));
    }

    /**
     * add expiration headers to avoid caching.
     */
    private function addHeaders()
    {
        self::response()->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate'); // HTTP 1.1
        self::response()->addHeader('Pragma', 'no-cache'); // HTTP 1.0
        self::response()->addHeader('Expires', '-1'); // Proxies
    }

    /**
     * is the session is init and data loaded.
     *
     * @var bool
     */
    protected static $init = false;

    /**
     * @return bool
     */
    public function isStarted() : bool
    {
        return self::$init;
    }

    /**
     * is the data have changed.
     *
     * @var bool
     */
    private $changed = false;

    /**
     * The session id.
     *
     * @var string
     */
    private $id;

    /**
     * @return string
     */
    public function getId() : string
    {
        return $this->id;
    }

    /**
     * The name of the session cookie.
     *
     * @var string
     */
    private $name;

    /**
     * Gets the name of the cookie.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return $this|self
     */
    public function setName(string $name) : self
    {
        // from PHP source code
        if (preg_match("/[=,; \t\r\n\013\014]/", $name)) {
            throw new \InvalidArgumentException(sprintf('The cookie name "%s" contains invalid characters.', $name));
        }

        if (empty($name)) {
            throw new \InvalidArgumentException('The cookie name cannot be empty.');
        }

        $this->name = $name;

        return $this;
    }

    /**
     * @var int
     */
    protected $startTime;

    /**
     * @return int
     */
    public function getStartTime() : int
    {
        return $this->startTime;
    }

    /**
     * @var int
     */
    protected $accessTime;

    /**
     * @return int
     */
    public function getAccessTime() : int
    {
        return $this->accessTime;
    }

    /**
     * @var array
     */
    private $data = [];

    /**
     * @return array
     */
    public function getData() : array
    {
        if (self::$init == false) {
            $this->init();
        }

        return $this->data;
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function get(string $name)
    {
        if (!$this->haveCookie()) {
            return null;
        }

        if (self::$init == false) {
            $this->init();
        }

        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    public function getFlush(string $name)
    {
        if (!$this->haveCookie()) {
            return null;
        }

        $return = $this->get($name);
        $this->remove($name);

        return $return;
    }

    /**
     * @param string $name
     * @param $value
     *
     * @return $this|self
     */
    public function set(string $name, $value) : self
    {
        if (self::$init == false) {
            $this->init();
        }

        if (array_key_exists($name, $this->data) && $this->data[$name] === $value) {
            return $this;
        }

        $this->changed = true;

        $this->data[$name] = $value;

        return $this;
    }

    /**
     * @param string $name
     * @param $value
     *
     * @return $this|self
     */
    public function push(string $name, $value) : self
    {
        $data = $this->get($name) ?: [];
        $data[] = $value;

        return $this->set($name, $data);
    }

    /**
     * @param string $name
     * @param $value
     *
     * @return $this|self
     */
    public function merge(string $name, $value) : self
    {
        $data = $this->get($name) ?: [];

        return $this->set($name, array_merge($data, $value));
    }

    /**
     * @param string $name
     *
     * @return $this|self
     */
    public function remove(string $name) : self
    {
        if (self::$init == false) {
            $this->init();
        }

        if (!isset($this->data[$name])) {
            return $this;
        }

        $this->changed = true;

        unset($this->data[$name]);

        return $this;
    }

    /**
     * @return bool
     */
    public function destroy() : bool
    {
        if (self::$init == false) {
            $this->init();
        }

        $event = new TimerEvent('session.destroy');

        self::$init = false;

        $return = $this->storage->destroy($this->id);
        self::response()->clearCookie(new Cookie($this->name));

        self::emit($event);

        return $return;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function exist(string $name) : bool
    {
        if (self::$init == false) {
            $this->init();
        }

        return isset($this->data[$name]) ? true : false;
    }

    /**
     * @return bool
     */
    public function save() : bool
    {
        if (!self::$init) {
            return true;
        }

        if (!$this->accessTime) {
            $this->accessTime = time();
        }

        $ttl = DI::config()->getIfExists('session/refreshTtl') ?? 60;

        if (!$this->changed && $this->accessTime + $ttl < time()) {
            $this->accessTime = time();

            $event = new TimerEvent('session.touch');
            $return = $this->storage->touch($this->id, $this->data, $this->startTime, $this->accessTime);
        } elseif ($this->changed) {
            $this->accessTime = time();

            $event = new TimerEvent('session.write');
            $return = $this->storage->write($this->id, $this->data, $this->startTime, $this->accessTime);
        } else {
            return true;
        }

        $event->setData(['length' => $return]);
        self::emit($event);

        return $return === false ? false : true;
    }
}
