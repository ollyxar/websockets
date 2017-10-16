<?php namespace Ollyxar\WebSockets;

class Master
{
    private $workers = [];
    private $clients = [];
    private $connector;

    public function __construct($workers, $connector)
    {
        $this->clients = $this->workers = $workers;
        $this->connector = $connector;
    }

    public function dispatch()
    {
        while (true) {
            $read = $this->clients;
            $read[] = $this->connector;

            @stream_select($read, $write, $except, null);

            foreach ($read as $client) {
                if ($client === $this->connector) {
                    $client = @stream_socket_accept($client);
                    $data = Frame::encode(fread($client, 4096));
                } else {
                    $data = fread($client, 4096);
                }

                if (!strlen($data)) {
                    unset($this->clients[(int)$client]);
                    fclose($client);
                    continue;
                }

                foreach ($this->workers as $worker) {
                    if ($worker !== $client) {
                        fwrite($worker, $data);
                    }
                }
            }
        }
    }
}