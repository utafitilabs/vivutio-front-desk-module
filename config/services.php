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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Deletion\DeletionContributorInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Contracts\Stay\StaySourceInterface;
use Vivutio\Contracts\Stay\UnitSourceInterface;
use Vivutio\FrontDesk\Access\FrontDeskConcerns;
use Vivutio\FrontDesk\Controller\FrontDeskController;
use Vivutio\FrontDesk\Deletion\PlaceDeskDeletion;
use Vivutio\FrontDesk\Repository\DeskStayRepository;
use Vivutio\FrontDesk\Service\FrontDeskService;
use Vivutio\FrontDesk\Shell\FrontDeskMenu;
use Vivutio\FrontDesk\ValueResolver\PlaceValueResolver;

/*
 * Every service is defined explicitly, with an id prefixed by the bundle's
 * alias; nothing is autowired or autoconfigured, so a tag is applied by hand.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('front_desk.access.concerns', FrontDeskConcerns::class)
        ->tag(ConcernSourceInterface::TAG);

    $services->set('front_desk.menu', FrontDeskMenu::class)
        ->tag(MenuSourceInterface::TAG);

    $services->set(DeskStayRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    // The desk works from every stay and every place's units the
    // installation's packages offer.
    $services->set('front_desk.desk', FrontDeskService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(DeskStayRepository::class),
            service('identity.places'),
            tagged_iterator(StaySourceInterface::TAG),
            tagged_iterator(UnitSourceInterface::TAG),
        ]);
    $services->alias(FrontDeskService::class, 'front_desk.desk');

    // A controller's place, from the route's kind and id.
    // @see https://symfony.com/doc/current/controller/value_resolver.html#adding-a-custom-value-resolver
    $services->set('front_desk.value_resolver.place', PlaceValueResolver::class)
        ->args([service('identity.places')])
        ->tag('controller.argument_value_resolver', ['priority' => 150]);

    $services->set('front_desk.controller', FrontDeskController::class)
        ->args([
            service('twig'),
            service('front_desk.desk'),
            service('security.authorization_checker'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(FrontDeskController::class, 'front_desk.controller')->public();

    // The desk's records at a place a Super Admin deletes go with it.
    $services->set('front_desk.deletion', PlaceDeskDeletion::class)
        ->args([service('doctrine.orm.entity_manager')])
        ->tag(DeletionContributorInterface::TAG);
};
