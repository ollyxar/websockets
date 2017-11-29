<?php namespace Ollyxar\WebSockets;

use Generator;
use SplQueue;

/**
 * Class Dispatcher
 * @package Ollyxar\WebSockets
 */
final class Dispatcher
{
    /**
     * @var SplQueue
     */
    private $queue;

    /**
     * Sockets ready to read
     *
     * @var array
     */
    private $read = [];

    /**
     * Sockets ready to write
     *
     * @var array
     */
    private $write = [];

    /**
     * Dispatcher constructor.
     */
    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    /**
     * Poll connections
     *
     * @param $timeout
     * @return void
     */
    private function poll($timeout): void
    {
        $read = $write = [];

        foreach ($this->read as [$socket]) {
            $read[] = $socket;
        }

        foreach ($this->write as [$socket]) {
            $write[] = $socket;
        }

        if (!@stream_select($read, $write, $except, $timeout)) {
            return;
        }

        foreach ($read as $socket) {
            $jobs = $this->read[(int)$socket][1];
            unset($this->read[(int)$socket]);

            foreach ($jobs as $job) {
                $this->enqueue($job);
            }
        }

        foreach ($write as $socket) {
            $jobs = $this->write[(int)$socket][1];
            unset($this->write[(int)$socket]);

            foreach ($jobs as $job) {
                $this->enqueue($job);
            }
        }
    }

    /**
     * @return Generator
     */
    private function pollProcess(): Generator
    {
        while (true) {
            if ($this->queue->isEmpty()) {
                $this->poll(null);
            } else {
                $this->poll(0);
            }
            yield;
        }
    }

    /**
     * @param Job $job
     * @return void
     */
    public function enqueue(Job $job): void
    {
        $this->queue->enqueue($job);
    }

    /**
     * @param $socket
     * @param Job $job
     * @return void
     */
    public function appendRead($socket, Job $job): void
    {
        if (isset($this->read[(int)$socket])) {
            $this->read[(int)$socket][1][] = $job;
        } else {
            $this->read[(int)$socket] = [$socket, [$job]];
        }
    }

    /**
     * @param $socket
     * @param Job $job
     * @return void
     */
    public function appendWrite($socket, Job $job): void
    {
        if (isset($this->write[(int)$socket])) {
            $this->write[(int)$socket][1][] = $job;
        } else {
            $this->write[(int)$socket] = [$socket, [$job]];
        }
    }

    /**
     * @param Generator $process
     * @return Dispatcher
     */
    public function add(Generator $process): self
    {
        $this->enqueue(new Job($process));

        return $this;
    }

    /**
     * @param Generator $process
     * @return SysCall
     */
    public static function make(Generator $process): SysCall
    {
        return new SysCall(
            function (Job $job, Dispatcher $dispatcher) use ($process) {
                $job->value($dispatcher->add($process));
                $dispatcher->enqueue($job);
            }
        );
    }

    /**
     * @param $socket
     * @return SysCall
     */
    public static function listenRead($socket): SysCall
    {
        return new SysCall(
            function (Job $job, Dispatcher $dispatcher) use ($socket) {
                $dispatcher->appendRead($socket, $job);
            }
        );
    }

    /**
     * @param $socket
     * @return SysCall
     */
    public static function listenWrite($socket): SysCall
    {
        return new SysCall(
            function (Job $job, Dispatcher $dispatcher) use ($socket) {
                $dispatcher->appendWrite($socket, $job);
            }
        );
    }

    /**
     * @return void
     */
    public function dispatch(): void
    {
        $this->add($this->pollProcess());

        while (!$this->queue->isEmpty()) {
            $job = $this->queue->dequeue();
            $result = $job->run();

            if ($result instanceof SysCall) {
                $result($job, $this);
                continue;
            }

            if (!$job->finished()) {
                $this->enqueue($job);
            }
        }
    }
}