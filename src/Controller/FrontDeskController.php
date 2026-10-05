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

namespace Vivutio\FrontDesk\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\FrontDesk\Exception\DeskRefusedException;
use Vivutio\FrontDesk\Service\FrontDeskService;

/**
 * The desk: the places it serves, a place's board for a day, and checking in,
 * checking out and no-shows. The board is read with front_desk.read and the
 * rest recorded with front_desk.record, a module's pairs a department must
 * allow, asked about the place, so reach applies.
 */
final readonly class FrontDeskController
{
    public const string PLACES = 'front_desk_places';
    public const string BOARD = 'front_desk_board';
    public const string CHECK_IN = 'front_desk_check_in';
    public const string CHECK_OUT = 'front_desk_check_out';
    public const string NO_SHOW = 'front_desk_no_show';
    public const string UNDO_NO_SHOW = 'front_desk_undo_no_show';

    public const string READ = 'front_desk.read';
    public const string RECORD = 'front_desk.record';

    private const string SAID = 'front_desk.said';
    private const string KIND = '[a-z0-9_]+';

    public function __construct(
        private Environment $twig,
        private FrontDeskService $desk,
        private AuthorizationCheckerInterface $authorization,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/front-desk', name: self::PLACES, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function places(): Response
    {
        return new Response($this->twig->render('@VivutioFrontDesk/front_desk/places.html.twig', [
            'places' => array_values(array_filter($this->desk->places(), fn (PlaceInterface $place): bool => $this->authorization->isGranted(self::READ, $place))),
        ]));
    }

    #[Route('/front-desk/{kind}/{id}', name: self::BOARD, requirements: ['kind' => self::KIND, 'id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ, subject: 'place')]
    public function board(Request $request, PlaceInterface $place): Response
    {
        $typed = $request->query->getString('day');
        $day = 1 === preg_match('{^\d{4}-\d{2}-\d{2}$}D', $typed) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $typed) : false;

        return $this->page($place, false === $day ? $this->desk->today() : $day, said: $this->said($request));
    }

    #[Route('/front-desk/{kind}/{id}/stays/{stayKind}/{stayId}/check-in', name: self::CHECK_IN, requirements: ['kind' => self::KIND, 'id' => Requirement::UUID, 'stayKind' => self::KIND, 'stayId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::RECORD, subject: 'place')]
    public function checkIn(Request $request, PlaceInterface $place, string $stayKind, string $stayId): Response
    {
        $units = [];
        foreach ($request->getPayload()->all('units') as $type => $typed) {
            if (\is_string($typed)) {
                $units[(string) $type] = $typed;
            }
        }

        return $this->act($request, $place, $stayKind, $stayId, 'front_desk_check_in', function ($stay) use ($place, $units): string {
            $this->desk->checkIn($place, $stay, $units);

            return \sprintf('%s is checked in.', $stay->getReference());
        });
    }

    #[Route('/front-desk/{kind}/{id}/stays/{stayKind}/{stayId}/check-out', name: self::CHECK_OUT, requirements: ['kind' => self::KIND, 'id' => Requirement::UUID, 'stayKind' => self::KIND, 'stayId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::RECORD, subject: 'place')]
    public function checkOut(Request $request, PlaceInterface $place, string $stayKind, string $stayId): Response
    {
        return $this->act($request, $place, $stayKind, $stayId, 'front_desk_check_out', function ($stay): string {
            $this->desk->checkOut($stay);

            return \sprintf('%s is checked out.', $stay->getReference());
        });
    }

    #[Route('/front-desk/{kind}/{id}/stays/{stayKind}/{stayId}/no-show', name: self::NO_SHOW, requirements: ['kind' => self::KIND, 'id' => Requirement::UUID, 'stayKind' => self::KIND, 'stayId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::RECORD, subject: 'place')]
    public function noShow(Request $request, PlaceInterface $place, string $stayKind, string $stayId): Response
    {
        return $this->act($request, $place, $stayKind, $stayId, 'front_desk_no_show', function ($stay) use ($place): string {
            $this->desk->noShow($place, $stay);

            return \sprintf('%s is marked a no-show.', $stay->getReference());
        });
    }

    #[Route('/front-desk/{kind}/{id}/stays/{stayKind}/{stayId}/undo-no-show', name: self::UNDO_NO_SHOW, requirements: ['kind' => self::KIND, 'id' => Requirement::UUID, 'stayKind' => self::KIND, 'stayId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::RECORD, subject: 'place')]
    public function undoNoShow(Request $request, PlaceInterface $place, string $stayKind, string $stayId): Response
    {
        return $this->act($request, $place, $stayKind, $stayId, 'front_desk_undo_no_show', function ($stay): string {
            $this->desk->undoNoShow($stay);

            return \sprintf('%s is expected again.', $stay->getReference());
        });
    }

    /**
     * @param callable(\Vivutio\Contracts\Stay\StayInterface): string $action
     */
    private function act(Request $request, PlaceInterface $place, string $stayKind, string $stayId, string $token, callable $action): Response
    {
        $day = $this->desk->today();
        try {
            $stay = $this->desk->stay($place, $stayKind, $stayId);
        } catch (DeskRefusedException) {
            throw new NotFoundHttpException();
        }
        if (!$this->tokens->isTokenValid(new CsrfToken($token, $request->getPayload()->getString('_token')))) {
            return $this->page($place, $day, expired: true);
        }

        try {
            $said = $action($stay);
        } catch (DeskRefusedException $refusal) {
            return $this->page($place, $day, [$refusal->field => $refusal->getMessage()], refusedFor: $stay->getStayId());
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAID, $said);
        }

        return new RedirectResponse($this->urls->generate(self::BOARD, ['kind' => $place->getPlaceKind(), 'id' => $place->getPlaceId(), 'day' => $day->format('Y-m-d')]));
    }

    private function said(Request $request): ?string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $said = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::SAID) : [];

        return \is_string($said[0] ?? null) ? $said[0] : null;
    }

    /**
     * @param array<string, string> $wrong
     */
    private function page(PlaceInterface $place, \DateTimeImmutable $day, array $wrong = [], bool $expired = false, ?string $said = null, ?string $refusedFor = null): Response
    {
        return new Response($this->twig->render('@VivutioFrontDesk/front_desk/board.html.twig', [
            'board' => $this->desk->board($place, $day),
            'today' => $this->desk->today(),
            'wrong' => $wrong,
            'refused_for' => $refusedFor,
            'expired' => $expired,
            'said' => $said,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
