<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

class SinkServerSubscriber implements EventSubscriberInterface
{
    public function __construct(private string $sinkSuffix = '.sink.sparkpostmail.com')
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => 'onMessage'];
    }

    public function onMessage(MessageEvent $event): void
    {
        if (!$this->sinkSuffix) {
            return;
        }

        $recipients = array_map(
            function (Address $address): Address {
                return new Address($address->getAddress().$this->sinkSuffix, $address->getName());
            },
            $event->getEnvelope()->getRecipients()
        );

        $event->getEnvelope()->setRecipients($recipients);
    }
}
