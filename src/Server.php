<?php namespace Ollyxar\WebSockets;

use Ollyxar\WebSockets\Exceptions\{
    ForkException,
    SocketException
};

/**
 * Class Server
 * @package Ollyxar\WebSockets
 */
class Server
{
    private $socket;
    private $unixConnector;
    protected $host = '0.0.0.0';
    protected $port = 2083;
    protected $useSSL = false;
    protected $useConnector = true;
    protected $exchangeWorkersData = true;
    protected $cert;
    protected $passPhrase;
    protected $workerCount = 4;
    protected $handler;
    public static $connector = '/var/run/wsc.sock';

    /**
     * Server constructor.
     *
     * @param string $host
     * @param int $port
     * @param int $workerCount
     * @param bool $useSSL
     * @param bool $useConnector
     * @param bool $exchangeWorkersData
     */
    public function __construct(string $host, int $port, int $workerCount = 4, $useSSL = false, $useConnector = true, $exchangeWorkersData = true)
    {
        $this->host = $host;
        $this->port = $port;
        $this->useSSL = $useSSL;
        $this->useConnector = $useConnector;
        $this->exchangeWorkersData = $exchangeWorkersData;
        $this->workerCount = $workerCount;
    }

    /**
     * Make server sockets
     *
     * @throws SocketException
     * @return void
     */
    private function makeSocket(): void
    {
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

        if ($this->useConnector) {
            if (file_exists(static::$connector)) {
                unlink(static::$connector);
            }

            $this->unixConnector = stream_socket_server('unix://' . static::$connector, $errorNumber, $errorString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
            chmod(static::$connector, 0777);
        }

        if (!$this->socket) {
            throw new SocketException($errorString, $errorNumber);
        }
    }

    /**
     * Spawning process to avoid system limits and increase performance
     *
     * @throws ForkException
     * @return array
     */
    private function spawn(): array
    {
        $pid = $master = null;
        $workers = [];

        for ($i = 0; $i < $this->workerCount; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            $pid = pcntl_fork();

            if ($pid == -1) {
                throw new ForkException('Cannot fork process');
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

    /**
     * Class name for spawned workers
     *
     * @param string $handler
     * @return Server
     */
    public function setHandler(string $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Path to PEM certificate
     *
     * @param string $cert
     * @return Server
     */
    public function setCert(string $cert = '/etc/nginx/conf.d/wss.pem'): self
    {
        $this->cert = $cert;

        return $this;
    }

    /**
     * Pass phrase for PEM certificate
     *
     * @param string $passPhrase
     * @return Server
     */
    public function setPassPhrase(string $passPhrase = 'abracadabra'): self
    {
        $this->passPhrase = $passPhrase;

        return $this;
    }

    /**
     * Launching server
     *
     * @return void
     * @throws ForkException
     * @throws SocketException
     */
    public function run(): void
    {
        $this->makeSocket();

        [$pid, $master, $workers] = $this->spawn();

        if ($pid) {
            fclose($this->socket);
            (new Master($workers, $this->unixConnector, $this->exchangeWorkersData))->dispatch();
        } else {
            if ($this->useConnector) {
                fclose($this->unixConnector);
            }

            (new $this->handler($this->socket, $master))->handle();
        }
    }
}