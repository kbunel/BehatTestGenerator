<?php

namespace BehatTestGenerator\Manager;

use Symfony\Component\Filesystem\Filesystem;
use BehatTestGenerator\Manager\LogManager;

class FileManager
{
    public const FILE_CREATED = 1;
    public const FILE_UPDATED = 2;

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

    public function getOrCreateTestFolder(string $namespace, bool $verbose = false): string
    {
        $controllerPath = $this->getPathFromNamespace($namespace);
        $path = $this->featureRootPath . DIRECTORY_SEPARATOR . $controllerPath;

        if (!file_exists($path)) {

            mkdir($path, 0777, true);

            if ($verbose) {
                $this->logManager->log($path . " folder created", LogManager::TYPE_COMMENT);
            }
        }

        return $path;
    }

    public function write(string $filePath, string $content, bool $verbose = false): string
    {
        if (file_exists($filePath)) {
            $comment = ' updated';
            $status = self::FILE_UPDATED;
        } else {
            $comment = ' created';
            $status = self::FILE_CREATED;
        }

        $this->fs->dumpFile($filePath, $content);
        if ($verbose) {
            $this->logManager->log($filePath . $comment, LogManager::TYPE_COMMENT);
        }

        return $status;
    }

    private function getPathFromNamespace(string $namespace): string
    {
        $ar = explode('\\', $namespace);
        array_pop($ar);

        return implode('/', $ar);
    }

}
