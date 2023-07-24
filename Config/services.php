<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'Helper/SparkpostResponse.php',
        'Mailer/Transport/SparkpostTransport.php',
    ];

    $services->set('mailer.transport_factory.sparkpost', SparkpostTransportFactory::class)
        ->args([
            service('mautic.email.model.transport_callback'),
            service('event_dispatcher'),
            service('http_client'),
            service('logger'),
        ])
        ->tag('mailer.transport_factory');

    $services->load('MauticPlugin\\SparkpostBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');
};
