<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\ImportStreamEvent;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ImportBroadcast
{
    public function __construct(private string $source, private ?string $batchId = null)
    {
    }

    public function started(): void
    {
        broadcast(new ImportStreamEvent($this->source, 'Starting import...', 'started', $this->batchId));
    }

    public function finished(): void
    {
        broadcast(new ImportStreamEvent($this->source, 'Import completed', 'finished', $this->batchId));
    }

    public function failed(Throwable $throwable): void
    {
        $message = $throwable->getMessage() ?: 'Import failed';
        broadcast(new ImportStreamEvent($this->source, $message, 'failed', $this->batchId));
    }

    public function output(): OutputInterface
    {
        return new class($this->source, $this->batchId) extends Output {
            public function __construct(private string $source, private ?string $batchId)
            {
                parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $payload = $newline ? rtrim($message, "\r\n") : $message;
                $lines = preg_split('/\r\n|\r|\n/', $payload) ?: [];

                foreach ($lines as $line) {
                    if (trim($line) === '') {
                        continue;
                    }

                    broadcast(new ImportStreamEvent($this->source, $line, 'output', $this->batchId));
                }
            }
        };
    }
}
