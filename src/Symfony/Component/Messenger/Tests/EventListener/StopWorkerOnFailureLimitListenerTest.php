<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\Tests\Fixtures\DummyMessage;
use Symfony\Component\Messenger\Worker;
use Throwable;

class StopWorkerOnFailureLimitListenerTest extends TestCase
{
    /**
     * @dataProvider countProvider
     */
    public function testWorkerStopsWhenMaximumCountReached(int $max, bool $shouldStop): void
    {
        $worker = $this->createMock(Worker::class);
        $worker->expects($shouldStop ? $this->atLeastOnce() : $this->never())->method('stop');

        $failedEvent = $this->createFailedEvent();
        $runningEvent = new WorkerRunningEvent($worker, false);

        $failureLimitListener = new StopWorkerOnFailureLimitListener($max);
        // simulate three messages (of which 2 failed)
        $failureLimitListener->onMessageFailed($failedEvent);
        $failureLimitListener->onWorkerRunning($runningEvent);

        $failureLimitListener->onWorkerRunning($runningEvent);

        $failureLimitListener->onMessageFailed($failedEvent);
        $failureLimitListener->onWorkerRunning($runningEvent);
    }

    public function countProvider(): iterable
    {
        yield [1, true];
        yield [2, true];
        yield [3, false];
        yield [4, false];
    }

    public function testWorkerLogsMaximumCountReachedWhenLoggerIsGiven(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with(
                $this->equalTo('Worker stopped due to limit of {count} failed message(s) is reached'),
                $this->equalTo(['count' => 1])
            );

        $worker = $this->createMock(Worker::class);
        $event = new WorkerRunningEvent($worker, false);

        $failureLimitListener = new StopWorkerOnFailureLimitListener(1, $logger);
        $failureLimitListener->onMessageFailed($this->createFailedEvent());
        $failureLimitListener->onWorkerRunning($event);
    }

    public function testWorkerLogsErrorWhenLoggerIsGiven(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())->method('error')
            ->with(
                $this->equalTo('Message failed with {error}'),
                $this->equalTo(['error' => 'trace'])
            );

        $worker = $this->createMock(Worker::class);
        $event = new WorkerRunningEvent($worker, false);

        $failEvent = $this->createFailedEvent();
        $failEvent->getThrowable()->expects($this->once())->method('__toString')->willReturn('trace');

        $failureLimitListener = new StopWorkerOnFailureLimitListener(1, $logger);
        $failureLimitListener->onMessageFailed($failEvent);
        $failureLimitListener->onWorkerRunning($event);
    }

    private function createFailedEvent(): WorkerMessageFailedEvent
    {
        $envelope = new Envelope(new DummyMessage('hello'));

        return new WorkerMessageFailedEvent($envelope, 'default', $this->createMock(Throwable::class));
    }
}
