<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\BannerAlert;
use App\Services\DecklistManager;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class DefaultController extends AbstractController
{
    use LocaleAwareTemplating;

    public function __construct(private readonly ManagerRegistry $managerRegistry)
    {
    }

    /**
     *
     * @param int $cacheExpiration
     * @param string $gameName
     * @param string $publisherName
     * @param DecklistManager $decklistManager
     * @return Response
     * @throws Exception
     */
    #[Route(path: '/', name: 'index', methods: ['GET'])]
    public function indexAction(
        int $cacheExpiration,
        string $gameName,
        string $publisherName,
        DecklistManager $decklistManager,
    ) {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $decklistManager->setLimit(10);
        $popular = iterator_to_array($decklistManager->findDecklistsByPopularity());
        $recent = iterator_to_array($decklistManager->findDecklistsByAge());

        $alerts = $this->managerRegistry->getRepository(BannerAlert::class)
            ->findBy(['active' => true], ['id' => 'DESC']);

        return $this->render(
            'Default/index.html.twig',
            [
                'pagetitle' => "{$gameName} Deckbuilder",
                'pagedescription' => "Build your deck for {$gameName}."
                    . " Browse the cards and the decklists submitted by the community."
                    . " Publish your own decks and get feedback.",
                'popular_decklists' => $popular,
                'recent_decklists' => $recent,
                'banner_alerts' => $alerts,
            ],
            $response
        );
    }

    /**
     * @param int $cacheExpiration
     * @param TranslatorInterface $translator
     * @return Response
     */
    #[Route(path: '/rulesreference', name: 'rulesreference', methods: ['GET'])]
    public function rulesreferenceAction(int $cacheExpiration, TranslatorInterface $translator)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $page = $this->renderView(
            'Default/rulesreference.html.twig',
            array("pagetitle" => $translator->trans("nav.rules"), "pagedescription" => "Rules Reference")
        );
        $response->setContent($page);

        return $response;
    }

    /**
     * @param int $cacheExpiration
     * @param TranslatorInterface $translator
     * @return Response
     */
    #[Route(path: '/faq', name: 'faq', methods: ['GET'])]
    public function faqAction(int $cacheExpiration, TranslatorInterface $translator)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $page = $this->renderView(
            'Default/faq.html.twig',
            array("pagetitle" => $translator->trans("nav.rules"), "pagedescription" => "F.A.Q")
        );
        $response->setContent($page);

        return $response;
    }

    /**
     * @param int $cacheExpiration
     * @param TranslatorInterface $translator
     * @return Response
     */
    #[Route(path: '/draft', name: 'draft', methods: ['GET'])]
    public function draftAction(int $cacheExpiration, TranslatorInterface $translator)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $page = $this->renderView(
            'Default/draft.html.twig',
            array("pagetitle" => $translator->trans("nav.draft"), "pagedescription" => "Draft F.A.Q")
        );
        $response->setContent($page);

        return $response;
    }

    /**
     * @param int $cacheExpiration
     * @param TranslatorInterface $translator
     * @return Response
     */
    #[Route(path: '/syntax', name: 'syntax', methods: ['GET'])]
    public function syntaxAction(int $cacheExpiration, TranslatorInterface $translator): Response
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $page = $this->renderView(
            'Default/syntax.html.twig',
            [
                "pagetitle" => $translator->trans("nav.syntax"),
                "pagedescription" => "ThronesDB Search Syntax documentation",
            ]
        );
        $response->setContent($page);

        return $response;
    }

    /**
     * @param int $cacheExpiration
     * @param TranslatorInterface $translator
     * @return Response
     */
    #[Route(path: '/tournamentregulations', name: 'tournamentregulations', methods: ['GET'])]
    public function tournamentregulationsAction(int $cacheExpiration, TranslatorInterface $translator)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        $page = $this->renderView(
            'Default/tournamentregulations.html.twig',
            array(
                "pagetitle" => $translator->trans("nav.rules"),
                "pagedescription" => "Tournament Regulations",
            )
        );
        $response->setContent($page);

        return $response;
    }

    /**
     * @param Request $request
     * @param int $cacheExpiration
     * @param string $gameName
     * @return Response
     */
    #[Route(path: '/about', name: 'about', methods: ['GET'])]
    public function aboutAction(Request $request, int $cacheExpiration, string $gameName)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        return $this->render(
            $this->getLocaleSpecificViewPath('about', $request->getLocale()),
            array(
                "pagetitle" => "About",
                "game_name" => $gameName,
            ),
            $response
        );
    }

    /**
     * @param int $cacheExpiration
     * @param string $gameName
     * @param string $publisherName
     * @return Response
     */
    #[Route(path: '/api/', name: 'api_intro', methods: ['GET'])]
    public function apiIntroAction(int $cacheExpiration, string $gameName, string $publisherName)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($cacheExpiration);

        return $this->render(
            'Default/apiIntro.html.twig',
            array(
                "pagetitle" => "API",
                "game_name" => $gameName,
                "publisher_name" => $publisherName,
            ),
            $response
        );
    }
}
