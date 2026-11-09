<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\BannerAlert;
use App\Repository\FormatRepository;
use App\Services\PublishedDeckManager;
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
     * @param PublishedDeckManager $decklistManager
     * @param FormatRepository $formats
     * @param TranslatorInterface $translator
     * @return Response
     * @throws Exception
     */
    #[Route(path: '/', name: 'index', methods: ['GET'])]
    public function indexAction(
        int $cacheExpiration,
        string $gameName,
        string $publisherName,
        PublishedDeckManager $decklistManager,
        FormatRepository $formats,
        TranslatorInterface $translator,
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
                // the site's own title: Red Book, a LOTR-TCG Deck Repository (Ketura, 2026-11-09)
                'pagetitle' => null,
                'pagedescription' => $translator->trans('site.description'),
                'popular_decklists' => $popular,
                'recent_decklists' => $recent,
                'banner_alerts' => $alerts,
                'format_names' => array_map(fn ($f) => $f->getName(), $formats->findAllByCode()),
            ],
            $response
        );
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
                "pagedescription" => "Card search syntax",
            ]
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
