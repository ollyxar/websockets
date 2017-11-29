<?php namespace Ollyxar\WebSockets;

use Generator;

/**
 * Class Master
 * @package Ollyxar\WebSockets
 */
final class Master
{
    private $workers = [];
    private $connector;

    /**
     * Master constructor.
     *
     * @param $workers
     * @param $connector
     */
    public function __construct($workers, $connector)
    {
        $this->workers = $workers;
        $this->connector = $connector;
    }

    /**
     * @param $client
     * @param $data
     * @return Generator
     */
    protected function write($client, $data): Generator
    {
        yield Dispatcher::listenWrite($client);
        fwrite($client, $data);
    }

    protected function listenWorker($socket): Generator
    {
        yield Dispatcher::listenRead($socket);
        yield Dispatcher::listenWrite($socket);

        $data = Frame::decode($socket);

        if (!$data['opcode']) {
            return yield;
        }

        stream_select($read, $this->workers, $except, 0);

        foreach ($this->workers as $worker) {
            if ($worker !== $socket) {
                dump('sending to worker # '.(int)$worker);
                yield Dispatcher::make($this->write($worker, Frame::encode($data['payload'], $data['opcode'])));
            }
        }
    }

    /**
     * @return Generator
     */
    protected function listenWorkers(): Generator
    {
        while (true) {
            foreach ($this->workers as $worker) {
                yield Dispatcher::make($this->listenWorker($worker));
            }
        }
    }

    /**
     * @return Generator
     */
    protected function listenConnector(): Generator
    {
        while (true) {
            yield Dispatcher::listenRead($this->connector);

            if ($socket = @stream_socket_accept($this->connector)) {
                $data = Frame::decode($socket);

                if (!$data['opcode']) {
                    continue;
                }

                foreach ($this->workers as $worker) {
                    yield Dispatcher::make($this->write($worker, Frame::encode($data['payload'], $data['opcode'])));
                }
            }
        }
    }

    /**
     * Dispatch messaging
     *
     * @return void
     */
    public function dispatch(): void
    {
        (new Dispatcher())
            ->add($this->listenConnector())
            //->add($this->listenWorkers())
            ->dispatch();
    }
}
