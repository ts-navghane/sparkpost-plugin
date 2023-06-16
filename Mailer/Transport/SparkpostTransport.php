<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\AbstractTokenArrayTransport;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostClientFactoryInterface;
use Psr\Log\LoggerInterface;
use SparkPost\SparkPost;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransport extends AbstractTokenArrayTransport
{
    public const MAUTIC_SPARKPOST_API_SCHEME = 'mautic+sparkpost+api';

    private const SPARK_POST_HOSTS = [
        'us' => 'api.sparkpost.com',
        'eu' => 'api.eu.sparkpost.com',
    ];

    private string $host;

    public function __construct(
        private string $apiKey,
        string $region,
        private TranslatorInterface $translator,
        private TransportCallback $transportCallback,
        private SparkpostClientFactoryInterface $sparkpostClientFactory,
        EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger
    ) {
        parent::__construct($dispatcher, $logger);
        $this->host = self::SPARK_POST_HOSTS[$region] ?? self::SPARK_POST_HOSTS['us'];
    }

    public function __toString(): string
    {
        return sprintf(self::MAUTIC_SPARKPOST_API_SCHEME.'://%s', $this->host);
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $sparkPostMessage = $this->getSparkPostMessage($message);
            $sparkPostClient  = $this->sparkpostClientFactory->create($this->host, $this->apiKey);

            $this->checkTemplateIsValid($sparkPostClient, $sparkPostMessage);

            $promise  = $sparkPostClient->transmissions->post($sparkPostMessage);
            $response = $promise->wait();
            $body     = $response->getBody();

            if ($errorMessage = $this->getErrorMessageFromResponseBody($body)) {
                $this->processImmediateSendFeedback($sparkPostMessage, $body);
                $this->throwException($errorMessage);
            }
        } catch (\Exception $e) {
            $this->throwException($e);
        }
    }

    public function getMaxBatchLimit(): int
    {
        return 5000;
    }

    public function getBatchRecipientCount(Email $message, $toBeAdded = 1, $type = 'to'): int
    {
        return count($message->getTo()) + count($message->getCc()) + count($message->getBcc()) + $toBeAdded;
    }

    private function getSparkPostMessage(SentMessage $message): array
    {
        $email = $message->getOriginalMessage();

        if (!$email instanceof MauticMessage) {
            $this->throwException('Message must be an instance of '.MauticMessage::class);
        }

        $this->message        = $email;
        $metadata             = $this->getMetadata();
        $tags                 = [];
        $inlineCss            = null;
        $mauticTokens         = [];
        $mergeVars            = [];
        $mergeVarPlaceholders = [];
        $campaignId           = '';

        // Sparkpost uses {{ name }} for tokens so Mautic's need to be converted; although using their {{{ }}} syntax to prevent HTML escaping
        if (!empty($metadata)) {
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);

            foreach ($mauticTokens as $token) {
                $mergeVars[$token]            = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $token));
                $mergeVarPlaceholders[$token] = '{{{ '.$mergeVars[$token].' }}}';
            }

            $campaignId = $this->extractCampaignId($metadataSet);
        }

        $message = $this->messageToArray($mauticTokens, $mergeVarPlaceholders, true);

        // Sparkpost requires a subject
        if (empty($message['subject'])) {
            $this->throwException($this->translator->trans('mautic.email.subject.notblank', [], 'validators'));
        }

        if (isset($message['headers']['X-MC-InlineCSS'])) {
            $inlineCss = $message['headers']['X-MC-InlineCSS'];
        }

        if (isset($message['headers']['X-MC-Tags'])) {
            $tags = explode(',', $message['headers']['X-MC-Tags']);
        }

        $recipients = $this->getRecipients($message, $metadata, $mergeVars);
        $content    = $this->getContent($message);

        $sparkPostMessage = [
            'content'     => $content,
            'recipients'  => $recipients,
            'inline_css'  => $inlineCss,
            'tags'        => $tags,
            'campaign_id' => $campaignId,
        ];

        if (!empty($message['attachments'])) {
            foreach ($message['attachments'] as $key => $attachment) {
                $message['attachments'][$key]['data'] = $attachment['content'];
                unset($message['attachments'][$key]['content']);
            }
            $sparkPostMessage['content']['attachments'] = $message['attachments'];
        }

        $sparkPostMessage['options'] = [
            'open_tracking'  => false,
            'click_tracking' => false,
        ];

        return $sparkPostMessage;
    }

    private function getRecipients(array $message, array $metadata, array $mergeVars): array
    {
        $recipients = [];

        foreach ($message['recipients']['to'] as $to) {
            $recipient    = $this->getRecipient($metadata, $mergeVars, $to);
            $recipients[] = $recipient;

            // CC and BCC fields need to be included as a normal TO address with token duplication
            // https://www.sparkpost.com/docs/faq/cc-bcc-with-rest-api/ - token duplication is not mentioned here
            // See test for CC and BCC too
            foreach (['cc', 'bcc'] as $copyType) {
                if (!empty($message['recipients'][$copyType])) {
                    foreach ($message['recipients'][$copyType] as $email => $content) {
                        $copyRecipient = [
                            'address'   => ['email' => $email],
                            'header_to' => $to['email'],
                        ];

                        if (!empty($recipient['substitution_data'])) {
                            $copyRecipient['substitution_data'] = $recipient['substitution_data'];
                        }

                        $recipients[] = $copyRecipient;
                    }
                }
            }
        }

        return $recipients;
    }

    private function getRecipient(array $metadata, array $mergeVars, array $to): array
    {
        $recipient = [
            'address'           => $to,
            'substitution_data' => [],
            'metadata'          => [],
        ];

        if (isset($metadata[$to['email']]['tokens'])) {
            foreach ($metadata[$to['email']]['tokens'] as $token => $value) {
                $recipient['substitution_data'][$mergeVars[$token]] = $value;
            }

            unset($metadata[$to['email']]['tokens']);
            $recipient['metadata'] = $metadata[$to['email']];
        }

        // Sparkpost requires substitution_data which can be byspassed by using MailHelper::setTo() rather than a Lead via MailHelper::setLead()
        // Without it, Sparkpost returns the error: "field 'substitution_data' is required"
        // But, it can't be an empty array or Sparkpost will return error: field 'substitution_data' is of type 'json', but needs to be of type 'json_object'
        if (empty($recipient['substitution_data'])) {
            $recipient['substitution_data'] = new \stdClass();
        }

        // Sparkpost doesn't like empty metadata
        if (empty($recipient['metadata'])) {
            unset($recipient['metadata']);
        }

        return $recipient;
    }

    private function getContent(array $message): array
    {
        $content = [
            'from'    => (!empty($message['from']['name'])) ? $message['from']['name'].' <'.$message['from']['email']
                .'>' : $message['from']['email'],
            'subject' => $message['subject'],
        ];

        if (!empty($message['headers'])) {
            $content['headers'] = array_map('strval', $message['headers']);
        }

        // Sparkpost will set parts regardless if they are empty or not
        if (!empty($message['html'])) {
            $content['html'] = $message['html'];
        }

        if (!empty($message['text'])) {
            $content['text'] = $message['text'];
        }

        // Add Reply To
        if (isset($message['replyTo'])) {
            $content['reply_to'] = $message['replyTo']['email'];
        }

        return $content;
    }

    private function extractCampaignId(array $metadataSet): string
    {
        $id = '';

        if (!empty($metadataSet['utmTags']['utmCampaign'])) {
            $id = $metadataSet['utmTags']['utmCampaign'];
        } elseif (!empty($metadataSet['emailId']) && !empty($metadataSet['emailName'])) {
            $id = $metadataSet['emailId'].':'.$metadataSet['emailName'];
        } elseif (!empty($metadataSet['emailId'])) {
            $id = $metadataSet['emailId'];
        }

        return mb_strcut($id, 0, 64);
    }

    private function checkTemplateIsValid(Sparkpost $sparkPostClient, array $sparkPostMessage): void
    {
        // Take substitution_data from the first recipient.
        if (
            empty($sparkPostMessage['substitution_data'])
            && isset($sparkPostMessage['recipients'][0]['substitution_data'])
        ) {
            $sparkPostMessage['substitution_data'] = $sparkPostMessage['recipients'][0]['substitution_data'];
            unset($sparkPostMessage['recipients']);
        }

        $promise  = $sparkPostClient->request('POST', 'utils/content-previewer', $sparkPostMessage);
        $response = $promise->wait();
        $body     = $response->getBody();

        if (403 === $response->getStatusCode()) {
            // We cannot fail as it would be a BC break. Throw a warning and continue.
            $this->logger->warning(
                "The permission 'Templates: Preview' is not enabled. Enable it to let Mautic check email template validity before send."
            );

            return;
        }

        if ($errorMessage = $this->getErrorMessageFromResponseBody($body)) {
            $this->throwException($errorMessage);
        }
    }

    private function getErrorMessageFromResponseBody(array $response): string
    {
        if (isset($response['errors'][0]['description'])) {
            return $response['errors'][0]['description'];
        } elseif (isset($response['errors'][0]['message'])) {
            return $response['errors'][0]['message'];
        }

        return '';
    }

    private function processImmediateSendFeedback(array $message, array $response): void
    {
        if (!empty($response['errors'][0]['code']) && 1902 == (int) $response['errors'][0]['code']) {
            $comments     = $this->getErrorMessageFromResponseBody($response);
            $emailAddress = $message['recipients'][0]['address']['email'];
            $metadata     = $this->getMetadata();

            if (isset($metadata[$emailAddress]['leadId'])) {
                $emailId = $metadata[$emailAddress]['emailId'] ?? null;
                $this->transportCallback->addFailureByContactId(
                    $metadata[$emailAddress]['leadId'],
                    $comments,
                    DoNotContact::BOUNCED,
                    $emailId
                );
            }
        }
    }
}
