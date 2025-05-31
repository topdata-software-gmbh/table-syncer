<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use DTO\SyncReportDTO;
use Psr\Log\NullLogger;

class SyncReportDTOTest extends TestCase
{
    public function testConstructorSetsProperties()
    {
        $logger = new NullLogger();
        $summary = 'Summary';
        $logEntries = ['entry1', 'entry2'];

        $dto = new SyncReportDTO($summary, $logEntries, $logger);

        $this->assertSame($summary, $dto->getSummary());
        $this->assertSame($logEntries, $dto->getLogEntries());
    }

    public function testLogMethods()
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
               ->method('info')
               ->with('Test message');

        $dto = new SyncReportDTO('Summary', [], $logger);
        $dto->log('Test message');
    }

    public function testSummary()
    {
        $logger = new NullLogger();
        $summary = 'Summary';
        $logEntries = ['entry1', 'entry2'];

        $dto = new SyncReportDTO($summary, $logEntries, $logger);

        $this->assertSame($summary, $dto->getSummary());
    }
}