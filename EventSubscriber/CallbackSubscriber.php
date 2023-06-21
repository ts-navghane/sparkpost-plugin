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
        $payload = $event->getRequest()->request->all();

        foreach ($payload as $msys) {
            $msys  = $msys['msys'] ?? null;
            $event = $msys['message_event'] ?? $msys['unsubscribe_event'] ?? null;

            if (!$event) {
                continue;
            }

            if (isset($event['rcpt_type']) && 'to' !== $event['rcpt_type']) {
                // Ignore cc/bcc
                continue;
            }

            $type        = $event['type'] ?? null;
            $bounceClass = $event['bounce_class'] ?? null;

            if ('bounce' === $type && !in_array((int) $bounceClass, [10, 30, 50, 51, 52, 53, 54, 90])) {
                // Only parse hard bounces
                // https://support.sparkpost.com/customer/portal/articles/1929896-bounce-classification-codes
                continue;
            }

            $hashId = $event['rcpt_meta']['hashId'] ?? null;

            if ($hashId) {
                $this->processCallbackByHashId($hashId, $event);

                continue;
            }

            $rcptTo = $event['rcpt_to'] ?? '';
            $this->processCallbackByEmailAddress($rcptTo, $event);
        }
    }

    private function processCallbackByHashId($hashId, array $event): void
    {
        $type = $event['type'] ?? null;

        switch ($type) {
            case 'policy_rejection':
            case 'out_of_band':
            case 'bounce':
                $rawReason = $event['raw_reason'] ?? '';
                $this->transportCallback->addFailureByHashId($hashId, $rawReason);
                break;
            case 'spam_complaint':
                $fbType = $event['fbtype'] ?? '';
                $this->transportCallback->addFailureByHashId($hashId, $fbType, DoNotContact::UNSUBSCRIBED);
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
        $type = $event['type'] ?? null;

        switch ($type) {
            case 'policy_rejection':
            case 'out_of_band':
            case 'bounce':
                $rawReason = $event['raw_reason'] ?? '';
                $this->transportCallback->addFailureByAddress($email, $rawReason);
                break;
            case 'spam_complaint':
                $fbType = $event['fbtype'] ?? '';
                $this->transportCallback->addFailureByAddress($email, $fbType, DoNotContact::UNSUBSCRIBED);
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
