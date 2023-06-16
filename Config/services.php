<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\SparkpostBundle\Mailer\Factory\SparkpostTransportFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = ['Mailer/Transport/SparkpostTransport.php'];

    $services->set('mailer.transport_factory.sparkpost', SparkpostTransportFactory::class)
        ->tag('mailer.transport_factory')
        ->autowire();

    $services->load('MauticPlugin\\SparkpostBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');
};
