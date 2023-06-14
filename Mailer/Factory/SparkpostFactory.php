<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Mailer\Factory;

use Http\Adapter\Guzzle7\Client as GuzzleAdapter;
use SparkPost\SparkPost;

class SparkpostFactory implements SparkpostFactoryInterface
{
    public function __construct(private GuzzleAdapter $client)
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
            $options = $this->buildOptions($hostInfo, $port);
        }

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
