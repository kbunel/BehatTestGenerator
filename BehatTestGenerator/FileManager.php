<?php

namespace App\Services\TestGenerator;

use Symfony\Component\Filesystem\Filesystem;
use App\Services\TestGenerator\LogManager;

class FileManager
{
    private $fs;
    private $featureRootPath;

    public function __construct(string $featureRootPath, Filesystem $fs, LogManager $logManager)
    {
        $this->fs = $fs;
        $this->featureRootPath = $featureRootPath;
        $this->logManager = $logManager;
    }

    public function parseTemplate(string $templatePath, array $parameters): string
    {
        ob_start();
        extract($parameters, EXTR_SKIP);
        include $templatePath;

        return ob_get_clean();
    }

    public function getOrCreateTestFolder(string $namespace): string
    {
        $controllerPath = $this->getPathFromNamespace($namespace);
        $path = $this->featureRootPath . DIRECTORY_SEPARATOR . $controllerPath;

        if (!file_exists($path)) {
            mkdir($path, 0777, true);
            $this->logManager->log($path . " folder created", LogManager::TYPE_COMMENT);
        }

        return $path;
    }

    public function write(string $filePath, string $content): void
    {
        $comment = file_exists($filePath) ? ' updated' : ' created';

        $this->fs->dumpFile($filePath, $content);
        $this->logManager->log($filePath . $comment, LogManager::TYPE_COMMENT);
    }

    private function getPathFromNamespace(string $namespace): string
    {
        $ar = explode('\\', $namespace);
        array_pop($ar);

        return implode('/', $ar);
    }

}
