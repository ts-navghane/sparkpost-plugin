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
        $expectedResponses = [
            function ($method, $url, $options): MockResponse {
                $payload = file_get_contents(__DIR__.'/../../SparkpostResponses/content-previewer.json');
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $requestBodyArray = json_decode($options['body'], true);
                $payloadArray     = json_decode($payload, true);
                Assert::assertArrayHasKey('UNSUBSCRIBETEXT', $requestBodyArray['substitution_data']);
                Assert::assertArrayHasKey('UNSUBSCRIBEURL', $requestBodyArray['substitution_data']);
                Assert::assertArrayHasKey('WEBVIEWTEXT', $requestBodyArray['substitution_data']);
                Assert::assertArrayHasKey('WEBVIEWURL', $requestBodyArray['substitution_data']);
                Assert::assertArrayHasKey('TRACKINGPIXEL', $requestBodyArray['substitution_data']);
                // These keys have dynamic hash id for tracking, no way to validate these, so unsetting them
                unset(
                    $requestBodyArray['substitution_data']['UNSUBSCRIBETEXT'],
                    $requestBodyArray['substitution_data']['UNSUBSCRIBEURL'],
                    $requestBodyArray['substitution_data']['WEBVIEWTEXT'],
                    $requestBodyArray['substitution_data']['WEBVIEWURL'],
                    $requestBodyArray['substitution_data']['TRACKINGPIXEL'],
                );
                Assert::assertSame($payloadArray, $requestBodyArray);
                Assert::assertSame(
                    [
                        'Authorization: some_api',
                        'Content-Type: application/json',
                        'Accept: */*',
                        'Content-Length: 1157',
                    ],
                    $options['headers']
                );
                $body = '{"results": {"subject": "Hello there!", "html": "This is test body for {contactfield=email}!"}}';

                return new MockResponse($body);
            },
            function ($method, $url, $options): MockResponse {
                $payload = file_get_contents(__DIR__.'/../../SparkpostResponses/transmissions.json');
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $requestBodyArray = json_decode($options['body'], true);
                $payloadArray     = json_decode($payload, true);
                Assert::assertArrayHasKey('UNSUBSCRIBETEXT', $requestBodyArray['recipients'][0]['substitution_data']);
                Assert::assertArrayHasKey('UNSUBSCRIBEURL', $requestBodyArray['recipients'][0]['substitution_data']);
                Assert::assertArrayHasKey('WEBVIEWTEXT', $requestBodyArray['recipients'][0]['substitution_data']);
                Assert::assertArrayHasKey('WEBVIEWURL', $requestBodyArray['recipients'][0]['substitution_data']);
                Assert::assertArrayHasKey('TRACKINGPIXEL', $requestBodyArray['recipients'][0]['substitution_data']);
                // These keys have dynamic hash id for tracking, no way to validate these, so unsetting them
                unset(
                    $requestBodyArray['recipients'][0]['substitution_data']['UNSUBSCRIBETEXT'],
                    $requestBodyArray['recipients'][0]['substitution_data']['UNSUBSCRIBEURL'],
                    $requestBodyArray['recipients'][0]['substitution_data']['WEBVIEWTEXT'],
                    $requestBodyArray['recipients'][0]['substitution_data']['WEBVIEWURL'],
                    $requestBodyArray['recipients'][0]['substitution_data']['TRACKINGPIXEL'],
                    $requestBodyArray['recipients'][0]['metadata']['hashId'],
                    $requestBodyArray['recipients'][0]['metadata']['leadId'],
                );
                Assert::assertSame($payloadArray, $requestBodyArray);
                Assert::assertSame(
                    [
                        'Authorization: some_api',
                        'Content-Type: application/json',
                        'Accept: */*',
                        'Content-Length: 1370',
                    ],
                    $options['headers']
                );
                $body = '{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}';

                return new MockResponse($body);
            },
        ];

        $mockHttpClient = self::getContainer()->get('http_client');
        Assert::assertInstanceOf(MockHttpClient::class, $mockHttpClient); // @phpstan-ignore-line
        $mockHttpClient->setResponseFactory($expectedResponses);

        $contact = $this->createContact('contact@an.email');
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, "/s/contacts/email/{$contact->getId()}");
        Assert::assertTrue($this->client->getResponse()->isOk());
        $newContent = json_decode($this->client->getResponse()->getContent(), true)['newContent'];
        $crawler    = new Crawler($newContent, $this->client->getInternalRequest()->getUri());
        $form       = $crawler->selectButton('Send')->form();
        $form->setValues(
            [
                'lead_quickemail[subject]' => 'Hello there!',
                'lead_quickemail[body]'    => 'This is test body for {contactfield=email}!',
            ]
        );
        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk());
        self::assertQueuedEmailCount(1);

        $email      = self::getMailerMessage();
        $userHelper = static::getContainer()->get(UserHelper::class);
        $user       = $userHelper->getUser();

        Assert::assertSame('Hello there!', $email->getSubject());
        Assert::assertStringContainsString('This is test body for {contactfield=email}!', $email->getHtmlBody());
        Assert::assertSame('This is test body for {contactfield=email}!', $email->getTextBody());
        /** @phpstan-ignore-next-line */
        Assert::assertSame('contact@an.email', $email->getMetadata()['contact@an.email']['tokens']['{contactfield=email}']);
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
