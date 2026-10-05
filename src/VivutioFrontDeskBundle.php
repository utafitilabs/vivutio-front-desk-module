<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio front desk module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\FrontDesk;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The front desk: a day's arrivals, guests in house and departures at a
 * place, from whatever stays the installation's packages offer through the
 * core's stay contracts. It keeps only its own record of each stay: checked
 * in, checked out or a no-show, and the units it was given.
 */
final class VivutioFrontDeskBundle extends AbstractBundle
{
    /** Configuration lives under "front_desk:". */
    protected string $extensionAlias = 'front_desk';

    /**
     * Its entities' mapping and its migrations, prepended so the installation
     * keeps the last word; each guarded.
     *
     * @see https://symfony.com/doc/current/bundles/prepend_extension.html
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'FrontDesk' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Vivutio\\FrontDesk\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ], prepend: true);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Vivutio\\FrontDesk\\Migrations' => \dirname(__DIR__).'/migrations',
                ],
            ], prepend: true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
