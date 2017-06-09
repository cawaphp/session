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

namespace Cawa\Session\Orm;

use Cawa\Session\SessionFactory;

trait SessionTrait
{
    use SessionFactory;

    /**
     * @param $class
     * @param bool $autoload
     *
     * @return array
     */
    private function classUsedRecursive($class, $autoload = true)
    {
        $traits = [];
        do {
            $traits = array_merge(class_uses($class, $autoload), $traits);
        } while ($class = get_parent_class($class));
        foreach ($traits as $trait => $same) {
            $traits = array_merge(class_uses($trait, $autoload), $traits);
        }

        return array_unique($traits);
    }

    /**
     * @param string $name
     */
    public function sessionSave(string $name = null)
    {
        if (!$name) {
            $name = get_called_class();
        }

        $data = $this;
        if (in_array(SessionSleepTrait::class, $this->classUsedRecursive($this))) {
            $data = $this->sessionSleep();
        }

        self::session()->set($name, $data);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public static function sessionExists(string $name = null) : bool
    {
        if (!$name) {
            $name = get_class();
        }

        return self::session()->exist($name);
    }

    /**
     * @param string $name
     *
     * @return null|object
     */
    public static function sessionReload(string $name = null)
    {
        if (!$name) {
            $name = get_called_class();
        }

        $data = self::session()->get($name);

        if (!$data) {
            return false;
        }

        $class = get_called_class();

        if (method_exists($class, 'sessionWakeup')) {
            $data = $class::sessionWakeup($data);
        }

        return $data;
    }

    /**
     * @param string|null $name
     *
     * @return bool
     */
    public function sessionRemove(string $name = null) : bool
    {
        if (!$name) {
            $name = get_called_class();
        }

        if (!self::session()->exist($name)) {
            return false;
        }

        self::session()->remove($name);

        return true;
    }
}
