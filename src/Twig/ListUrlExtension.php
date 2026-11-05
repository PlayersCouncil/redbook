<?php

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * list_path(route, params): path() with lists written as culture[]=a&culture[]=b, as a form
 * sends them, instead of culture[0]=a&culture[1]=b; params set to null are left out. For
 * the decklists page's links, whose addresses show in the address bar (Ketura, 2026-11-05).
 */
class ListUrlExtension extends AbstractExtension
{
    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('list_path', [$this, 'listPath'])];
    }

    public function listPath(string $route, array $params = []): string
    {
        return self::unindex($this->router->generate($route, array_filter($params, fn ($v) => $v !== null)));
    }

    /** kind%5B0%5D=… to kind%5B%5D=… */
    public static function unindex(string $url): string
    {
        return preg_replace('/%5B\d+%5D=/', '%5B%5D=', $url);
    }
}
