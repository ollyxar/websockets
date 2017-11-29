<?php namespace Ollyxar\WebSockets;

use Generator;

/**
 * Class Job
 * @package Ollyxar\WebSockets
 */
final class Job
{
    /**
     * @var Generator
     */
    protected $process;

    /**
     * @var mixed
     */
    protected $sendValue = null;

    /**
     * @var bool
     */
    protected $init = true;

    /**
     * Job constructor.
     * @param Generator $process
     */
    public function __construct(Generator $process)
    {
        $this->process = $process;
    }

    /**
     * @param mixed $sendValue
     */
    public function value($sendValue)
    {
        $this->sendValue = $sendValue;
    }

    /**
     * @return mixed
     */
    public function run()
    {
        if ($this->init) {
            $this->init = false;

            return $this->process->current();
        } else {
            $result = $this->process->send($this->sendValue);
            $this->sendValue = null;

            return $result;
        }
    }

    /**
     * @return bool
     */
    public function finished(): bool
    {
        return !$this->process->valid();
    }
}