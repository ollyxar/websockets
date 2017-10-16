<?php namespace Ollyxar\WebSockets;

use \Exception;

class Server
{
    private $host = '0.0.0.0';
    private $port = 2083;
    private $useSSL = false;
    private $cert;
    private $passPhrase;
    private $socket;
    private $unixConnector;
    private static $terminated = false;
    private $workerCount = 4;
    public static $connector = 'unix:///var/run/wsc.sock';

    public function __construct($host, $port, $useSSL = false, $cert = '/etc/nginx/conf.d/wss.pem', $passPhrase = 'abracadabra')
    {
        $this->host = $host;
        $this->port = $port;
        $this->useSSL = $useSSL;
        $this->cert = $cert;
        $this->passPhrase = $passPhrase;
    }

    private function generateCert(string $pemPassPhrase): void
    {
        $certificateData = [
            "countryName"            => "UA",
            "stateOrProvinceName"    => "Kyiv",
            "localityName"           => "Kyiv",
            "organizationName"       => "customwebsite.com",
            "organizationalUnitName" => "customname",
            "commonName"             => "commoncustomname",
            "emailAddress"           => "custom@email.com"
        ];

        $privateKey = openssl_pkey_new();
        $certificate = openssl_csr_new($certificateData, $privateKey);
        $certificate = openssl_csr_sign($certificate, null, $privateKey, 365);

        $pem = [];
        openssl_x509_export($certificate, $pem[0]);
        openssl_pkey_export($privateKey, $pem[1], $pemPassPhrase);
        $pem = implode($pem);

        file_put_contents($this->cert, $pem);
    }

    private function makeSocket(): void
    {
        if (file_exists(substr(static::$connector, 7))) {
            unlink(substr(static::$connector, 7));
        }

        if ($this->useSSL) {
            if (!file_exists($this->cert)) {
                $this->generateCert($this->passPhrase);
            }

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

        $this->unixConnector = stream_socket_server(static::$connector, $errorNumber, $errorString, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

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