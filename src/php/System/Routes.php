<?php

declare(strict_types=1);

namespace RuneCloud\System;

use RuneCloud\Controller\CategoryController;
use RuneCloud\Controller\DeveloperController;
use RuneCloud\Controller\GameController;
use RuneCloud\Controller\GameVersionController;
use RuneCloud\Controller\HighscoreController;
use RuneCloud\Controller\LibraryController;
use RuneCloud\Controller\ResetController;
use RuneCloud\Controller\ScreensaverController;
use RuneCloud\Controller\ScreensaverVersionController;
use RuneCloud\Controller\TagController;
use RuneCloud\Controller\TokenController;
use RuneCloud\Http\JsonResponse;
use RuneCloud\Http\Request;
use RuneCloud\Http\Router;

/**
 * Declares HTTP methods, paths and actions using explicitly supplied controllers.
 */
final class Routes
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Registers the on-demand highscore synchronization endpoint.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param HighscoreController $controller Endpoint controller.
     * @return void
     */
    public function highscores(Router $router, Request $request, HighscoreController $controller): void
    {
        $router->add('POST', '/v1/highscores/sync', fn (): array => $controller->synchronize($request));
    }

    /**
     * Registers the administrative reset endpoint.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param ResetController $controller Endpoint controller.
     * @return void
     */
    public function reset(Router $router, Request $request, ResetController $controller): void
    {
        $router->add('POST', '/v1/reset', fn (): array => $controller->reset($request));
    }

    /**
     * Registers developer endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param DeveloperController $controller Endpoint controller.
     * @return void
     */
    public function developers(Router $router, Request $request, DeveloperController $controller): void
    {
        $router->add('POST', '/v1/developers', fn (): JsonResponse => $controller->create($request));
        $router->add('GET', '/v1/developer', fn (): array => $controller->show($request));
        $router->add('DELETE', '/v1/developer', fn (): array => $controller->delete($request));
    }

    /**
     * Registers token endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param TokenController $controller Endpoint controller.
     * @return void
     */
    public function tokens(Router $router, Request $request, TokenController $controller): void
    {
        $router->add('DELETE', '/v1/developer/tokens/{id}',
            fn ($id): array => $controller->delete($id, $request));
    }

    /**
     * Registers game endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param GameController $controller Endpoint controller.
     * @return void
     */
    public function games(Router $router, Request $request, GameController $controller): void
    {
        $router->add('GET', '/v1/games', [$controller, 'index']);
        $router->add('GET', '/v1/developer/games', fn (): array => $controller->owned($request));
        $router->add('POST', '/v1/games', fn (): JsonResponse => $controller->create($request));
        $router->add('GET', '/v1/games/{id}', [$controller, 'show']);
        $router->add('PUT', '/v1/games/{id}', fn ($id): array => $controller->update($id, $request));
        $router->add('DELETE', '/v1/games/{id}', fn ($id): array => $controller->delete($id, $request));
    }

    /**
     * Registers gameversion endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param GameVersionController $controller Endpoint controller.
     * @return void
     */
    public function gameVersions(Router $router, Request $request, GameVersionController $controller): void
    {
        $router->add('GET', '/v1/games/{id}/versions', fn ($id): array => $controller->index($id, $request));
        $router->add('POST', '/v1/games/{id}/versions', fn ($id): JsonResponse => $controller->create($id, $request));
        $router->add('GET', '/v1/games/{id}/versions/{version}',
            fn ($id, $version): array => $controller->show($id, $version, $request));
        $router->add('DELETE', '/v1/games/{id}/versions/{version}',
            fn ($id, $version): array => $controller->delete($id, $version, $request));
        $router->add('POST', '/v1/games/{id}/versions/{version}/files',
            fn ($id, $version): JsonResponse => $controller->upload($id, $version, $request));
        $router->add('POST', '/v1/games/{id}/versions/{version}/publish',
            fn ($id, $version): JsonResponse => $controller->publish($id, $version, $request));
    }

    /**
     * Registers tag endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param TagController $controller Endpoint controller.
     * @return void
     */
    public function tags(Router $router, Request $request, TagController $controller): void
    {
        $router->add('GET', '/v1/tags', [$controller, 'index']);
        $router->add('GET', '/v1/tags/{id}', [$controller, 'show']);
        $router->add('POST', '/v1/tags', fn (): JsonResponse => $controller->create($request));
        $router->add('PUT', '/v1/tags/{id}', fn ($id): array => $controller->update($id, $request));
        $router->add('DELETE', '/v1/tags/{id}', fn ($id): array => $controller->delete($id, $request));
    }

    /**
     * Registers category endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param CategoryController $controller Endpoint controller.
     * @return void
     */
    public function categories(Router $router, Request $request, CategoryController $controller): void
    {
        $router->add('GET', '/v1/categories', [$controller, 'index']);
        $router->add('GET', '/v1/categories/{id}', [$controller, 'show']);
        $router->add('POST', '/v1/categories', fn (): JsonResponse => $controller->create($request));
        $router->add('PUT', '/v1/categories/{id}', fn ($id): array => $controller->update($id, $request));
        $router->add('DELETE', '/v1/categories/{id}', fn ($id): array => $controller->delete($id, $request));
    }

    /**
     * Registers screensaver endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param ScreensaverController $controller Endpoint controller.
     * @return void
     */
    public function screensavers(Router $router, Request $request, ScreensaverController $controller): void
    {
        $router->add('GET', '/v1/screensavers', [$controller, 'index']);
        $router->add('GET', '/v1/developer/screensavers', fn (): array => $controller->owned($request));
        $router->add('POST', '/v1/screensavers', fn (): JsonResponse => $controller->create($request));
        $router->add('GET', '/v1/screensavers/{id}', [$controller, 'show']);
        $router->add('PUT', '/v1/screensavers/{id}', fn ($id): array => $controller->update($id, $request));
        $router->add('DELETE', '/v1/screensavers/{id}',
            fn ($id): array => $controller->delete($id, $request));
    }

    /**
     * Registers screensaverversion endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param ScreensaverVersionController $controller Endpoint controller.
     * @return void
     */
    public function screensaverVersions(Router $router, Request $request, ScreensaverVersionController $controller): void
    {
        $router->add('GET', '/v1/screensavers/{id}/versions',
            fn ($id): array => $controller->index($id, $request));
        $router->add('POST', '/v1/screensavers/{id}/versions',
            fn ($id): JsonResponse => $controller->create($id, $request));
        $router->add('GET', '/v1/screensavers/{id}/versions/{version}',
            fn ($id, $version): array => $controller->show($id, $version, $request));
        $router->add('POST', '/v1/screensavers/{id}/versions/{version}/files',
            fn ($id, $version): JsonResponse => $controller->upload($id, $version, $request));
        $router->add('POST', '/v1/screensavers/{id}/versions/{version}/publish',
            fn ($id, $version): JsonResponse => $controller->publish($id, $version, $request));
        $router->add('DELETE', '/v1/screensavers/{id}/versions/{version}',
            fn ($id, $version): array => $controller->delete($id, $version, $request));
    }

    /**
     * Registers library endpoints using an explicitly supplied controller.
     * @param Router $router Route registry.
     * @param Request $request Current input.
     * @param LibraryController $controller Endpoint controller.
     * @return void
     */
    public function library(Router $router, Request $request, LibraryController $controller): void
    {
        $router->add('GET', '/v1/library', [$controller, 'index']);
        $router->add('POST', '/v1/library/publish', fn (): JsonResponse => $controller->publish($request));
        $router->add('GET', '/v1/library/releases/{revision}/{path...}', [$controller, 'download']);
    }
}
