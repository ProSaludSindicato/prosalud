<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class QueueRetryAfterTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: int}>
     */
    public static function jobTimeouts(): array
    {
        $cases = [];

        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Jobs')->name('*.php') as $file) {
            $class = 'App\\Jobs\\'.$file->getBasename('.php');

            if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            $defaults = (new ReflectionClass($class))->getDefaultProperties();

            if (isset($defaults['timeout'])) {
                $cases[$class] = [$class, (int) $defaults['timeout']];
            }
        }

        return $cases;
    }

    #[DataProvider('jobTimeouts')]
    public function test_retry_after_is_longer_than_every_job_timeout(string $class, int $timeout): void
    {
        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan(
                $timeout,
                $retryAfter,
                "{$class} timeout ({$timeout}s) must be lower than {$connection} retry_after ({$retryAfter}s) or the job runs twice."
            );
        }
    }
}
