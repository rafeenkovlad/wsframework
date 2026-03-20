<?php

namespace WsFramework\GlobalData;

use Exception;

interface DataInterface
{
    /**
     * @param string $key
     * @param $value
     * @return bool
     * @throws Exception
     */
    public function add(string $key, $value): bool;

    /**
     * @param string $key
     * @param mixed $oldValue
     * @param mixed $newValue
     * @return bool
     * @throws Exception
     */
    public function cas(string $key, mixed $oldValue, mixed $newValue): bool;

    /**
     * @param string $key
     * @param int $step
     * @return bool
     * @throws Exception
     */
    public function increment(string $key, int $step = 1): bool;


    /**
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    public function get(string $key): mixed;

    /**
     * @param string $key
     * @return void
     */
    public function unset(string $key): void;

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * @param string $key
     * @return bool
     */
    public function isset(string $key): bool;
}
