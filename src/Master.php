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
     * If true then Master will retransmit data from one worker to others
     *
     * @var bool
     */
    private $exchangeWorkersData = true;

    /**
     * Master constructor.
     *
     * @param $workers
     * @param null $connector
     * @param bool $exchangeWorkersData
     */
    public function __construct($workers, $connector = null, $exchangeWorkersData = true)
    {
        $this->workers = $workers;
        $this->connector = $connector;
        $this->exchangeWorkersData = $exchangeWorkersData;
    }

    /**
     * @param $client
     * @return Generator
     */
    protected function read($client): Generator
    {
        yield Dispatcher::listenRead($client);

        Logger::log('master', posix_getpid(), 'data received from worker', (int)$client);
        $data = Frame::decode($client);

        if (!$data['opcode']) {
            return yield;
        }

        foreach ($this->workers as $worker) {
            if ($worker !== $client) {
                Logger::log('master', posix_getpid(), 'write to worker', (int)$worker);

                yield Dispatcher::listenWrite($worker);
                yield Dispatcher::async($this->write($worker, Frame::encode($data['payload'], $data['opcode'])));
            }
        }

        yield Dispatcher::async($this->read($client));
    }

    /**
     * @param $client
     * @param $data
     * @return Generator
     */
    protected function write($client, $data): Generator
    {
        Logger::log('master', posix_getpid(), 'write to ' . (int)$client, $data);

        yield Dispatcher::listenWrite($client);
        fwrite($client, $data);
    }

    /**
     * @return Generator
     */
    protected function listenWorkers(): Generator
    {
        foreach ($this->workers as $worker) {
            yield Dispatcher::async($this->read($worker));
        }
    }

    /**
     * @return Generator
     */
    protected function listenConnector(): Generator
    {
        yield Dispatcher::listenRead($this->connector);

        if ($socket = @stream_socket_accept($this->connector)) {
            Logger::log('master', posix_getpid(), 'connector accepted');
            $data = Frame::decode($socket);

            if (!$data['opcode']) {
                return yield;
            }

            foreach ($this->workers as $worker) {
                yield Dispatcher::listenWrite($worker);
                yield Dispatcher::async($this->write($worker, Frame::encode($data['payload'], $data['opcode'])));
            }
        }

        yield Dispatcher::async($this->listenConnector());
    }

    /**
     * Dispatch messaging
     *
     * @return void
     */
    public function dispatch(): void
    {
        $dispatcher = new Dispatcher();

        if ($this->connector) {
            $dispatcher->add($this->listenConnector());
        }

        if ($this->exchangeWorkersData) {
            $dispatcher->add($this->listenWorkers());
        }

        $dispatcher->dispatch();
    }
}
