<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Mailer\Transport;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\SparkpostBundle\Helper\SparkpostResponse;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostClientFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Log\LoggerInterface;
use SparkPost\SparkPost;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransport extends AbstractApiTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

    public const MAUTIC_SPARKPOST_API_SCHEME = 'mautic+sparkpost+api';

    private const SPARK_POST_HOSTS = ['us' => 'api.sparkpost.com', 'eu' => 'api.eu.sparkpost.com'];

    public function __construct(
        private string $apiKey,
        private string $region,
        private TranslatorInterface $translator,
        private SparkpostClientFactory $factory,
        private TransportCallback $callback,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
        $this->host = self::SPARK_POST_HOSTS[$region] ?? self::SPARK_POST_HOSTS['us'];
    }

    public function __toString(): string
    {
        return sprintf(self::MAUTIC_SPARKPOST_API_SCHEME.'://%s', $this->host);
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        try {
            $payload         = $this->getSparkPostPayload($sentMessage);
            $sparkPostClient = $this->factory->create($this->host, $this->apiKey);
            $this->checkTemplateIsValid($sparkPostClient, $payload);

            $promise           = $sparkPostClient->transmissions->post($payload);
            $sparkpostResponse = $promise->wait();
            $body              = $sparkpostResponse->getBody();

            if ($errorMessage = $this->getErrorMessageFromResponseBody($body)) {
                $this->processImmediateSendFeedback($payload, $body);
                $this->throwException($errorMessage);
            }
        } catch (\Exception $e) {
            $this->throwException($e->getMessage());
        }

        return $this->handleResponse($sparkpostResponse);
    }

    private function getSparkPostPayload(SentMessage $message): array
    {
        $email = $message->getOriginalMessage();

        if (!$email instanceof MauticMessage) {
            $this->throwException('Message must be an instance of '.MauticMessage::class);
        }

        $metadata    = $email->getMetadata();
        $mergeVars   = [];
        $metadataSet = [];

        // Sparkpost uses {{ name }} for tokens so Mautic's need to be converted;
        // although using their {{{ }}} syntax to prevent HTML escaping
        if (!empty($metadata)) {
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);

            $mergeVarPlaceholders = [];

            foreach ($mauticTokens as $token) {
                $mergeVars[$token]            = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $token));
                $mergeVarPlaceholders[$token] = '{{{ '.$mergeVars[$token].' }}}';
            }

            if (!empty($mauticTokens)) {
                MailHelper::searchReplaceTokens($mauticTokens, $mergeVarPlaceholders, $email);
            }
        }

        return [
            'content'     => $this->buildContent($email),
            'recipients'  => $this->buildRecipients($email, $metadata, $mergeVars),
            'inline_css'  => $email->getHeaders()->get('X-MC-InlineCSS')
                ? $email->getHeaders()->get('X-MC-InlineCSS')->getBody()
                : null,
            'tags'        => $email->getHeaders()->get('X-MC-Tags')
                ? $email->getHeaders()->get('X-MC-Tags')->getBody()
                : [],
            'campaign_id' => $this->getCampaignId($metadata, $metadataSet),
            'options'     => [
                'open_tracking'  => false,
                'click_tracking' => false,
            ],
        ];
    }

    private function buildContent(MauticMessage $message): array
    {
        $fromAddress = current($message->getFrom());

        $content = [
            'from'        => !empty($fromAddress->getName())
                ? $fromAddress->getName().' <'.$fromAddress->getAddress().'>'
                : $fromAddress->getAddress(),
            'subject'     => $message->getSubject(),
            'headers'     => $message->getHeaders()->all(),
            'html'        => $message->getHtmlBody(),
            'text'        => $message->getTextBody(),
            'reply_to'    => (current($message->getReplyTo()))->getAddress(),
            'attachments' => $this->buildAttachments($message),
        ];

        if (!empty($headers = $message->getHeaders()->all())) {
            $content['headers'] = array_map('strval', (array) $headers);
        }

        return $content;
    }

    private function buildAttachments(MauticMessage $message): array
    {
        $result = [];

        foreach ($message->getAttachments() as $attachment) {
            /** @var ParameterizedHeader $file */
            $file = $attachment->getPreparedHeaders()->get('Content-Disposition');
            /** @var ParameterizedHeader $type */
            $type = $attachment->getPreparedHeaders()->get('Content-Type');

            $result[] = [
                'name' => $file->getParameter('filename'),
                'type' => $type->getValue(),
                'data' => base64_encode($attachment->getBody()),
            ];
        }

        return $result;
    }

    private function buildRecipients(MauticMessage $message, array $metadata, array $mergeVars): array
    {
        $recipients = [];

        foreach ($message->getTo() as $to) {
            $recipient = $this->buildRecipient($to, $metadata, $mergeVars);
            $recipients[] = $recipient;

            // CC and BCC fields need to be included as a normal TO address with token duplication
            // https://www.sparkpost.com/docs/faq/cc-bcc-with-rest-api/ - token duplication is not mentioned here
            // See test for CC and BCC too
            foreach ($message->getCc() as $cc) {
                $recipients[] = $this->buildCopyRecipient($to, $cc, $recipient);
            }

            foreach ($message->getBcc() as $bcc) {
                $recipients[] = $this->buildCopyRecipient($to, $bcc, $recipient);
            }
        }

        return $recipients;
    }

    private function buildRecipient(Address $to, array $metadata, array $mergeVars): array
    {
        $recipient = [
            'address'           => [
                'email' => $to->getAddress(),
                'name'  => $to->getName(),
            ],
            'substitution_data' => [],
            'metadata'          => [],
        ];

        $email = $to->getAddress();

        if (isset($metadata[$email]['tokens'])) {
            foreach ($metadata[$email]['tokens'] as $token => $value) {
                $recipient['substitution_data'][$mergeVars[$token]] = $value;
            }

            unset($metadata[$email]['tokens']);
            $recipient['metadata'] = $metadata[$email];
        }

        // Sparkpost requires substitution_data which can be by-passed by using
        // MailHelper::setTo() rather than a Lead via MailHelper::setLead()
        // Without it, Sparkpost returns the error: "field 'substitution_data' is required"
        // But, it can't be an empty array or Sparkpost will return error: field 'substitution_data'
        // is of type 'json', but needs to be of type 'json_object'
        if (empty($recipient['substitution_data'])) {
            $recipient['substitution_data'] = new \stdClass();
        }

        // Sparkpost doesn't like empty metadata
        if (empty($recipient['metadata'])) {
            unset($recipient['metadata']);
        }

        return $recipient;
    }

    private function buildCopyRecipient(Address $to, Address $copy, array $recipient): array
    {
        $copyRecipient = [
            'address'   => ['email' => $copy->getAddress()],
            'header_to' => $to->getAddress(),
        ];

        if (!empty($recipient['substitution_data'])) {
            $copyRecipient['substitution_data'] = $recipient['substitution_data'];
        }

        return $copyRecipient;
    }

    private function getCampaignId(array $metadata, array $metadataSet): string
    {
        $campaignId = '';

        if (!empty($metadata)) {
            $id = '';

            if (!empty($metadataSet['utmTags']['utmCampaign'])) {
                $id = $metadataSet['utmTags']['utmCampaign'];
            } elseif (!empty($metadataSet['emailId']) && !empty($metadataSet['emailName'])) {
                $id = $metadataSet['emailId'].':'.$metadataSet['emailName'];
            } elseif (!empty($metadataSet['emailId'])) {
                $id = $metadataSet['emailId'];
            }

            $campaignId = mb_strcut($id, 0, 64);
        }

        return $campaignId;
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
            $this->getLogger()->warning(
                'The permission "Templates: Preview" is not enabled. '.
                'Enable it to let Mautic check email template validity before send.'
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
        if (!empty($response['errors'][0]['code']) && 1902 == (int)$response['errors'][0]['code']) {
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

    private function handleResponse(PsrResponseInterface $response): ResponseInterface
    {
        if (200 === $response->getStatusCode()) {
            return new SparkpostResponse(
                $response->getBody()->getContents(),
                $response->getStatusCode(),
                $response->getHeaders()
            );
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $this->getLogger()->error('SparkpostTransport error response', $data);

        throw new TransportException(json_encode($data['errors']), $response->getStatusCode());
    }

    private function throwException(string $message): void
    {
        throw new TransportException($message);
    }

    public function getMaxBatchLimit(): int
    {
        return 5000;
    }
}
