<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types = 1);

namespace Cawa\Session\SessionStorage;

use Cawa\Net\Uri;

class RedisStorage extends AbstractStorage
{
    /**
     * @var Uri
     */
    private $config;

    /**
     * @var string
     */
    private $prefix;

    /**
     * @var \Redis
     */
    protected $client;

    /**
     * @param string $config
     * @param string|null $prefix
     *
     * @internal param string $path
     */
    public function __construct(string $config, string $prefix = null)
    {
        $this->config = new Uri($config);
        $this->prefix = $prefix;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function getKey(string $key) : string
    {
        return ($this->prefix ? $this->prefix . ':' : '') . $key;
    }

    /**
     * {@inheritdoc}
     */
    public function open() : bool
    {
        $this->client = new \Redis();
        $this->client->pconnect($this->config->getHost(), $this->config->getPort(), 2.5);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close() : bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $id)
    {
        if (!($content = $this->client->get($this->getKey($id)))) {
            return false;
        }

        $length = strlen($content);
        $saveData = self::unserialize($content);

        return [$saveData['data'], $saveData['startTime'], $saveData['accessTime'], $length];
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $id, array $data, int $startTime, int $accessTime)
    {
        $saveData = [
            'startTime' => $startTime,
            'accessTime' => $accessTime,
            'data' => $data,
        ];

        $stringData = self::serialize($saveData);
        if ($this->client->setex($this->getKey($id), $this->getDuration(), $stringData)) {
            return false;
        } else {
            return strlen($stringData);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $id) : bool
    {
        return $this->client->del($this->getKey($id)) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function touch(string $id, array $data, int $startTime, int $accessTime)
    {
        return $this->write($id, $data, $startTime, $accessTime);
    }
}
