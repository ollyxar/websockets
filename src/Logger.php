<?php namespace Ollyxar\WebSockets;

/**
 * Class Logger
 * @package Ollyxar\WebSockets
 */
class Logger
{
    /**
     * @var bool
     */
    private static $enabled = false;

    /**
     * Enable logging
     * @return void
     */
    public static function enable(): void
    {
        static::$enabled = true;
    }

    /**
     * Disable logging
     * @return void
     */
    public static function disable(): void
    {
        static::$enabled = true;
    }

    /**
     * Printing info into console
     *
     * @param $speakerType
     * @param $speakerId
     * @param $message
     * @param string $raw
     * @return void
     */
    public static function log($speakerType, $speakerId, $message, $raw = ''): void
    {
        if (!static::$enabled) {
            return;
        }

        switch ($speakerType) {
            case 'master':
                $speaker = "\033[1;34m" . $speakerId . "\033[0m";
                break;
            default:
                $speaker = "\033[1;35m" . $speakerId . "\033[0m";
        }

        $log = "\033[1;37m";

        try {
            $log .= \DateTime::createFromFormat('U.u', microtime(true))->format("i:s:u");
        } catch (\Throwable $exception) {
            $log .= 'cannot get current time. God damn fast!';
        }

        $log .= "\033[0m ";
        $log .= $speaker . " ";
        $log .= "\033[1;32m" . $message . "\033[0m ";
        $log .= "\033[42m" . $raw . "\033[0m\n";
        print $log;
    }
}