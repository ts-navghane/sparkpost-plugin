<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Unit\Mailer\Factory;

use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostClientFactory;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostTransportFactory;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransportFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $translatorMock             = $this->createMock(TranslatorInterface::class);
        $sparkpostClientFactoryMock = $this->createMock(SparkpostClientFactory::class);
        $eventDispatcherMock        = $this->createMock(EventDispatcherInterface::class);
        $transportCallbackMock      = $this->createMock(TransportCallback::class);
        $httpClientMock             = $this->createMock(HttpClientInterface::class);
        $loggerMock                 = $this->createMock(LoggerInterface::class);

        $this->sparkpostTransportFactory = new SparkpostTransportFactory(
            $translatorMock,
            $sparkpostClientFactoryMock,
            $eventDispatcherMock,
            $transportCallbackMock,
            $httpClientMock,
            $loggerMock
        );
    }

    public function testCreateTransport(): void
    {
        $dsn = new Dsn(
            SparkpostTransport::MAUTIC_SPARKPOST_API_SCHEME,
            'host',
            null,
            'sparkpost_api_key',
            null,
            ['region' => 'us']
        );
        $sparkpostTransport = $this->sparkpostTransportFactory->create($dsn);
        Assert::assertInstanceOf(SparkpostTransport::class, $sparkpostTransport);
    }

    public function testUnsupportedScheme(): void
    {
        $this->expectException(UnsupportedSchemeException::class);
        $dsn = new Dsn(
            'mautic+sparkpost',
            'host',
            null,
            'sparkpost_api_key',
            null,
            ['region' => 'us']
        );
        $this->sparkpostTransportFactory->create($dsn);
    }
}
