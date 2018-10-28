<?php

namespace App\Services\TestGenerator;

use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\Route;
use Symfony\Component\Asset\Exception\LogicException;
use App\Services\TestGenerator\FileManager;
use App\Services\TestGenerator\FixturesManager;
use App\Services\TestGenerator\FeatureManager;
use App\Services\TestGenerator\LogManager;

class TestGenerator
{
    private $router;
    private $fileManager;
    private $fixturesManager;
    private $featureManager;
    private $logManager;

    private $userLoggedAs = "first-client@staffmatch.com";

    public function __construct(Router $router, FileManager $fileManager, FixturesManager $fixturesManager, FeatureManager $featureManager, LogManager $logManager)
    {
        $this->router = $router;
        $this->fileManager = $fileManager;
        $this->fixturesManager = $fixturesManager;
        $this->featureManager = $featureManager;
        $this->logManager = $logManager;
    }

    public function generate(string $namespace = null, ?array $methods = null, ?string $tag = null)
    {
        $routes = $this->getRoutesByController();

        if ($namespace) {

            if (!isset($routes[$namespace])) {
                $this->logManager->log('No route matched this namespace', LogManager::TYPE_ERROR);
                exit;
            }

            $routes = [$namespace => $routes[$namespace]];
        }

        $this->createTests($routes, $methods, $tag);
    }

    private function createTests(array $routes, ?array $methods = null, ?string $tag = null): void
    {
        $skipped = [];
        $created = 0;
        $updated = 0;
        foreach ($routes as $namespace => $routes) {
            try {
                $testFolder = $this->fileManager->getOrCreateTestFolder($namespace);
                $servicesUsed = $this->getServicesUsed($namespace);
                $fixturesDetails = $this->fixturesManager->generate($namespace, $servicesUsed);
                $status = $this->featureManager->generate($testFolder, $namespace, $fixturesDetails, $routes, $methods, $tag, $servicesUsed);

                switch ($status) {
                    case FeatureManager::FILE_CREATED:
                        $created++;
                        break;
                    case FeatureManager::FILE_UPDATED:
                        $updated++;
                        break;
                }

            } catch (LogicException $e) {
                $skipped[] = $namespace . ":" . "\n\t". LogManager::COMMENT_COLOR . $e->getMessage();
                continue;
            }
        }

        foreach ($skipped as $skip) {
            $this->logManager->log($skip, LogManager::TYPE_COMMENT);
        }
        $s = $created > 1 ? 's' : '';
        $this->logManager->log($created . " file" . $s . " created.", LogManager::TYPE_SUCCESS);
        $s = $updated > 1 ? 's' : '';
        $this->logManager->log($updated . " file" . $s  . " updated.", LogManager::TYPE_SUCCESS);
        $s = count($skipped) > 1 ? 's' : '';
        $this->logManager->log(count($skipped) . " controller" . $s  . " skipped.", LogManager::TYPE_COMMENT);
    }

    private function getServicesUsed(string $namespace): array
    {
        $reflector = new \ReflectionClass($namespace);
        $filePath = $reflector->getFileName();
        $lines = file($filePath, FILE_SKIP_EMPTY_LINES);

        $servicesUsed = [];
        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^use.+/', $line)) {
                $servicesUsed[] = str_replace(['use ', ';'], '', $line);

                continue;
            }

            if (preg_match('/^class/', $line)) {
                return $servicesUsed;
            }
        }
    }

    private function getRoutesByController(): array
    {
        $routes = [];
        foreach ($this->router->getRouteCollection() as $routeName => $route) {
            if (!$this->isFromProject($route)) {
                continue;
            }

            $namespace = explode('::', $route->getDefaults()['_controller'])[0];
            $routes[$namespace][] = [
                'routeName' => $routeName,
                'route' => $route,
            ];
        }

        return $routes;
    }

    private function isFromProject(Route $route): bool
    {
        return !!!preg_match('/\./', $route->getDefaults()['_controller']);
    }
}
