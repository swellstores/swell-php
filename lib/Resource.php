<?php

namespace Swell;

/**
 * Represents a client resource
 * Base class to represent client response data
 */
class Resource extends \ArrayIterator
{
    /**
     * @var string
     */
    protected $url;

    /**
     * @var array
     */
    protected $links;

    /**
     * @var array
     */
    protected $link_data = array();

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var Client
     */
    protected static $client;

    /**
     * @var array
     */
    protected static $client_links = array();

    /**
     * @param  mixed $result
     * @param  Client $client
     */
    public function __construct($result, $client = null)
    {
        if ($client) {
            self::$client = $client;
        }
        if (isset($result['$url'])) {
            $this->url = $result['$url'];
            if (isset($result['$links'])) {
                $this->result_links = $result['$links'];
                self::$client_links[$this->url] = $result['$links'];
                unset($result['$links']);
            }
        }

        if ((array)$result['$data'] === $result['$data']) {
            ksort($result['$data']);
            parent::__construct($result['$data']);
            unset($result['$data']);
        }

        $this->links =& $this->links() ?: array();

        $this->headers = $result;
    }

    /**
     * Create a resource instance from request result
     *
     * @return Resource
     */
    public static function instance($result, $client = null)
    {
        if ((array)$result['$data'] === $result['$data']
            && isset($result['$data']['results'])
            && isset($result['$data']['count'])) {
            return new Collection($result, $client);
        }

        return new Record($result, $client);
    }

    /**
     * Convert instance to a string, represented by url
     *
     * @return string
     */
    public function __toString()
    {
        return (string)$this->url;
    }

    /**
     * Get resource url
     *
     * @return mixed
     */
    public function url()
    {
        return $this->url;
    }

    /**
     * Get resource data
     *
     * @param  bool $raw
     * @return mixed
     */
    public function data($raw = false)
    {
        $data = $this->getArrayCopy();

        if ($raw) {
            foreach ($data as $key => $val) {
                if ($val instanceof Resource) {
                    $data[$key] = $val->data($raw);
                }
            }
            foreach ($this->link_data as $key => $val) {
                if ($val instanceof Resource) {
                    $data[$key] = $val->data($raw);
                }
            }
        }

        return $data;
    }

    /**
     * Get the resource client object
     *
     * @return Client
     */
    public function client()
    {
        return self::$client;
    }

    /**
     * Get links for this resource
     *
     * @return array
     */
    public function & links()
    {
        if (!isset(self::$client_links[$this->url])) {
            self::$client_links[$this->url] = array();
        }
        return self::$client_links[$this->url];
    }

    /**
     * Get link data for this resource
     *
     * @return array
     */
    public function & link_data()
    {
        return $this->link_data;
    }

    /**
     * Get original request headers for this resource
     *
     * @return array
     */
    public function headers()
    {
        return $this->headers;
    }

    /**
     * Execute a GET request on this resource
     *
     * @param  mixed $scope
     * @param  mixed $data
     * @return mixed
     */
    public function get($scope = null, $data = null)
    {
        return $this->request('get', $scope, $data);
    }

    /**
     * Execute a PUT request on this resource
     *
     * @param  mixed $scope
     * @param  mixed $data
     * @return mixed
     */
    public function put($scope = null, $data = null)
    {
        return $this->request('put', $scope, $data);
    }

    /**
     * Execute a POST request on this resource
     *
     * @param  mixed $scope
     * @param  mixed $data
     * @return mixed
     */
    public function post($scope = null, $data = null)
    {
        return $this->request('post', $scope, $data);
    }

    /**
     * Execute a DELETE request on this resource
     *
     * @param  mixed $scope
     * @param  mixed $data
     * @return mixed
     */
    public function delete($scope = null, $data = null)
    {
        return $this->request('delete', $scope, $data);
    }

    /**
     * Execute a request on this resource
     *
     * @param  mixed $scope
     * @param  mixed $data
     * @return mixed
     */
    public function request($method, $scope = null, $data = null)
    {
        if (is_array($scope)) {
            $data = $scope;
            $scope = null;
        }
        $url = $scope ? $this->url.'/'.ltrim($scope, '/') : $this->url;
        $result = self::$client->request($method, $url, $data);
        if (!$scope && $result instanceof Resource) {
            // TODO: how should POST be handled here?
            foreach ($result->data() as $key => $value) {
                self::offsetSet($key, $value);
            }
        }
        return $scope ? $result : $this;
    }



    /**
     * Dump the contents of this resource
     *
     * @return mixed
     */
    public function dump($return = false)
    {
        return print_r($this->getArrayCopy(), $return);
    }

    /**
     * Dump resource links
     *
     * @param  array $links
     */
    public function dump_links($links = null)
    {
        if ($links === null) {
            $links = $this->links;
        }
        $dump = array();
        foreach ($links as $key => $link) {
            if (isset($link['url'])) {
                $dump[$key] = $link['url'];
            }
            if ($key === '*') {
                $dump = array_merge($dump, $this->dump_links($link));
            } else if (isset($link['links'])) {
                $dump[$key] = $this->dump_links($link['links']);
            }
        }

        return $dump;
    }
}
