<?php

namespace Swell;

class ClientException extends \Exception
{
}

class Client
{
  /**
   * @var array
   */
  protected $params;

  /**
   * @var Connection
   */
  protected $server;

  /**
   * @var string
   */
  protected $session;

  /**
   * @var bool
   */
  protected $authed;

  /**
   * @var string
   */
  protected $end_client_id;

  /**
   * @var Cache
   */
  public $cache;

  /**
   * @static string
   */
  public static $default_host = "api.swell.store";

  /**
   * @static int
   */
  public static $default_port = 8443;

  /**
   * @static string
   */
  public static $default_rescue_host = "rescue.api.swell.store";

  /**
   * @static int
   */
  public static $default_rescue_port = 8443;

  /**
   * @param  string $client_id
   * @param  string $client_key
   * @param  array $options
   * @return void
   */
  function __construct($client_id, $client_key = null, $options = null)
  {
    if (is_array($client_id)) {
      $options = $client_id;
      $client_id = null;
    } elseif (is_array($client_key)) {
      $options = $client_key;
      $client_key = null;
    }
    if ($client_id === null) {
      if (isset($options["client_id"])) {
        $client_id = $options["client_id"];
      } elseif (isset($options["id"])) {
        $client_id = $options["id"];
      }
    }
    if ($client_key === null) {
      if (isset($options["client_key"])) {
        $client_key = $options["client_key"];
      } elseif (isset($options["key"])) {
        $client_key = $options["key"];
      }
    }
    if (!isset($options["session"]) || $options["session"] === true) {
      if (
        isset($options["session"]) &&
        $options["session"] === true &&
        session_id() === ""
      ) {
        session_start();
      }
      $options["session"] = session_id();
    }
    if (isset($options["rescue"]) && $options["rescue"] !== false) {
      $options["rescue"] = [
        "host" =>
          isset($options["rescue"]["host"]) && $options["rescue"]["host"]
            ? $options["rescue"]["host"]
            : self::$default_rescue_host,
        "port" =>
          isset($options["rescue"]["port"]) && $options["rescue"]["port"]
            ? $options["rescue"]["port"]
            : self::$default_rescue_port,
      ];
    }

    $this->params = [
      "client_id" => $client_id,
      "client_key" => $client_key,
      "host" => isset($options["host"])
        ? $options["host"]
        : self::$default_host,
      "port" => isset($options["port"])
        ? $options["port"]
        : self::$default_port,
      "verify_cert" => isset($options["verify_cert"])
        ? $options["verify_cert"]
        : true,
      "version" => isset($options["version"]) ? $options["version"] : 1,
      "session" => isset($options["session"]) ? $options["session"] : null,
      "rescue" => isset($options["rescue"]) ? $options["rescue"] : null,
      "api" => isset($options["api"]) ? $options["api"] : null,
      "route" => isset($options["route"]["client"]) ? $options["route"] : null,
      "proxy" => isset($options["proxy"]) ? $options["proxy"] : null,
      "cache" => isset($options["cache"]) ? $options["cache"] : null,
    ];

    $this->server = new Connection([
      "host" => $this->params["host"],
      "port" => $this->params["port"],
      "verify_cert" => $this->params["verify_cert"],
    ]);

    $this->end_client_id = isset($this->params["route"]["client"])
        ? $this->params["route"]["client"]
        : $this->params["client_id"];
  }

  /**
   * Get or set client params
   *
   * @param  mixed $merge
   * @param  array
   */
  public function params($merge = null): array
  {
    if (is_array($merge)) {
      $this->params = array_merge($this->params, $merge);
    } elseif (is_string($key = $merge)) {
      return $this->params[$key];
    } else {
      return $this->params;
    }
  }

  /**
   * Request helper
   *
   * @param  string $method
   * @param  string $url
   * @param  array $data
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function request($method, $url, $data = null)
  {
    $url = (string) $url;
    $data = ['$data' => $data];

    if (!$this->cache && $this->params["cache"]) {
      $this->cache = new Cache($this->end_client_id, $this->params["cache"]);
    }

    try {
      if (!$this->server->connected) {
        if ($this->params["proxy"]) {
          $data = $this->request_proxy_data($data);
        }
        if (!$this->authed) {
          $data['$client'] = $this->end_client_id;
          // Perform basic auth by default for secure/non-route based requests
          if (
            !isset($this->server->options["clear"]) &&
            !isset($this->params["route"]["client"])
          ) {
            $data['$key'] = $this->params["client_key"];
            if ($this->cache) {
              $data['$cached'] = $this->cache->get_versions();
            }
            if ($this->params["session"]) {
              $data['$session'] = $this->params["session"];
            }
          }
        }
        $this->server->connect();
      }
      $result = $this->server->request($method, $url, $data);
    } catch (NetworkException $e) {
      $this->request_rescue($e);
      $result = $this->server->request($method, $url, $data);
    }

    if (isset($result['$auth'])) {
      if (isset($result['$end'])) {
        // Connection ended, retry
        return $this->request($method, $url, $data['$data']);
      } else {
        $result = $this->auth($result['$auth']);
      }
    }

    return $this->response($method, $url, $data, $result);
  }

  /**
   * Request from a rescue server
   *
   * @param  Exception
   * @return void
   */
  protected function request_rescue($e)
  {
    if (
      $this->params["rescue"] &&
      $this->params["client_id"] &&
      $this->params["client_key"]
    ) {
      if ($this->params["rescued"]) {
        throw $e; // Prevent recursion
      } else {
        $this->params(["rescued" => true]);
        $this->server = new Connection([
          "host" => $this->params["rescue"]["host"],
          "port" => $this->params["rescue"]["port"],
          "verify_cert" => $this->params["verify_cert"],
        ]);
        $this->server->connect();
      }
    }
  }

  /**
   * Modify request to pass through an API proxy
   *
   * @param  array $data
   * @return array
   */
  protected function request_proxy_data($data): array
  {
    if (isset($this->params["rescued"])) {
      return $data;
    }

    $data['$proxy'] = [
      "client" => $this->end_client_id,
      "host" => $this->params["host"],
      "port" => $this->params["port"],
    ];
    if (is_array($this->params["proxy"])) {
      $this->server->options["clear"] = isset($this->params["proxy"]["clear"])
        ? $this->params["proxy"]["clear"]
        : false;
      $this->server->options["host"] = isset($this->params["proxy"]["host"])
        ? $this->params["proxy"]["host"]
        : $this->params["host"];
      $this->server->options["port"] = isset($this->params["proxy"]["port"])
        ? $this->params["proxy"]["port"]
        : $this->params["port"];
    }

    return $data;
  }

  /**
   * Response helper
   *
   * @param  string $method
   * @param  string $url
   * @param  mixed $data
   * @param  mixed $result
   * @return Resource
   */
  #[\ReturnTypeWillChange]
  protected function response($method, $url, $data, $result)
  {
    if (!isset($result['$url'])) {
      $result['$url'] = $url;
    }
    if ($this->cache) {
      $this->cache->clear($result);
      if (strtolower($method) === "get") {
        $this->cache->put($url, $data, $result);
      }
    }

    return $this->response_data($result, $method, $url);
  }

  /**
   * Instantiate resource for response data if applicable
   *
   * @param  array $result
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  protected function response_data($result, $method, $url)
  {
    if (isset($result['$data'])) {
      if (is_array($result['$data'])) {
        return Resource::instance($result, $this);
      }
      return $result['$data'];
    }
    return null;
  }

  /**
   * Call GET method
   *
   * @param  string $url
   * @param  mixed $data
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function get($url, $data = null)
  {
    if (!$this->cache && $this->params["cache"]) {
      $this->cache = new Cache($this->end_client_id, $this->params["cache"]);
    }

    if ($this->cache) {
      $result = $this->cache->get($url, ['$data' => $data]);
      if (array_key_exists('$data', (array) $result)) {
        return $this->response_data($result, "get", $url);
      }
    }

    return $this->request("get", $url, $data);
  }

  /**
   * Call PUT method
   *
   * @param  string $url
   * @param  mixed $data
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function put($url, $data = '$undefined')
  {
    if ($data === '$undefined') {
      $data = $url instanceof Resource ? $url->data() : null;
    }
    return $this->request("put", $url, $data);
  }

  /**
   * Call POST method
   *
   * @param  string $url
   * @param  mixed $data
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function post($url, $data = null)
  {
    return $this->request("post", $url, $data);
  }

  /**
   * Call DELETE method
   *
   * @param  string $url
   * @param  mixed $data
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function delete($url, $data = null)
  {
    return $this->request("delete", $url, $data);
  }

  /**
   * Call AUTH method
   *
   * @param  string $nonce
   * @param  array $params
   * @return mixed
   */
  #[\ReturnTypeWillChange]
  public function auth($nonce = null, $params = null)
  {
    $params = $params ?: [];

    $client_id = $this->params["client_id"];
    $client_key = $this->params["client_key"];

    // 1) Get nonce
    $nonce = $nonce ?: $this->server->request("auth");

    // 2) Create key hash
    $key_hash = md5("{$client_id}::{$client_key}");

    // 3) Create auth key
    $auth_key = md5("{$nonce}{$client_id}{$key_hash}");

    // 4) Authenticate with client params
    $params["client"] = $client_id;
    $params["key"] = $auth_key;

    if ($this->params["version"]) {
      $params['$v'] = $this->params["version"];
    }
    if ($this->params["api"]) {
      $params['$api'] = $this->params["api"];
    }
    if ($this->params["session"]) {
      $params['$session'] = $this->params["session"];
    }
    if (isset($this->params["route"]["client"])) {
      $params['$route'] = $this->params["route"];
    }
    if (
      isset($_SERVER["REMOTE_ADDR"]) &&
      ($ip_address = $_SERVER["REMOTE_ADDR"])
    ) {
      $params['$ip'] = $ip_address;
    }
    if ($this->cache) {
      $params['$cached'] = $this->cache->get_versions();
    }

    $this->authed = true;

    try {
      return $this->server->request("auth", $params);
    } catch (NetworkException $e) {
      $this->request_rescue($e);
      return $this->auth();
    }
  }
}
