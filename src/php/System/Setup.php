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
use RuneCloud\Developer\Deletion;
use RuneCloud\Developer\Registration;
use RuneCloud\Game\Deletion as GameDeletion;
use RuneCloud\Http\Request;
use RuneCloud\Http\Router;
use RuneCloud\Library\Files;
use RuneCloud\Library\Publisher;
use RuneCloud\Model\ApiTokens;
use RuneCloud\Model\Categories;
use RuneCloud\Model\Developers;
use RuneCloud\Model\FileContents;
use RuneCloud\Model\ReleaseFiles;
use RuneCloud\Model\GameVersions;
use RuneCloud\Model\Games;
use RuneCloud\Model\Revisions;
use RuneCloud\Model\ScreensaverVersions;
use RuneCloud\Model\Screensavers;
use RuneCloud\Model\Tags;
use RuneCloud\MySQL\MySQLi;
use RuneCloud\Screensaver\Deletion as ScreensaverDeletion;
use RuneCloud\Security\AdminAuth;
use RuneCloud\Security\DeveloperAuth;
use RuneCloud\Upload\Validation;

/**
 * Composes application dependencies and supplies controllers to route declarations.
 */
final class Setup
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Supplies the database connection to route models.
     *
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Contains upload configuration supplied by Main.
     * @var array<string, int|string>
     */
    private readonly array $uploads;

    /**
     * Separate administrative credential supplied by the entry point.
     * @var string
     */
    private readonly string $adminToken;

    /**
     * Separate reset confirmation supplied by the entry point.
     * @var string
     */
    private readonly string $resetPasscode;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the application's database connection.
     *
     * @param MySQLi $database Database connection shared by models.
     * @param array<string, int|string> $uploads Upload limits and storage root.
     * @param string $adminToken Administrative Bearer token; empty disables administrative access.
     * @param string $resetPasscode Reset confirmation; empty disables database reset.
     */
    public function __construct(MySQLi $database, array $uploads, string $adminToken = '', string $resetPasscode = '')
    {
        $this->database = $database;
        $this->uploads = $uploads;
        $this->adminToken = $adminToken;
        $this->resetPasscode = $resetPasscode;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Composes controllers and registers the application routes.
     *
     * @param Router $router Router receiving the API routes.
     * @param Request|null $request Explicit input for protected routes.
     *
     * @return void
     */
    public function register(Router $router, ?Request $request = null): void
    {
        $request ??= new Request();
        $routes = new Routes();
        $developerAuth = $this->createDeveloperAuth();
        $adminAuth = $this->createAdminAuth();
        $publisher = $this->createPublisher();
        
        $routes->reset($router, $request, $this->createResetController($adminAuth));
        $routes->developers($router, $request, $this->createDeveloperController($developerAuth));
        $routes->tokens($router, $request, $this->createTokenController($developerAuth));
        $routes->games($router, $request, $this->createGameController($developerAuth));
        $routes->highscores($router, $request, $this->createHighscoreController($developerAuth));
        $routes->gameVersions(
            $router, $request, $this->createGameVersionController($developerAuth, $publisher)
        );
        
        $routes->tags($router, $request, $this->createTagController($adminAuth));
        $routes->categories($router, $request, $this->createCategoryController($adminAuth));
        $routes->screensavers($router, $request, $this->createScreensaverController($developerAuth));
        $routes->screensaverVersions(
            $router, $request, $this->createScreensaverVersionController($developerAuth, $publisher)
        );
        
        $routes->library(
            $router, $request, $this->createLibraryController($adminAuth, $publisher)
        );
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Composes the administrative reset boundary and transactional workflow.
     * @param AdminAuth $authentication Shared administrative authentication.
     * @return ResetController Explicitly composed dependency.
     */
    private function createResetController(AdminAuth $authentication): ResetController
    {
        $reset = new Reset($this->database, new \RuneCloud\Library\Lock($this->database));
        return new ResetController($authentication, $reset, $this->resetPasscode);
    }

    /**
     * Composes developer authentication.
     * @return DeveloperAuth Explicitly composed dependency.
     */
    private function createDeveloperAuth(): DeveloperAuth
    {
        return new DeveloperAuth(new ApiTokens($this->database));
    }

    /**
     * Composes administrative authentication.
     * @return AdminAuth Explicitly composed dependency.
     */
    private function createAdminAuth(): AdminAuth
    {
        return new AdminAuth($this->adminToken);
    }

    /**
     * Composes library publication.
     * @return Publisher Explicitly composed dependency.
     */
    private function createPublisher(): Publisher
    {
        $revisions = new Revisions($this->database);
        $games = new Games($this->database, new \RuneCloud\Game\Validation());
        $screensavers = new Screensavers($this->database, new \RuneCloud\Screensaver\Validation());
        $snapshot = new \RuneCloud\Library\Snapshot(
            $revisions,
            $this->createTags(),
            new Categories($this->database, new \RuneCloud\Category\Validation()),
            $games, $screensavers, new Files($this->database), new FileContents($this->database),
            new \RuneCloud\Library\Rules()
        );

        return new Publisher(
            $this->database, $revisions, $games, $screensavers, $snapshot,
            $this->createGameVersions(), $this->createScreensaverVersions()
        );
    }

    /**
     * Composes game deletion.
     * @return GameDeletion Explicitly composed dependency.
     */
    private function createGameDeletion(): GameDeletion
    {
        $revisions = new Revisions($this->database);
        $contents = new FileContents($this->database);
        $removal = new \RuneCloud\Library\Removal($revisions, $contents);

        return new GameDeletion($this->database, $contents, $revisions, $removal);
    }

    /**
     * Composes screensaver deletion.
     * @return ScreensaverDeletion Explicitly composed dependency.
     */
    private function createScreensaverDeletion(): ScreensaverDeletion
    {
        $revisions = new Revisions($this->database);
        $contents = new FileContents($this->database);
        $removal = new \RuneCloud\Library\Removal($revisions, $contents);

        return new ScreensaverDeletion($this->database, $revisions, $contents, $removal);
    }

    /**
     * Composes developer registration.
     * @return Registration Explicitly composed dependency.
     */
    private function createRegistration(): Registration
    {
        return new Registration($this->database, new Developers($this->database), new ApiTokens($this->database));
    }

    /**
     * Composes developer deletion.
     * @return Deletion Explicitly composed dependency.
     */
    private function createDeveloperDeletion(): Deletion
    {
        return new Deletion(
            $this->database, $this->createGameDeletion(), new FileContents($this->database),
            new Revisions($this->database), $this->createScreensaverDeletion()
        );
    }

    /**
     * Composes developer endpoints.
     * @param DeveloperAuth $authentication Shared Developer authentication.
     * @return DeveloperController Explicitly composed dependency.
     */
    private function createDeveloperController(DeveloperAuth $authentication): DeveloperController
    {
        return new DeveloperController($authentication, $this->createRegistration(), $this->createDeveloperDeletion());
    }

    /**
     * Composes token endpoints.
     * @param DeveloperAuth $authentication Shared Developer authentication.
     * @return TokenController Explicitly composed dependency.
     */
    private function createTokenController(DeveloperAuth $authentication): TokenController
    {
        return new TokenController(new ApiTokens($this->database), $authentication);
    }

    /**
     * Composes game endpoints.
     * @param DeveloperAuth $authentication Shared Developer authentication.
     * @return GameController Explicitly composed dependency.
     */
    private function createGameController(DeveloperAuth $authentication): GameController
    {
        return new GameController(new Games($this->database, new \RuneCloud\Game\Validation()), $authentication, $this->createGameDeletion());
    }

    /**
     * Composes the on-demand highscore synchronization endpoint.
     * @param DeveloperAuth $authentication Developer token authentication.
     * @return HighscoreController Controller using the shared database connection.
     */
    private function createHighscoreController(DeveloperAuth $authentication): HighscoreController
    {
        return new HighscoreController(
            new \RuneCloud\Highscore\Sync($this->database, new \RuneCloud\Highscore\Validation()),
            new \RuneCloud\Highscore\Clear($this->database),
            $authentication
        );
    }

    /**
     * Composes game version endpoints.
     * @param DeveloperAuth $authentication Shared Developer authentication.
     * @param Publisher $publisher Shared publication service.
     * @return GameVersionController Explicitly composed dependency.
     */
    private function createGameVersionController(DeveloperAuth $authentication, Publisher $publisher): GameVersionController
    {
        $versions = $this->createGameVersions();
        return new GameVersionController($versions, $authentication, $publisher, $this->createGameDeletion());
    }

    /**
     * Composes tag storage and current library reference checks.
     * @return Tags Explicitly composed dependencies on the shared connection.
     */
    private function createTags(): Tags
    {
        $references = new \RuneCloud\Library\References(new Revisions($this->database));
        return new Tags($this->database, $references, new \RuneCloud\Tag\Validation());
    }

    /**
     * Composes tag endpoints.
     * @param AdminAuth $authentication Shared administrative authentication.
     * @return TagController Explicitly composed dependency.
     */
    private function createTagController(AdminAuth $authentication): TagController
    {
        return new TagController($this->createTags(), $authentication);
    }

    /**
     * Composes category endpoints.
     * @param AdminAuth $authentication Shared administrative authentication.
     * @return CategoryController Explicitly composed dependency.
     */
    private function createCategoryController(AdminAuth $authentication): CategoryController
    {
        $categories = new Categories($this->database, new \RuneCloud\Category\Validation());
        return new CategoryController($categories, $authentication);
    }

    /**
     * Composes screensaver endpoints.
     * @param DeveloperAuth $authentication Shared Developer authentication.
     * @return ScreensaverController Explicitly composed dependency.
     */
    private function createScreensaverController(DeveloperAuth $authentication): ScreensaverController
    {
        return new ScreensaverController(new Screensavers($this->database, new \RuneCloud\Screensaver\Validation()), $authentication, $this->createScreensaverDeletion());
    }

    /**
     * Composes screensaver version endpoints.
     * @param DeveloperAuth $authentication Shared Developer authentication.
     * @param Publisher $publisher Shared publication service.
     * @return ScreensaverVersionController Explicitly composed dependency.
     */
    private function createScreensaverVersionController(DeveloperAuth $authentication, Publisher $publisher): ScreensaverVersionController
    {
        $versions = $this->createScreensaverVersions();
        return new ScreensaverVersionController($versions, $publisher, $authentication, $this->createScreensaverDeletion());
    }

    /**
     * Composes library endpoints.
     * @param AdminAuth $authentication Shared administrative authentication.
     * @param Publisher $publisher Shared publication service.
     * @return LibraryController Explicitly composed dependency.
     */
    private function createLibraryController(AdminAuth $authentication, Publisher $publisher): LibraryController
    {
        return new LibraryController(new Files($this->database), new Revisions($this->database), $publisher, $authentication);
    }

    /**
     * Composes version storage shared by upload and publication workflows.
     * @return GameVersions Explicitly composed dependency.
     */
    private function createGameVersions(): GameVersions
    {
        return new GameVersions(
            $this->database, new Validation($this->uploads),
            new ReleaseFiles($this->database, new FileContents($this->database)), new \RuneCloud\Game\Validation()
        );
    }

    /**
     * Composes version storage shared by upload and publication workflows.
     * @return ScreensaverVersions Explicitly composed dependency.
     */
    private function createScreensaverVersions(): ScreensaverVersions
    {
        return new ScreensaverVersions(
            $this->database, new ReleaseFiles($this->database, new FileContents($this->database)),
            new Revisions($this->database), new Validation($this->uploads), new \RuneCloud\Screensaver\Validation()
        );
    }
}
