<?php
namespace QinGuard\Driver\Db;

interface DbInterface
{
    /**
     * 获取数据
     * @param string $key
     * @return string|bool
     */
    public function get(string $key, string $default = null);

    /**
     * 新增或修改数据
     * @param string $key
     * @return bool
     */
    public function set(string $key, string $value, int $ttl = null);

    /**
     * 新增或修改数据
     * @param string $key
     * @return bool
     */
    public function delete(string $key);

    /**
     * 新增或修改数据
     * @param string $key
     * @return bool
     */
    public function has(string $key);
}
