<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Helper;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SparkpostResponse implements ResponseInterface
{
    public function __construct(private string $content, private int $statusCode, private array $headers)
    {
    }

    public function getContent(bool $throw = true): string
    {
        return $this->content;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function toArray(bool $throw = true): array
    {
        return [
            'content'    => $this->content,
            'statusCode' => $this->statusCode,
            'headers'    => $this->headers,
        ];
    }

    public function cancel(): void
    {
        // TODO: Implement cancel() method.
    }

    public function getInfo(string $type = null)
    {
        // TODO: Implement getInfo() method.
    }
}
