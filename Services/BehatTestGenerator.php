<?php

namespace BehatTestGenerator\Services;

use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Asset\Exception\LogicException;
use BehatTestGenerator\Manager\FileManager;
use BehatTestGenerator\Manager\FixturesManager;
use BehatTestGenerator\Manager\FeatureManager;
use BehatTestGenerator\Manager\LogManager;

class BehatTestGenerator
{
    private $router;
    private $fileManager;
    private $fixturesManager;
    private $featureManager;
    private $logManager;
    private $verbose;

    public function __construct(RouterInterface $router, FileManager $fileManager, FixturesManager $fixturesManager, FeatureManager $featureManager, LogManager $logManager)
    {
        $this->router = $router;
        $this->fileManager = $fileManager;
        $this->fixturesManager = $fixturesManager;
        $this->featureManager = $featureManager;
        $this->logManager = $logManager;
    }

    public function generate(string $namespace = null, ?array $methods = null, ?string $tag = null, ?string $fromNamespace = null, bool $verbose = false)
    {
        $this->verbose = $verbose;
        $routes = $this->getRoutesByController();

        if ($namespace) {

            if (!isset($routes[$namespace])) {
                $this->logManager->log('No route matched this namespace', LogManager::TYPE_ERROR);
                exit;
            }

            $routes = [$namespace => $routes[$namespace]];
        } elseif ($fromNamespace) {
            $routes = $this->getRoutesMatchingNamespace($routes, $fromNamespace);
        }

        $this->createTests($routes, $methods, $tag);
    }

    private function getRoutesMatchingNamespace(array $routes, string $fromNamespace): array
    {
        $r = [];
        foreach ($routes as $key => $value) {
            if (preg_match('/^' . str_replace('\\', '\\\\', $fromNamespace) . '/', $key)) {
                $r[$key] = $value;
            }
        }

        return $r;
    }

    private function createTests(array $routes, ?array $methods = null, ?string $tag = null): void
    {
        $skipped = [];
        $created = 0;
        $updated = 0;
        $testCreated = 0;
        foreach ($routes as $namespace => $routes) {
            try {
                $testFolder = $this->fileManager->getOrCreateTestFolder($namespace, $this->verbose);
                $servicesUsed = $this->getServicesUsed($namespace);
                $fixturesDetails = $this->fixturesManager->generate($namespace, $servicesUsed);
                $status = $this->featureManager->generate($testFolder, $namespace, $fixturesDetails, $routes, $methods, $tag, $servicesUsed, $this->verbose);

                if ($status) {
                    switch ($status['file']) {
                        case FileManager::FILE_CREATED:
                            $created++;
                            break;
                        case FileManager::FILE_UPDATED:
                            $updated++;
                            break;
                    }

                    $testCreated += $status['scenarios'];
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
        $s = $testCreated > 1 ? 's' : '';
        $this->logManager->log($testCreated . " test" . $s  . " created.", LogManager::TYPE_SUCCESS);
        if (count($skipped) > 0) {
            $s = count($skipped) > 1 ? 's' : '';
            $this->logManager->log(count($skipped) . " controller" . $s  . " skipped.", LogManager::TYPE_COMMENT);
        }
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
