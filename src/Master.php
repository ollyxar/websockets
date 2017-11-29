<?php namespace Ollyxar\WebSockets;

use Generator;

/**
 * Class Master
 * @package Ollyxar\WebSockets
 */
final class Master
{
    /**
     * @var array
     */
    private $workers = [];

    /**
     * Unix socket connector
     */
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
     * @return Generator
     */
    protected function read($client): Generator
    {
        yield Dispatcher::listenRead($client);

        $data = Frame::decode($client);

        if (!$data['opcode']) {
            return yield;
        }

        foreach ($this->workers as $worker) {
            if ($worker !== $client) {
                yield Dispatcher::listenWrite($worker);
                yield Dispatcher::make($this->write($worker, Frame::encode($data['payload'], $data['opcode'])));
            }
        }

        yield Dispatcher::make($this->read($client));
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

    /**
     * @return Generator
     */
    protected function listenWorkers(): Generator
    {
        foreach ($this->workers as $worker) {
            yield Dispatcher::make($this->read($worker));
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
                    yield Dispatcher::listenWrite($worker);
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
            ->add($this->listenWorkers())
            ->dispatch();
    }
}
