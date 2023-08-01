<?php

declare(strict_types=1);

use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('MauticPlugin\\SparkpostBundle\\', '../')
        ->exclude('../{Config,Helper/SparkpostResponse.php,Mailer/Transport/SparkpostTransport.php}');

    $services->get(SparkpostTransportFactory::class)->tag('mailer.transport_factory');
};
