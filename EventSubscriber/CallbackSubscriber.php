<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\EventSubscriber;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(private TransportCallback $transportCallback)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $request = $event->getRequest();

        $payload = $request->request->all();

        foreach ($payload as $msys) {
            $msys = $msys['msys'];
            if (isset($msys['message_event'])) {
                $event = $msys['message_event'];
            } elseif (isset($msys['unsubscribe_event'])) {
                $event = $msys['unsubscribe_event'];
            } else {
                continue;
            }

            if (isset($event['rcpt_type']) && 'to' !== $event['rcpt_type']) {
                // Ignore cc/bcc

                continue;
            }

            if (
                'bounce' === $event['type']
                && !in_array((int) $event['bounce_class'], [10, 30, 50, 51, 52, 53, 54, 90])
            ) {
                // Only parse hard bounces - https://support.sparkpost.com/customer/portal/articles/1929896-bounce-classification-codes
                continue;
            }

            if (isset($event['rcpt_meta']['hashId']) && $hashId = $event['rcpt_meta']['hashId']) {
                $this->processCallbackByHashId($hashId, $event);

                continue;
            }

            $this->processCallbackByEmailAddress($event['rcpt_to'], $event);
        }
    }

    private function processCallbackByHashId($hashId, array $event): void
    {
        switch ($event['type']) {
            case 'policy_rejection':
            case 'out_of_band':
            case 'bounce':
                $this->transportCallback->addFailureByHashId($hashId, $event['raw_reason']);
                break;
            case 'spam_complaint':
                $this->transportCallback->addFailureByHashId($hashId, $event['fbtype'], DoNotContact::UNSUBSCRIBED);
                break;
            case 'list_unsubscribe':
            case 'link_unsubscribe':
                $this->transportCallback->addFailureByHashId($hashId, 'unsubscribed', DoNotContact::UNSUBSCRIBED);
                break;
            default:
                break;
        }
    }

    private function processCallbackByEmailAddress($email, array $event): void
    {
        switch ($event['type']) {
            case 'policy_rejection':
            case 'out_of_band':
            case 'bounce':
                $this->transportCallback->addFailureByAddress($email, $event['raw_reason']);
                break;
            case 'spam_complaint':
                $this->transportCallback->addFailureByAddress($email, $event['fbtype'], DoNotContact::UNSUBSCRIBED);
                break;
            case 'list_unsubscribe':
            case 'link_unsubscribe':
                $this->transportCallback->addFailureByAddress($email, 'unsubscribed', DoNotContact::UNSUBSCRIBED);
                break;
            default:
                break;
        }
    }
}
