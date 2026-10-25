<?php

declare(strict_types=1);

namespace MarketplaceSync\Support;

final class Logger
{
    /** @var resource */
    private $stream;

    public function __construct(string $fileName)
    {
        $directory = dirname($fileName);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create log directory: {$directory}");
        }

        $stream = fopen($fileName, 'ab');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open log file: {$fileName}");
        }
        $this->stream = $stream;
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $encoded = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($this->stream, sprintf("[%s] %s %s%s\n", gmdate('c'), $level, $message, $encoded));
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
