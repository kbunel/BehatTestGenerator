<?php

namespace BehatTestGenerator\Manager;

use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor;
use Doctrine\ORM\EntityManagerInterface;
use BehatTestGenerator\Manager\FileManager;
use BehatTestGenerator\Manager\LogManager;
use BehatTestGenerator\Manager\EntityManager as TestGeneratorEntityManager;

class FixturesManager
{
    private $em;
    private $fileManager;
    private $propertyInfo;
    private $fixturesDirPath;
    private $logManager;
    private $entityManager;

    private const TEMPLATE_PATH = __DIR__ . '/../Templates/fixtures.tpl.php';

    public function __construct(string $fixturesDirPath, EntityManagerInterface $em, FileManager $fileManager, LogManager $logManager, TestGeneratorEntityManager $entityManager)
    {
        $this->em = $em;
        $this->fileManager = $fileManager;
        $this->propertyInfo = $this->createPropertyInfoExtractor();
        $this->fixturesDirPath = $fixturesDirPath;
        $this->logManager = $logManager;
        $this->entityManager = $entityManager;
    }

    public function generate(string $namespace, array $servicesUsed): array
    {
        $entitiesRequired = $this->entityManager->getEntitiesRequired($namespace, $servicesUsed);

        $fixtures = $this->getFixturesParameters($entitiesRequired);

        return $this->writeFixtures($fixtures);
    }

    private function writeFixtures(array $fixtures): array
    {
        $fixturesDetails = [];
        foreach ($fixtures as $fixture) {
            $filePath = $this->fixturesDirPath . DIRECTORY_SEPARATOR . $fixture['entityName'] . '.yaml';
            $fixturesDetails[] = [
                'filePath' => $filePath,
                'fixture' => $fixture,
            ];

            if (file_exists($filePath)) {
                continue;
            }

            $content = $this->fileManager->parseTemplate(self::TEMPLATE_PATH, $fixture);
            $this->fileManager->write($filePath, $content);
        }

        return $fixturesDetails;
    }

    private function getFixturesParameters(array $entities): array
    {
        foreach ($entities as $key => $entity) {
            $entities[$key]['requiredFieldsMappings'] = $this->entityManager->getEntityRequiredFieldsMappings($entity);
        }

        return $this->setFixturesInformations($entities);
    }

    private function setFixturesInformations(array $entities, array $fixtures = []): array
    {
        /// here
        foreach ($entities as $entity) {
            $fixtures[$entity['service']] = [
                'service' => $entity['service'],
                'entityName' => $this->getEntityNameFromNamespace($entity['service']),
                'fields' => [],
            ];
            $reflector = new \ReflectionClass($entity['service']);
            $properties = $reflector->getDefaultProperties();

            foreach ($entity['requiredFieldsMappings'] as $key => $field) {
                if (!is_null($properties[$key])) {
                    continue;
                }

                if (isset($field['targetEntity'])) {
                    $types = $this->propertyInfo->getTypes($entity['service'], $key) ?? [];
                    $isNullable = null;
                    $isCollection = null;
                    foreach ($types as $type) {
                        $isNullable = $type->isNullable();
                        $isCollection = $type->isCollection();
                    }

                    if (!$this->propertyInfo->isReadable($entity['service'], $key)
                    || !$this->propertyInfo->isWritable($entity['service'], $key)
                    || $isNullable) {
                        continue;
                    }
                    $fixtures[$entity['service']]['fields'][] = [
                        'fieldName' => $key,
                        'type' => 'relation',
                        'isCollection' => $isCollection,
                        'targetEntity' => $field['targetEntity'],
                        'targetFixtureName' => $this->getEntityNameFromNamespace($field['targetEntity'])
                    ];
                } else {
                    $fixtures[$entity['service']]['fields'][] = [
                        'fieldName' => $key,
                        'type' => $field['type'],
                    ];
                }
            }
        }

        return $this->addFixturesMissingFromRelationTypes($fixtures);
    }

    private function addFixturesMissingFromRelationTypes(array $fixtures): array
    {
        $newEntities = [];
        foreach ($fixtures as $fixture) {
            foreach ($fixture['fields'] as $field) {
                if ($field['type'] == 'relation' && !isset($fixtures[$field['targetEntity']])) {
                    $newEntities[] = [
                        'service' => $field['targetEntity'],
                        'requiredFieldsMappings' => $this->entityManager->getEntityRequiredFieldsMappings([
                            'params' => $field,
                            'service' => $field['targetEntity'],
                        ]),
                    ];
                    $fixtures = array_merge($fixtures, $this->setFixturesInformations($newEntities, $fixtures));
                }
            }
        }

        return $fixtures;;
    }

    private function getFixtures(string $namespace, array $routes): array
    {
        $entities = $this->getEntitiesUsedInRoutes($namespace);
        $params = $this->getParamsFromRoute($routes);
    }

    private function getParamsFromRoute(array $routes): array
    {
        $params = [];

        foreach ($routes as $route) {
            $route = $route['route'];
            preg_match_all('/\{[a-zA-Z0-9]+\}/', $route->getPath(), $params);
        }

        foreach ($entities as $entity) {
            $p[] = preg_replace('/[\{,\}]/', '', $params);
        }

        return $p;
    }

    private function getEntityNameFromNamespace(string $namespace): string
    {
        $ar = explode('\\', $namespace);

        return lcfirst($ar[count($ar) - 1]);
    }

    private function createPropertyInfoExtractor(): PropertyInfoExtractor
    {
        $phpDocExtractor = new PhpDocExtractor();
        $reflectionExtractor = new ReflectionExtractor();
        $doctrineExtractor = new DoctrineExtractor($this->em->getMetadataFactory());

        // array of PropertyListExtractorInterface
        $listExtractors = array($reflectionExtractor);

        // array of PropertyTypeExtractorInterface
        $typeExtractors = array($doctrineExtractor, $phpDocExtractor, $reflectionExtractor);

        // array of PropertyDescriptionExtractorInterface
        $descriptionExtractors = array($phpDocExtractor);

        // array of PropertyAccessExtractorInterface
        $accessExtractors = array($reflectionExtractor);

        return new PropertyInfoExtractor(
            $listExtractors,
            $typeExtractors,
            $descriptionExtractors,
            $accessExtractors
        );
    }
}
