<?php

namespace Swell;

class ConnectionException extends \Exception {}

class NetworkException extends ConnectionException {}

class ProtocolException extends ConnectionException {}

class ServerException extends ConnectionException {}

class Connection
{
    /**
     * @var bool
     */
    public $connected;

    /**
     * @var string
     */
    public $host;

    /**
     * @var int
     */
    public $port;

    /**
     * @var bool
     */
    public $options;

    /**
     * @var resource
     */
    protected $stream;

    /**
     * @var string
     */
    protected $last_request;

    /**
     * @var int
     */
    public $request_count = 0;

    /**
     * @param  string $host
     * @param  string $port
     */
    public function __construct($options = null)
    {
        $this->options = $options ?: array();
        $this->connected = false;
    }

    /**
     * Connect to server
     *
     * @return void
     */
    public function connect()
    {
        $this->host = $this->options['host'];
        $this->port = $this->options['port'];

        if (isset($this->options['clear']) && $this->options['clear']) {
            $this->stream = stream_socket_client(
                "tcp://{$this->host}:{$this->port}", $error, $error_msg, 10
            );
        } else {
            $options = array(
                'ssl' => array(
                    'verify_peer' => false
                )
            );
            if ($this->options['verify_cert']) {
                $options['ssl']['verify_peer'] = true;
                $options['ssl']['verify_depth'] = 5;
                $options['ssl']['cafile'] = \dirname(\dirname(__FILE__)).'/data/ca-certificates.crt';
            }
            $context = stream_context_create($options);
            $this->stream = stream_socket_client(
                "tls://{$this->host}:{$this->port}", $error, $error_msg, 10,
                STREAM_CLIENT_CONNECT, $context
            );
        }
        if ($this->stream) {
            $this->connected = true;
        } else {
            $error_msg = $error_msg ?: 'Peer certificate rejected';
            throw new NetworkException(
                "Unable to connect to {$this->host}:{$this->port} "
                ."(Error:{$error} {$error_msg})"
            );
        }
    }

    /**
     * Initiate request
     *
     * @param  string $method
     * @param  array $args
     * @return mixed
     */
    public function request($method)
    {
        $this->request_write(func_get_args());
        return $this->request_response();
    }

    /**
     * Write request to stream
     *
     * @param  array $args
     */
    private function request_write($args)
    {
        $request = json_encode($args)."\n";
        $this->last_request = $request;
        if (!$this->stream) {
            $desc = $this->request_description();
            throw new NetworkException("Unable to execute request ({$desc}): Connection closed");
        }

        // Must block while writing
        stream_set_blocking($this->stream, true);

        for ($written = 0; $written < strlen($request); $written += $fwrite) {
            $fwrite = fwrite($this->stream, substr($request, $written));
            if ($fwrite === false) {
                break;
            }
        }

        $this->request_count++;
    }

    /**
     * Get server response
     *
     * @return mixed
     */
    private function request_response()
    {
        // Must not block while reading
        stream_set_blocking($this->stream, false);

        $response = '';
        while (true) {
            $buffer = fgets($this->stream);
            $response .= $buffer;
            if (strstr($buffer, "\n")) {
                break;
            } else {
                usleep(1000);
            }
        }

        $data = '';
        if (!$response) {
            $this->close();
            $desc = $this->request_description();
            throw new ProtocolException("Unable to read response from server ({$desc})");
        } else if (null === ($data = json_decode(trim($response), true))) {
            $desc = $this->request_description();
            throw new ProtocolException("Unable to parse response from server ({$desc}): {$response}");
        } else if (!is_array($data)) {
            $desc = $this->request_description();
            throw new ProtocolException("Invalid response from server ({$desc}): ".json_encode($data));
        }

        if (isset($data['$error'])) {
            throw new ServerException((string)$data['$error']);
        }
        if (isset($data['$end'])) {
            $this->close();
        }

        return $data;
    }

    /**
     * Get description of last request
     *
     * @return string
     */
    private function request_description()
    {
        $request = json_decode(trim($this->last_request), true);
        $desc = strtoupper($request[0]);
        if (isset($request[1])) {
            $desc .= ' '.json_encode($request[1]);
        }
        return $desc;
    }

    /**
     * Close connection stream
     *
     * @return void
     */
    public function close()
    {
        fclose($this->stream);
        $this->stream = null;
        $this->connected = false;
    }
}
