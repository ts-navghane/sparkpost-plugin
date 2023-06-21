<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Unit\Mailer\Factory;

use Http\Adapter\Guzzle7\Client;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostClientFactory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use SparkPost\SparkPost;

class SparkpostClientFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $this->sparkpostClientFactory = new SparkpostClientFactory(new Client(new \GuzzleHttp\Client()));
    }

    public function testCreateSparkpostClient(): void
    {
        $sparkpost = $this->sparkpostClientFactory->create('some_host', 'some_api');
        Assert::assertInstanceOf(SparkPost::class, $sparkpost);
    }
}
