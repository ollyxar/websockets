<?php namespace Ollyxar\WebSockets;

use \Exception;

class Server
{
    private $socket;
    private $unixConnector;
    private static $terminated = false;
    protected $host = '0.0.0.0';
    protected $port = 2083;
    protected $useSSL = false;
    protected $cert;
    protected $passPhrase;
    protected $workerCount = 4;
    public static $connector = '/var/run/wsc.sock';

    public function __construct($host, $port, $useSSL = false, $cert = '/etc/nginx/conf.d/wss.pem', $passPhrase = 'abracadabra')
    {
        $this->host = $host;
        $this->port = $port;
        $this->useSSL = $useSSL;
        $this->cert = $cert;
        $this->passPhrase = $passPhrase;
    }

    private function makeSocket(): void
    {
        if (file_exists(static::$connector)) {
            unlink(static::$connector);
        }

        if ($this->useSSL) {
            $context = stream_context_create([
                'ssl' => [
                    'local_cert'        => $this->cert,
                    'passphrase'        => $this->passPhrase,
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                    'verify_depth'      => 0
                ]
            ]);

            $protocol = 'ssl';
        } else {
            $context = stream_context_create();
            $protocol = 'tcp';
        }

        $this->socket = stream_socket_server("$protocol://{$this->host}:{$this->port}", $errorNumber, $errorString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        $this->unixConnector = stream_socket_server('unix://' . static::$connector, $errorNumber, $errorString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (!$this->socket) {
            throw new Exception($errorString, $errorNumber);
        }
    }

    private function spawn()
    {
        $pid = $master = null;
        $workers = [];

        for ($i = 0; $i < $this->workerCount; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new Exception('Cannot fork process');
            } elseif ($pid) {
                fclose($pair[0]);
                $workers[$pid] = $pair[1];
            } else {
                fclose($pair[1]);
                $master = $pair[0];
                break;
            }
        }

        return [$pid, $master, $workers];
    }

    public static function terminate(): void
    {
        static::$terminated = true;
    }

    public function run(): void
    {
        $this->makeSocket();

        list($pid, $master, $workers) = $this->spawn();

        if ($pid) {
            fclose($this->socket);
            (new Master($workers, $this->unixConnector))->dispatch();
        } else {
            fclose($this->unixConnector);
            (new Worker($this->socket, $master))->handle();
        }
    }
}