<?php

namespace Swell;

class Record extends Resource
{
    /**
     * @param  array $result
     * @param  Client $client
     */
    public function __construct($result, $client = null)
    {
        parent::__construct($result, $client);
    }

    /**
     * Check if record field value exists
     *
     * @param  string $index
     * @return bool
     */
    public function offsetExists($index)
    {
        if (!parent::offsetExists($index) || parent::offsetGet($index) === null) {
            if (isset($this->links[$index]['url']) || $index === '$links') {
                return true;
            }
            return false;
        }
        return true;
    }

    /**
     * Get record field value
     *
     * @param  string $field
     * @return mixed
     */
    public function offsetGet($field)
    {
        if ($field === null) {
            return;
        }
        $link_result = null;
        if (isset($this->links[$field]['url']) || $field === '$links') {
            $link_result = $this->offset_get_link($field);
        }
        if ($link_result !== null) {
            return $link_result;
        } else {
            return $this->offset_get_result($field);
        }
    }

    /**
     * Set record field value
     *
     * @param  string $field
     * @param  mixed $value
     * @return void
     */
    public function offsetSet($field, $value)
    {
        if (isset($this->links[$field]['url'])) {
            $this->link_data[$field] = $value;
        } else {
            parent::offsetSet($field, $value);
        }
    }

    /**
     * Get record field value as a link result
     *
     * @param  string $field
     * @return Resource
     **/
    public function offset_get_link($field)
    {
        $header_links = $this->links;

        if ($field === '$links') {
            $links = array();
            foreach ($header_links['*'] ?: $header_links as $key => $link) {
                if ($link['url']) {
                    $links[$key] = $this->link_url($key);
                }
            }
            return $links;
        }
        if (!array_key_exists($field, $this->link_data)) {
            if (parent::offsetExists($field)) {
                $value = parent::offsetGet($field);
                // Hack around link formulas
                // TODO: find a better way to differentiate from expanded links
                if ($header_links[$field]['url'] === '{'.$field.'}') {
                    unset($this[$field]);
                    return $this->offset_get_link($field);
                }
                if (is_array($value)) {
                    $this->link_data[$field] = Resource::instance(array(
                        '$url' => $this->link_url($field),
                        '$data' => $value,
                        '$links' => isset($header_links[$field]['links'])
                            ? $header_links[$field]['links'] : null
                    ));
                } else {
                    $this->link_data[$field] = $value;
                }
                return $this->link_data[$field];
            } else {
                // Avoid storing too much memory from links
                $mem_start = memory_get_usage();
                $link_url = $this->link_url($field);
                $link_data = array();
                if (isset(self::$client->default_limit)) {
                    $link_data['limit'] = self::$client->default_limit;
                }
                $result = $this->client()->get($link_url, $link_data);
                $mem_total = memory_get_usage() - $mem_start;
                // Max one megabyte
                if ($mem_total < 1048576) {
                    $this->link_data[$field] = $result;
                }
                return $result;
            }
        } else {
            return $this->link_data[$field];
        }

        return null;
    }

    /**
     * Get record field value as a record result
     *
     * @param  string $field
     * @return mixed
     **/
    public function offset_get_result($field)
    {
        $data_links = null;
        if (isset($this->links['*'])) {
            $data_links = $this->links['*'];
        } else if (isset($this->links[$field]['links'])) {
            $data_links = $this->links[$field]['links'];
        }

        $data_value = parent::offsetExists($field) ? parent::offsetGet($field) : null;

        if (is_array($data_value) && !empty($data_value)) {
            $collection = isset($this->headers['$collection']) ? $this->headers['$collection'] : null;
            $data_record = new Record(array(
                '$url' => $this->link_url($field),
                '$data' => $data_value,
                '$links' => $data_links,
                '$collection' => $collection
            ));
            $this->offsetSet($field, $data_record);
            return $data_record;
        }
        else if (!isset($data_value)) {
            // Find object within array by id
            $data = $this->getArrayCopy();
            foreach ($data as $data_val) {
                if (isset($data_val['id']) && $data_val['id'] === $field) {
                    $data_value = $data_val;
                    break;
                }
            }
        }

        return $data_value;
    }

    /**
     * Get the current element while iterating over array fields
     *
     * @return mixed
     */
    public function current()
    {
        return $this->offset_get_result($this->key());
    }

    /**
     * Build relative url for a link field
     *
     * @param  string $field
     * @param  string $id
     */
    public function link_url($field, $id = null)
    {
        if ($qpos = strpos($this->url, '?')) {
            $url = substr($this->url, 0, $qpos);
        } else {
            $url = $this->url;
        }

        if ($id) {
            $url = preg_replace('/[^\/]+$/', $id, rtrim($url, "/"));
        }

        return $url."/".$field;
    }

    /**
     * Dump raw record values
     *
     * @param  bool $return
     * @param  bool $print
     */
    public function dump($return = false, $print = true, $depth = 1)
    {
        $dump = $this->data();
        $links = $this->links;

        foreach ($links as $key => $link) {
            if ($depth < 1) {
                try {
                    $related = $this->{$key};
                } catch (ServerException $e) {
                    $related = array('$error' => $e->getMessage());
                }

                if ($related instanceof Resource) {
                    $dump[$key] = $related->dump(true, false, $depth+1);
                } else {
                    $dump[$key] = $related;
                }
            }
        }
        if ($links) {
            $dump['$links'] = $this->dump_links($links);
        }

        return $print ? print_r($dump, $return) : $dump;
    }
}
