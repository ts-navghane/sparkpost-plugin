<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Unit\Mailer\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostClientFactory;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SparkPost\SparkPost;
use SparkPost\SparkPostPromise;
use SparkPost\SparkPostResponse;
use SparkPost\Transmission;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransportTest extends TestCase
{
    private TranslatorInterface|MockObject $translatorMock;

    private SparkpostClientFactory|MockObject $sparkpostClientFactoryMock;

    private TransportCallback|MockObject $transportCallbackMock;

    private HttpClientInterface|MockObject $httpClientMock;

    private EventDispatcherInterface|MockObject $eventDispatcherMock;

    private LoggerInterface|MockObject $loggerMock;

    private SparkpostTransport $transport;

    protected function setUp(): void
    {
        $this->translatorMock             = $this->createMock(TranslatorInterface::class);
        $this->sparkpostClientFactoryMock = $this->createMock(SparkpostClientFactory::class);
        $this->transportCallbackMock      = $this->createMock(TransportCallback::class);
        $this->httpClientMock             = $this->createMock(HttpClientInterface::class);
        $this->eventDispatcherMock        = $this->createMock(EventDispatcherInterface::class);
        $this->loggerMock                 = $this->createMock(LoggerInterface::class);
        $this->transport                  = new SparkpostTransport(
            'api-key',
            'some-region',
            $this->translatorMock,
            $this->sparkpostClientFactoryMock,
            $this->transportCallbackMock,
            $this->httpClientMock,
            $this->eventDispatcherMock,
            $this->loggerMock
        );
    }

    public function testInstanceOfSparkpostTransport(): void
    {
        Assert::assertInstanceOf(SparkpostTransport::class, $this->transport);
    }

    public function testSendEmail(): void
    {
        $sentMessageMock       = $this->createMock(SentMessage::class);
        $mauticMessageMock     = $this->createMock(MauticMessage::class);
        $sparkPostMock         = $this->createMock(SparkPost::class);
        $sparkPostPromiseMock  = $this->createMock(SparkPostPromise::class);
        $sparkPostResponseMock = $this->createMock(SparkPostResponse::class);
        $transmissionMock      = $this->createMock(Transmission::class);

        $sentMessageMock->method('getOriginalMessage')
            ->willReturn($mauticMessageMock);

        $fromAddress    = new Address('from@mautic.com', 'From Name');
        $replyToAddress = new Address('reply@mautic.com', 'Reply To Name');

        $mauticMessageMock->method('getFrom')
            ->willReturn([$fromAddress]);

        $mauticMessageMock->method('getReplyTo')
            ->willReturn([$replyToAddress]);

        $mauticMessageMock->method('getHeaders')
            ->willReturn(new Headers());

        $sparkPostResponseMock->method('getBody')
            ->willReturn([]);

        $sparkPostResponseMock->method('getStatusCode')
            ->willReturn(200);

        $sparkPostResponseMock->method('getHeaders')
            ->willReturn([]);

        $sparkPostPromiseMock->method('wait')
            ->willReturn($sparkPostResponseMock);

        $sparkPostMock->method('request')
            ->willReturn($sparkPostPromiseMock);

        $payload = [
            'content'     => [
                'from'        => 'From Name <from@mautic.com>',
                'subject'     => null,
                'headers'     => new \stdClass(),
                'html'        => null,
                'text'        => null,
                'reply_to'    => 'reply@mautic.com',
                'attachments' => [],
            ],
            'recipients'  => [],
            'inline_css'  => null,
            'tags'        => [],
            'campaign_id' => '',
            'options'     => [
                'open_tracking'  => false,
                'click_tracking' => false,
            ],
        ];

        $sparkPostMock->transmissions = $transmissionMock;
        $transmissionMock->method('post')
            ->with($payload)
            ->willReturn($sparkPostPromiseMock);

        $this->sparkpostClientFactoryMock->method('create')
            ->willReturn($sparkPostMock);

        $response = $this->invokeInaccessibleMethod(
            $this->transport,
            'doSendApi',
            [
                $sentMessageMock,
                $this->createMock(Email::class),
                $this->createMock(Envelope::class),
            ]
        );

        Assert::assertInstanceOf(ResponseInterface::class, $response);
    }

    private function invokeInaccessibleMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method     = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
