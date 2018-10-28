<?php

namespace App\Services\TestGenerator;

use App\Services\TestGenerator\FileManager;
use Symfony\Component\Routing\Route;
use Symfony\Component\Form\FormFactoryInterface;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class FeatureManager
{
    public const FILE_CREATED = 1;
    public const FILE_UPDATED = 2;

    private const AUTHENT_EMAIL = 'first-super-admin@staffmatch.com';
    private const BACKGROUND_TPL = __DIR__ . '/templates/features/background.tpl.php';
    private const SCENARIO_TPL = __DIR__ . '/templates/features/scenario.tpl.php';
    private const FIXTURES_IMPORT_TPL = __DIR__ . '/templates/features/imports.tpl.php';

    private $fileManager;
    private $formFactory;
    private $em;

    public function __construct(FileManager $fileManager, FormFactoryInterface $formFactory, EntityManager $em)
    {
        $this->fileManager = $fileManager;
        $this->formFactory = $formFactory;
        $this->em = $em;
    }

    public function generate(string $testFolder, string $namespace, array $fixturesDetails, array $routes, ?array $methods = null, ?string $tag = null, array $servicesUsed): ?string
    {
        $fileName = $this->getFileNameFromNamespace($namespace);
        $filePath = $testFolder . DIRECTORY_SEPARATOR . $fileName;
        $fixturesFilesNames = $this->getFixturesFilesNamesFromPaths($fixturesDetails);
        $routes = $this->getRequiredRouteInformations($routes, $servicesUsed);
        $parameters = [
            'namespace' => $namespace,
            'fixturesFilesNames' => $fixturesFilesNames,
            'authenticationEmail' => self::AUTHENT_EMAIL,
            'routes' => $routes,
            'tag' => $tag,
            'methods' => $methods,
        ];

        $content = $this->getContent($filePath, $parameters);

        if (!$content) {
            return null;
        }

        $this->fileManager->write($filePath, $content);

        return file_exists($filePath) ? self::FILE_UPDATED : self::FILE_CREATED;
    }

    private function getContent(string $filePath, array $parameters): ?string
    {
        if (file_exists($filePath)) {
            $content = $this->checkFixturesImports(file_get_contents($filePath), $parameters);
        } else {
            $content = $this->fileManager->parseTemplate(self::BACKGROUND_TPL, $parameters);
        }

        $scenarios = $this->getScenariosContent($filePath, $parameters);

        if (empty($scenarios)) {
            return null;
        }

        return $content . $scenarios;
    }

    private function checkFixturesImports(string $content, array $parameters): string
    {
        $fixturesLoaded = $this->getFixturesLoaded($content);
        $fixturesNotLoaded = $this->getFixturesNotLoaded($fixturesLoaded, $parameters);

        if (count($fixturesNotLoaded)) {
             $content = $this->addFixturesNotLoadedInContent($content, $fixturesNotLoaded);
        }

        return $content;
    }

    private function getFixturesNotLoaded(array $fixturesLoaded, array $parameters): array
    {
        $fixturesNotLoaded = [];
        foreach ($parameters['fixturesFilesNames'] as $fixtureFileName) {
            if (!in_array($fixtureFileName, $fixturesLoaded)) {
                $fixturesNotLoaded[] = $fixtureFileName;
            }
        }

        return $fixturesNotLoaded;
    }

    private function addFixturesNotLoadedInContent(string $content, array $fixturesNotLoaded): string
    {
        $lines = explode("\n", $content);
        $i = -1;
        $backgroundDone = false;
        $importsDone = false;
        foreach ($lines as $line) {
            $i++;
            if (!$backgroundDone && preg_match('/And the following fixtures files are loaded/', $line)) {
                $backgroundDone = true;
                $background = [
                    'endIndex' => $i,
                    'content' => array_slice($lines, 0, $i + 1),
                ];

                continue;
            }

            if (!$backgroundDone) {
                continue;
            }

            if (!preg_match('/^\|/', trim($line))) {
                $imports = [
                    'endIndex' => $i,
                    'content' => array_slice($lines, $background['endIndex'] + 1, $i - $background['endIndex'] - 1),
                ];
                $eof = [
                    'endIndex' => $i,
                    'content' => array_slice($lines, $i, count($lines) - $i),
                ];

                break;
            }
        }

        foreach ($fixturesNotLoaded as $fixture) {
            $imports['content'][] = str_replace("\n", '', $this->fileManager->parseTemplate(self::FIXTURES_IMPORT_TPL, [
                'fixturesFilesName' => $fixture
            ]));
        }

        return implode("\n", $background['content']) . "\n" . implode("\n", $imports['content']) . "\n" . implode("\n", $eof['content']);
    }

    // private function getFixturesToLoad(array $parameters): array
    // {
    //     $filesToLoad = [];
    //     foreach ($parameters['routes'] as $route) {
    //         if (isset($route['requiredFields'])) {

    //             foreach ($route['requiredFields'] as $requiredField) {
    //                 if ($requiredField['class']) {
    //                     preg_match('/[a-zA-Z0-9]+$/', $requiredField['class'], $entityName);
    //                     $filesToLoad[] = strtolower($entityName[0]);
    //                 }
    //             }
    //         }
    //     }

    //     return $filesToLoad;
    // }

    private function getFixturesLoaded(string $content): array
    {
        $lines = explode("\n", $content);
        $importsPart = false;
        $imports = [];
        foreach ($lines as $line) {
            $line = trim($line);

            if (!$importsPart && preg_match('/And the following fixtures files are loaded/', $line)) {
                $importsPart = true;
                continue;
            } elseif (!$importsPart) {
                continue;
            }

            if (!preg_match('/^\|/', $line)) {
                break;
            }

            $imports[] = str_replace(['|', ' ',], '', $line);
        }

        return $imports;
    }

    private function getScenariosContent(string $filePath, array $parameters): string
    {
        if (file_exists($filePath)) {
            $scenariosAlreadyWritten = $this->getAlreadyWrittenScenarios($filePath);
            foreach ($parameters['routes'] as $key => $route) {
                if (in_array($route['functionName'], $scenariosAlreadyWritten)) {
                    unset($parameters['routes'][$key]);
                }
            }
        }

        return $this->fileManager->parseTemplate(self::SCENARIO_TPL, $parameters);
    }

    private function getAlreadyWrittenScenarios(string $filePath): array
    {
        $scenarios = [];
        $lines = file($filePath, FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Scenario/', $line)) {
                $scenarios[] = str_replace('Scenario: ', '', $line);
            }
        }

        return $scenarios;
    }

    private function getFixturesFilesNamesFromPaths(array $fixturesDetails): array
    {
        $fixturesFilesNames = [];
        foreach ($fixturesDetails as $fixturesDetail) {
            $fixturesFilesNames[] =  preg_replace('/.+Fixtures\\//', '', $fixturesDetail['filePath']);
        }

        return $fixturesFilesNames;
    }

    private function getRequiredRouteInformations(array $routes, array $servicesUsed): array
    {
        $r = [];
        foreach ($routes as $route) {
            $route = $route['route'];
            for ($i = 0; $i < count($route->getMethods()); $i++) {
                $infos = [
                    'functionName' => explode('::', $route->getDefaults()['_controller'])[1],
                    'method' => $route->getMethods()[$i],
                    'path' => $this->getPath($route),
                    'codeResponse' => $this->getCodeResponseFromMethod($route->getMethods()[0]),
                ];

                if (strtolower($infos['method']) == 'put') {
                    $infos = array_merge($infos, ['requiredFields' => $this->getRequiredFields($route, $servicesUsed)]);
                }

                $r[] = $infos;
            }
        }

        return $r;
    }

    // @todo refacto, is almost the same than the one used in FixturesManager
    private function getRequiredFields(Route $route, array $servicesUsed): array
    {
        if (!$formType = $this->getFormTypeUsedInRoute($route, $servicesUsed)) {
            return [];
        }

        try {
            $form = $this->formFactory->create($formType);
        } catch (ContextErrorException $e) {
            return [];
        } catch (MissingOptionsException $e) {
            return [];
        }

        $requiredFields = [];
        foreach ($form->all() as $child) {
            if ($child->isRequired()) {
                $options = [];
                foreach ($child->getConfig()->getOptions()['constraints'] as $constraint) {
                    if (isset($constraint->choices)) {
                        $options = $constraint->choices;
                    }
                }
                $options = $child->getConfig()->getOptions();

                $value = $this->getValue($options);

                // $value = null;
                // if (isset($options['class'])) {
                //     $entityClassName =  $this->em->getClassMetadata($options['class'])->getName();
                //     preg_match('/[a-zA-Z0-9]+$/', $entityClassName, $value);
                //     $value = strtolower($value[0]);
                // }

                // dump($options);
                // die;
                $requiredFields[] = [
                    'name' => $child->getname(),
                    'class' => isset($options['class']) ? $this->em->getClassMetadata($options['class'])->getName() : null,
                    'value' => $value,
                ];

                // return [
                //     'name' => $child->getname(),
                //     'class' => isset($options['class']) ? $this->em->getClassMetadata($options['class'])->getName() : null,
                //     'input' => isset($options['input']) ? $options['input'] : null,
                //     'format' => isset($options['format']) ? $options['format'] : null,
                // ];
            }
        }

        return $requiredFields;
    }

    private function getValue(array $options)
    {
        switch (true) {
            case isset($options['class']):
                return 1;
            case isset($options['choices']) && count($options) > 0:
                return '"' . array_values($options['choices'])[0] . '"';
            case isset($options['input']):
                switch($options['input']) {
                    case 'datetime':
                        $format = isset($options['format']) ? $this->convertDateFormat($options['format']) : 'Y-m-d';
                        return '"' . (new \DateTime('now'))->format($format) . '"';
                    default:
                        dump($options['input']);
                        die;
                }
            default:
                return '""';
        }
    }

    private function convertDateFormat(string $format): string
    {
        return preg_replace(['/yyyy/', '/MM/', '/dd/', '/HH/', '/mm/'], ['Y', 'm', 'd', 'H', 'i'], $format);
    }

    private function getFormTypeUsedInRoute(Route $route, array $servicesUsed): ?string
    {
        $_controller = explode('::', $route->getDefaults()['_controller']);
        $namespace = $_controller[0];
        $method = $_controller[1];
        $func = new \ReflectionMethod($namespace, $method);
        $filename = $func->getFileName();
        $start_line = $func->getStartLine();
        $end_line = $func->getEndLine();
        $length = $end_line - $start_line;
        $source = file($filename);
        $lines = array_slice($source, $start_line, $length);
        foreach ($lines as $line) {
            $line = trim($line);
            preg_match('/[a-zA-Z0-9]+Type::class/', $line, $supposedFormType);
            if (isset($supposedFormType[0])) {
                $supposedFormType = explode('::', $supposedFormType[0])[0];
                $formType = $this->getServiceAssociated($supposedFormType, $servicesUsed);
                if (!$formType) {
                    $formType = preg_replace('/[a-zA-Z0-9]+$/', $supposedFormType, $namespace);
                }

                $class = new \ReflectionClass($formType);
                if ($class->getParentClass() && $class->getParentClass()->getName() == 'Symfony\Component\Form\AbstractType') {
                    return $formType;
                }
            }
        }

        return null;
    }

    // @todo -> in double with the one in FixturesManager
    private function getServiceAssociated(string $service, array $servicesUsed): ?string
    {
        foreach ($servicesUsed as $serviceUsed) {
            if (preg_match('/' . $service . '$/', $serviceUsed)) {
                return $serviceUsed;
            }
        }

        return null;
    }

    private function getCodeResponseFromMethod(string $method): int
    {
        switch ($method) {
            case 'POST':
                return 201;
            case 'PUT':
                return 204;
            case 'PATCH':
                return 201;
            case 'DELETE':
                return '204';
            default:
                return 200;
        }
    }

    private function getFileNameFromNamespace(string $namespace): string
    {
        $ar = explode('\\', $namespace);

        return $ar[count($ar) - 1] . '.feature';
    }

    private function getPath(Route $route): string
    {
        $_controller = explode('::', $route->getDefaults()['_controller']);
        $func = new \ReflectionMethod($_controller[0], $_controller[1]);
        $filename = $func->getFileName();
        $fLine = $func->getStartLine() - 1;
        $lines = file($filename);
        $functionLine = $lines[$fLine];
        $paramConverted = $this->getParamConverted($fLine, $lines);
        preg_match_all('/[a-zA-Z0-9\\\\]* \\$[a-zA-Z0-9]+/', $functionLine, $functionServices);
        $params = [];
        foreach ($functionServices[0] as $service) {
            $ar = explode(' ', str_replace('$', '', $service));

            if (count($ar) == 1) {
                $params[] = [
                    'params' => $ar[0],
                    'services' => 'string',
                ];
            } else {
                $params[] = [
                    'params' => $ar[1],
                    'service' => $ar[0],
                ];
            }
        }

        $path = $route->getPath();
        foreach ($route->getRequirements() as $key => $value) {
            if (preg_match('/\|/', $value)) {
                $path = str_replace('{' . $key . '}', explode('|', $value)[0], $path);
            }
        }

        foreach ($params as $param) {
            if (in_array($param['service'], ['\DateTime', 'DateTime'])) {
                $format = isset($paramConverted[$param['params']]) ? $paramConverted[$param['params']]['format'] : 'Y-m-d';
                $path = preg_replace('/{' . $param['params'] . '}/', (new \DateTime('now'))->format($format), $path);
            }
        }

        return preg_replace('/\{[a-zA-Z0-9-_\.]+\}/', '1', $path);
    }
    private function getParamConverted(int $fLine, array $lines): array
    {
        $p = [];
        for ($x = $fLine; (trim($lines[$x]) != '' || $x == 0); $x--) {
            $line = trim($lines[$x]);
            if (preg_match('/\@ParamConverter/', $line)) {
                $paramConverterLine = $line;
                preg_match('/\(\"[a-zA-Z0-9-_]+\"/', $paramConverterLine, $paramConverted);
                $paramsConverted = preg_replace('/[\(,"]/', '', $paramConverted);
                preg_match('/options=\{[a-zA-Z0-9\"\!\ \-\_\!\:]+\}/', $paramConverterLine, $options);
                if (count($options) == 0) {
                    continue;
                }
                $p[$paramsConverted[0]] = preg_replace('/(options={)|}|\"|\ /', '', $options[0]);
                $options = explode(',', $p[$paramsConverted[0]]);
                $p[$paramsConverted[0]] = [];

                foreach ($options as $option) {
                    $opt = explode(':', $option);
                    $p[$paramsConverted[0]] = array_merge($p[$paramsConverted[0]], [$opt[0] => str_replace('!', '', $opt[1])]);
                }
            }
        }

        return $p;
    }
}
