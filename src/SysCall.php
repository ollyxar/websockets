<?php namespace Ollyxar\WebSockets;

/**
 * Class SysCall
 * @package Ollyxar\WebSockets
 */
final class SysCall
{
    /**
     * @var callable
     */
    protected $callback;

    /**
     * SysCall constructor.
     * @param callable $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param Job $job
     * @param Dispatcher $dispatcher
     * @return mixed
     */
    public function __invoke(Job $job, Dispatcher $dispatcher)
    {
        return call_user_func($this->callback, $job, $dispatcher);
    }
}