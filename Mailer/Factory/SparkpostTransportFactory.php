<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Mailer\Factory;

use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private TranslatorInterface $translator,
        private SparkpostClientFactory $sparkpostClientFactory,
        EventDispatcherInterface $eventDispatcher,
        private TransportCallback $transportCallback,
        HttpClientInterface $client = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($eventDispatcher, $client, $logger);
    }

    /**
     * @return string[]
     */
    protected function getSupportedSchemes(): array
    {
        return [SparkpostTransport::MAUTIC_SPARKPOST_API_SCHEME];
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (SparkpostTransport::MAUTIC_SPARKPOST_API_SCHEME === $dsn->getScheme()) {
            return new SparkpostTransport(
                $this->getPassword($dsn),
                $dsn->getOption('region'),
                $this->translator,
                $this->sparkpostClientFactory,
                $this->transportCallback,
                $this->client,
                $this->dispatcher,
                $this->logger
            );
        }

        throw new UnsupportedSchemeException($dsn, 'sparkpost', $this->getSupportedSchemes());
    }
}
