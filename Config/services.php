<?php

declare(strict_types=1);

use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('MauticPlugin\\SparkpostBundle\\', '../')
        ->exclude('../{Config,Helper/SparkpostResponse.php,Mailer/Transport/SparkpostTransport.php}');

    $services->get(SparkpostTransportFactory::class)
        ->args([
            service('mautic.email.model.transport_callback'),
            service('event_dispatcher'),
            service('http_client'),
            service('logger'),
        ])
        ->tag('mailer.transport_factory');
};
