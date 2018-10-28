<?php

namespace App\Services\TestGenerator;

use Symfony\Component\Asset\Exception\LogicException;

class LogManager
{
    public const TYPE_SUCCESS   = 0;
    public const TYPE_ERROR     = 1;
    public const TYPE_COMMENT   = 2;

    public const SUCCESS_COLOR = "\033[32m";
    public const ERROR_COLOR   = "\033[31m";
    public const COMMENT_COLOR = "\033[33m";
    public const WHITE_COLOR   = "\033[0m";

    public function log(string $text, string $type): void
    {
        echo $this->getColor($type) . $text . self::WHITE_COLOR . "\n";
    }

    private function getColor(string $type): string
    {
        switch ($type) {
            case self::TYPE_SUCCESS:
                return self::SUCCESS_COLOR;
            case self::TYPE_ERROR:
                return self::ERROR_COLOR;
            case self::TYPE_COMMENT:
                return self::COMMENT_COLOR;
            default:
                throw new LogicException('Error logging: Type unavailable');
        }
    }
}