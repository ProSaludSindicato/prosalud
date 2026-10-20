<?php

namespace App\Logging;

use App\Services\LogSanitizationService;
use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;

class SanitizeLogContext
{
    /**
     * Attach the sanitizing processor to every handler of the channel.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($this->process(...));
            }
        }
    }

    /**
     * Redact personal and secret values from the record context so no call site can leak them.
     */
    public function process(LogRecord $record): LogRecord
    {
        try {
            return $record->with(
                context: LogSanitizationService::sanitize($record->context),
                extra: LogSanitizationService::sanitize($record->extra),
            );
        } catch (\Throwable) {
            return $record->with(context: ['sanitization_failed' => true], extra: []);
        }
    }
}
