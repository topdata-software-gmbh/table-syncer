<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TopdataSoftwareGmbh\TableSyncer\Service\GenericDataHasher;
use Psr\Log\NullLogger;

class GenericDataHasherTest extends TestCase
{
    public function testHashingLogic()
    {
        $logger = new NullLogger();
        $hasher = new GenericDataHasher($logger);

        $data = 'test_data';
        $hash = $hasher->hash($data);

        $this->assertNotEmpty($hash);
        $this->assertNotSame($data, $hash);
    }

    public function testPsr3LoggingCalls()
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
               ->method('info')
               ->with('Hashing data');

        $hasher = new GenericDataHasher($logger);
        $hasher->hash('test_data');
    }
}