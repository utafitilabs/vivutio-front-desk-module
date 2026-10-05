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

namespace Vivutio\FrontDesk\ValueResolver;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vivutio\Bundle\IdentityBundle\Service\PlaceDirectoryService;
use Vivutio\Contracts\Place\PlaceInterface;

/**
 * A controller's PlaceInterface argument, from the route's {kind} and {id},
 * so its #[IsGranted] can ask about the place itself; a place nothing offers
 * is not found.
 *
 * @see https://symfony.com/doc/current/controller/value_resolver.html
 * @see vendor/symfony/doctrine-bridge/ArgumentResolver/EntityValueResolver.php — resolve(), which this follows for one interface
 */
final readonly class PlaceValueResolver implements ValueResolverInterface
{
    public function __construct(private PlaceDirectoryService $places)
    {
    }

    /**
     * @return iterable<PlaceInterface>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (PlaceInterface::class !== $argument->getType()) {
            return [];
        }

        $kind = $request->attributes->get('kind');
        $id = $request->attributes->get('id');
        $place = \is_string($kind) && \is_string($id) ? $this->places->find($kind, $id) : null;

        return [$place ?? throw new NotFoundHttpException()];
    }
}
