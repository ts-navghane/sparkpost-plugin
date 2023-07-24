<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Functional\Mailer\Transport;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

class SparkpostTransportTest extends MauticMysqlTestCase
{
    protected function setUp(): void
    {
        $this->configParams['mailer_dsn']          = 'mautic+sparkpost+api://:some_api@some_host:25?region=us';
        $this->configParams['messenger_dsn_email'] = 'sync://';
        parent::setUp();
    }

    public function testEmailSendToContactSync(): void
    {
        $expectedRequests = [
            function ($method, $url, $options): MockResponse {
                Assert::assertEquals(Request::METHOD_POST, $method);
                Assert::assertEquals('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $body = '{"results": {"subject": "Ahoy contact@an.email", "html": ""}}';

                return new MockResponse($body);
            },
            function ($method, $url, $options): MockResponse {
                Assert::assertEquals(Request::METHOD_POST, $method);
                Assert::assertEquals('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $body = '{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}';

                return new MockResponse($body);
            },
        ];

        $mockHttpClient = self::getContainer()->get('http_client');
        Assert::assertInstanceOf(MockHttpClient::class, $mockHttpClient); // @phpstan-ignore-line
        $mockHttpClient->setResponseFactory($expectedRequests);

        $contact = $this->createContact('contact@an.email');
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, "/s/contacts/email/{$contact->getId()}");

        Assert::assertTrue($this->client->getResponse()->isOk());
        $crawler = new Crawler(
            json_decode($this->client->getResponse()->getContent(), true)['newContent'],
            $this->client->getInternalRequest()->getUri()
        );
        $form    = $crawler->selectButton('Send')->form();
        $form->setValues(
            [
                'lead_quickemail[subject]' => 'Hello there!',
                'lead_quickemail[body]'    => 'This is test body!',
            ]
        );
        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk());
        self::assertQueuedEmailCount(1);

        $email      = self::getMailerMessage();
        $userHelper = static::getContainer()->get(UserHelper::class);
        $user       = $userHelper->getUser();

        Assert::assertSame('Hello there!', $email->getSubject());
        Assert::assertStringContainsString('This is test body!', $email->getHtmlBody());
        Assert::assertSame('This is test body!', $email->getTextBody());
        Assert::assertCount(1, $email->getFrom());
        Assert::assertSame($user->getName(), $email->getFrom()[0]->getName());
        Assert::assertSame($user->getEmail(), $email->getFrom()[0]->getAddress());
        Assert::assertCount(1, $email->getTo());
        Assert::assertSame('', $email->getTo()[0]->getName());
        Assert::assertSame($contact->getEmail(), $email->getTo()[0]->getAddress());
        Assert::assertCount(1, $email->getReplyTo());
        Assert::assertSame('', $email->getReplyTo()[0]->getName());
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        $this->em->persist($lead);

        return $lead;
    }
}
