<?php

namespace Swell;

class CacheException extends \Exception {}

class WriteException extends CacheException {}

class Cache
{
    /**
     * @var array
     */
    protected $params;

    /**
     * @static string
     */
    public static $default_write_perms = 0644;

    /**
     * @static string
     */
    public static $default_index_limit = 1000;

    /**
     * @var array
     */
    public $versions = array();

    /**
     * @var array
     */
    public $indexes = array();

    /**
     * @var string
     */
    public $env = null;

    /**
     * @param  string $client_id
     * @param  string $client_key
     * @param  array $options
     * @return void
     */
    function __construct($client_id, $options)
    {
        if (is_string($options)) {
            $options = array('path' => $options);
        }
        $this->params = array(
            'client_id' => $client_id,
            'path' => $options['path'],
            'write_perms' => isset($options['write_perms'])
                ? $options['write_perms'] : self::$default_write_perms,
            'index_limit' => isset($options['index_limit'])
                ? $options['index_limit'] : self::$default_index_limit
        );
    }

    /**
     * Get result from cache by url/data
     *
     * @param  string $url
     * @param  mixed $data
     * @return mixed
     */
    public function get($url, $data = null)
    {
        $cache_key = $this->get_key($url, $data);

        if ($json = $this->get_cache($cache_key, 'result')) {
            $result = json_decode($json, true);

            // Ensure cache_key exists in index
            $this->get_index();

            if (isset($result['$collection'])) {
                $collection = $result['$collection'];
                if (isset($this->indexes[$collection][$cache_key])) {
                    return $result;
                }
            }

            // Not found in proper index, then clear?
            foreach ($this->result_collections($result) as $collection) {
                $this->clear_indexes(array("{$collection}" => $cache_key));
            }
        }

        return null;
    }

    /**
     * Set environment id
     *
     * @param  string $env
     * @return void
     */
    public function set_env($env)
    {
        $this->env = $env;
    }

    /**
     * Get a cache key
     *
     * @param  string $url
     * @param  mixed $data
     * @return string
     */
    public function get_key($url, $data = null)
    {
        return md5(serialize(array(trim($url, '/'), $data)));
    }

    /**
     * Get path to a cache file
     *
     * @return string
     */
    public function get_path()
    {
        $cache_path = rtrim($this->params['path'], '/')
            .'/client'.'.'.$this->params['client_id'];

        if ($this->env) {
            $cache_path .= '_'.$this->env;
        }

        foreach (func_get_args() as $arg) {
            $cache_path .= '.'.$arg;
        }

        return $cache_path;
    }

    /**
     * Get cache version info
     *
     * @return array
     */
    public function get_versions()
    {
        if (!$this->versions) {
            $this->versions = array();
            if ($json = $this->get_cache('versions')) {
                $this->versions = json_decode($json, true);
            }
        }

        return $this->versions;
    }

    /**
     * Get cache index info
     *
     * @return array
     */
    public function get_index()
    {
        if (!$this->indexes) {
            $this->indexes = array();
            if ($json = $this->get_cache('index')) {
                $this->indexes = json_decode($json, true);
            }
        }

        return $this->indexes;
    }

    /**
     * Put cache result in file system cache atomicly
     *
     * @param  string $url
     * @param  mixed $data
     * @param  mixed $result
     * @return void
     */
    public function put($url, $data, $result)
    {
        if (!array_key_exists('$data', $result)) {
            $result['$data'] = null; // Allows for null response
        }

        $this->get_versions();

        $cache_content = $result;
        $cache_content['$cached'] = true;

        $cache_key = $this->get_key($url, $data);
        $cache_path = $this->get_path($cache_key, 'result');
        if ($size = $this->write_cache($cache_path, $cache_content)) {
            if (isset($result['$cached'])) {
                $cached = $result['$cached'];
                foreach ($this->result_collections($result) as $collection) {
                    // Collection may not be cacheable
                    if (!isset($cached[$collection]) && !isset($this->versions[$collection])) {
                        continue;
                    }
                    $this->put_index($collection, $cache_key, $size);
                    if (isset($cached[$collection])) {
                        $this->put_version($collection, $cached[$collection]);
                    }
                }
            }
        }
    }

    /**
     * Update/write the cache index
     *
     * @param  string $collection
     * @param  string $key
     * @param  string $size
     * @return int
     */
    public function put_index($collection, $key, $size)
    {
        $this->get_index();

        // Limit size of index per client/collection
        if (isset($this->indexes[$collection])) {
            if (count($this->indexes[$collection]) >= $this->params['index_limit']) {
                $this->truncate_index($collection);
            }
        }

        $this->indexes[$collection][$key] = $size;

        $index_path = $this->get_path('index');
        return $this->write_cache($index_path, $this->indexes);
    }

    /**
     * Remove an entry from cache base on url and data
     * This is mostly used for caching variables as opposed to client results
     *
     * @param  string $url
     * @param  mixed $data
     * @return void
     */
    public function remove($url, $data = null)
    {
        $cache_key = $this->get_key($url, $data);
        $cache_path = $this->get_path($cache_key, 'result');
        $this->clear_cache($cache_path);
    }

    /**
     * Truncate the cache index (usually by 1)
     * Prefers to eject the smallest cache content first
     *
     * @param  string $collection
     * @return bool
     */
    public function truncate_index($collection)
    {
        $this->get_index();
        asort($this->indexes[$collection]);
        reset($this->indexes[$collection]);
        $key = key($this->indexes[$collection]);

        $invalid = array("{$collection}" => $key);
        return $this->clear_indexes($invalid);
    }

    /**
     * Update/write the cache version file
     *
     * @param  string $collection
     * @param  string $cache_key
     * @return int
     */
    public function put_version($collection, $version)
    {
        if ($version) {
            $this->get_versions();
            $this->versions[$collection] = $version;
            $version_path = $this->get_path('versions');
            return $this->write_cache($version_path, $this->versions);
        }
    }

    /**
     * Clear all cache entries made invalid by result
     *
     * @param  string $url
     * @param  mixed $data
     * @param  mixed $result
     * @return void
     */
    public function clear($result)
    {
        $invalid = array();
        $this->get_versions();

        if (isset($result['$cached'])) {
            foreach ((array)$result['$cached'] as $collection => $ver) {
                if (!isset($this->versions[$collection]) || $ver != $this->versions[$collection]) {
                    $this->put_version($collection, $ver);
                    $invalid[$collection] = true;
                    // Hack to make admin.settings affect other api.settings
                    // TODO: figure out how to do this on the server side
                    if ($collection === 'admin.settings') {
                        foreach ((array)$this->versions as $vcoll => $vv) {
                            if (preg_match('/\.settings$/', $vcoll)) {
                                $invalid[$vcoll] = true;
                            }
                        }
                    }
                }
            }
        }
        if (!empty($invalid)) {
            $this->clear_indexes($invalid);
        }
    }

    /**
     * Clear cache index for a certain collection
     *
     * @param  array $invalid
     * @return void
     */
    public function clear_indexes($invalid)
    {
        if (empty($invalid)) {
            return;
        }
        $this->get_index();
        foreach ($invalid as $collection => $key) {
            // Clear all indexes per collection
            if (isset($this->indexes[$collection])) {
                if ($key === true) {
                    foreach ($this->indexes[$collection] as $cache_key => $size) {
                        $cache_path = $this->get_path($cache_key, 'result');
                        $this->clear_cache($cache_path);
                        unset($this->indexes[$collection][$cache_key]);
                    }
                }
                // Clear a single index element by key
                else if ($key && isset($this->indexes[$collection][$key])) {
                    $cache_path = $this->get_path($key, 'result');
                    $this->clear_cache($cache_path);
                    unset($this->indexes[$collection][$key]);
                }
            }
        }
        $index_path = $this->get_path('index');
        $this->write_cache($index_path, $this->indexes);
    }

    /**
     * Get cache content
     *
     * @return string
     */
    public function get_cache()
    {
        $args = func_get_args();
        $cache_path = call_user_func_array(array($this, 'get_path'), $args);

        if (is_file($cache_path)) {
            return file_get_contents($cache_path);
        }
        return null;
    }

    /**
     * Write to cache atomically
     *
     * @param  string $cache_path
     * @param  mixed $content
     * @return int
     */
    public function write_cache($cache_path, $content)
    {
        $cache_content = json_encode($content);
        $cache_size = strlen($cache_content);

        $temp = tempnam($this->params['path'], 'temp');
        if (!($f = @fopen($temp, 'wb'))) {
            $temp = $this->params['path'].'/'.uniqid('temp');
            if (!($f = @fopen($temp, 'wb'))) {
                throw new WriteException('Unable to write temporary file '.$temp);
            }
        }

        fwrite($f, $cache_content);
        fclose($f);

        if (!rename($temp, $cache_path)) {
            unlink($cache_path);
            rename($temp, $cache_path);
        }

        chmod($cache_path, $this->params['write_perms']);

        return $cache_size;
    }

    /**
     * Clear a cache path
     *
     * @param  string $cache_path
     * @return void
     */
    public function clear_cache($cache_path)
    {
        @unlink($cache_path);
    }

    /**
     * Get array of collections affected by a result
     *
     * @param  array $result
     * @return array
     */
    public function result_collections($result)
    {
        // Combine $collection and $expanded headers
        $collections = isset($result['$collection'])
            ? array($result['$collection'])
            : array();

        if (isset($result['$expanded'])) {
            foreach ($result['$expanded'] as $expanded_collection) {
                $collections[] = $expanded_collection;
            }
        }

        return $collections;
    }
}
