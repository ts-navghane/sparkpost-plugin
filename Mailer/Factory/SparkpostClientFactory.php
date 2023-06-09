<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Mailer\Factory;

use Http\Client\HttpClient;
use SparkPost\SparkPost;

class SparkpostClientFactory
{
    public function __construct(private HttpClient $client)
    {
    }

    public function create(string $host, string $apiKey, int $port = null): SparkPost
    {
        if (!str_contains($host, '://') && !str_starts_with($host, '/')) {
            $host = 'https://'.$host;
        }

        // prevent Exception: You must provide an API key
        $options['key'] = ($apiKey) ?: 1234;

        if ($port) {
            $options['port'] = $port;
        }

        $hostInfo = parse_url($host);

        if ($hostInfo) {
            $options = array_merge($options, $this->buildOptions($hostInfo, $port));
        }

        // Must always return a SparkPost host or else Symfony will fail to build the container if host is empty
        return new SparkPost($this->client, $options);
    }

    private function buildOptions(array $hostInfo, ?int $port): array
    {
        $options['protocol'] = $hostInfo['scheme'];

        if (empty($port)) {
            $options['port'] = 'https' === $hostInfo['scheme'] ? 443 : 80;
        }

        $host = $hostInfo['host'];

        if (isset($hostInfo['path'])) {
            $path = $hostInfo['path'];

            if (preg_match('~/api/(v\d+)$~i', $path, $matches)) {
                // Remove /api from the path and extract the version in case different than the Sparkpost SDK default
                $path               = str_replace($matches[0], '', $path);
                $options['version'] = $matches[1];
            }

            // Append whatever is left over to the host (assuming Momentum can be in a sub-folder?)
            if ('/' !== $path) {
                $host .= $path;
            }
        }

        $options['host'] = $host;

        return $options;
    }
}
