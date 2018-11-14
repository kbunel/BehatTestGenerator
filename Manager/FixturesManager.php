<?php

namespace BehatTestGenerator\Manager;

use Symfony\Component\Asset\Exception\LogicException;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Bridge\Doctrine\PropertyInfo\DoctrineExtractor;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Doctrine\ORM\EntityManager;
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
    private $formFactory;

    private const TEMPLATE_PATH = __DIR__ . '/templates/fixtures.tpl.php';

    public function __construct(string $fixturesDirPath, EntityManager $em, FileManager $fileManager, LogManager $logManager, TestGeneratorEntityManager $entityManager, FormFactoryInterface $formFactory)
    {
        $this->em = $em;
        $this->fileManager = $fileManager;
        $this->propertyInfo = $this->createPropertyInfoExtractor();
        $this->fixturesDirPath = $fixturesDirPath;
        $this->logManager = $logManager;
        $this->entityManager = $entityManager;
        $this->formFactory = $formFactory;
    }

    public function generate(string $namespace, array $servicesUsed): array
    {
        $entitiesRequired = $this->getEntitiesRequired($namespace, $servicesUsed);
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

    private function getEntitiesRequired(string $namespace, array $servicesUsed): array
    {
        $reflector = new \ReflectionClass($namespace);
        $filePath = $reflector->getFileName();

        $entities = $this->getEntitiesUsedInParamsFromFile($filePath, $servicesUsed, $namespace);

        return $this->removeSameEntities($entities);
    }

    private function removeSameEntities(array $entities): array
    {
        $uniqueEntities = [];
        foreach ($entities as $entity) {
            $found = false;
            foreach ($uniqueEntities as $uniqueEntity) {
                if ($uniqueEntity['service'] == $entity['service']) {
                    $found = true;

                    break;
                }
            }
            if (!$found) {
                $uniqueEntities[] = $entity;
            }
        }

        return $uniqueEntities;
    }

    private function getEntitiesUsedInParamsFromFile(string $filePath, array $servicesUsed, string $namespace): array
    {
        $lines = file($filePath);
        $entitiesRequired = [];
        $lookingForEntityParam = false;

        $i = 0;
        foreach ($lines as $line) {
            $i++;
            $line = trim($line);

            if (!$lookingForEntityParam && preg_match('/(^\*\ +\@Get.+\{[a-zA-Z0-9]+})|(^\*\ +\@Post)|(^\*\ +\@Put)|(^\*\ +\@Patch)|(^\*\ +\@Delete)/i', $line)) {
                preg_match('/"[a-zA-Z0-9-_\/{}]*"/', $line, $path);
                preg_match_all('/\{[a-zA-Z0-9-_\.]+\}/', $path[0], $params);
                $lookingForEntityParam = !!count($params[0]);

                continue;
            }

            if ($lookingForEntityParam && preg_match('/^public\ function/', $line)) {
                preg_match_all('/[a-zA-Z0-9\\\\]* \\$[a-zA-Z0-9]+/', $line, $functionServices);
                $entitiesRequired = array_merge($entitiesRequired, $this->getEntityMatchingParams($params[0], $functionServices[0], $servicesUsed, $i));

                $lookingForEntityParam = false;

                continue;
            }

            if (preg_match('/[a-zA-Z0-9]+Type::class/', $line, $supposedFormType)) {
                $supposedFormType = explode('::', $supposedFormType[0])[0];
                $formType = $this->getServiceAssociated($supposedFormType, $servicesUsed);
                if (!$formType) {
                    $formType = preg_replace('/[a-zA-Z0-9]+$/', $supposedFormType, $namespace);
                }

                $class = new \ReflectionClass($formType);
                if ($class->getParentClass() && $class->getParentClass()->getName() == 'Symfony\Component\Form\AbstractType') {
                    $requiredFields = $this->getRequiredFields($formType);
                    foreach ($requiredFields as $requiredField) {
                        if (isset($requiredField['class'])) {
                            $entitiesRequired[] = [
                                'params' => $requiredField['name'],
                                'service' => $requiredField['class'],
                            ];
                        }
                    }
                }
            }
        }

        return $entitiesRequired;
    }

    // @todo refacto, is almost the same than the one used in FeatureManager
    private function getRequiredFields(string $formType): array
    {
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

                $requiredFields[] = array_merge(
                    $this->getInformationsFromFormChild($child),
                    ['options' => $options]
                );
            }
        }

        return $requiredFields;
    }

    private function getInformationsFromFormChild(FormInterface $child): array
    {
        $options = $child->getConfig()->getOptions();

        return [
            'name' => $child->getname(),
            'class' => isset($options['class']) ? $this->em->getClassMetadata($options['class'])->getName() : null,
            'input' => isset($options['input']) ? $options['input'] : null,
            'format' => isset($options['format']) ? $options['format'] : null,
        ];
    }

    // @todo -> in double with the one in FeatureManager
    private function getServiceAssociated(string $service, array $servicesUsed): ?string
    {
        foreach ($servicesUsed as $serviceUsed) {
            if (preg_match('/' . $service . '$/', $serviceUsed)) {
                return $serviceUsed;
            }
        }

        return null;
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

    private function getEntityMatchingParams(array $params, array $functionServices, array $servicesUsed, int $functionLine): array
    {
        $entities = [];
        foreach ($params as $param) {
            $param = preg_replace('/[\{,\}]/', '', $param);

            $parameters = array_values(array_filter($functionServices, function($functionService) use ($param) {
                return preg_match('/\$' . $param . '$/i', $functionService);
            }));

            if (count($parameters) === 0) {
                continue;
            }

            if (count($parameters) > 1) {
                throw new LogicException('Parameters problem with the function line ' . $functionLine . ', Found the same parameter in function twice');
            }

            if (count(explode(' ', $parameters[0])) < 2) {
                throw new LogicException('Parameters problem with the function line ' . $functionLine . ', check the argument type is explicitly given');
            }

            $service = explode(' ', $parameters[0])[0];

            if (in_array($service, $this->getNativeTypes()) || empty($service)) {
                continue;
            }

            if (!preg_match('/^\\\/', $service)) {
                $match = array_values(array_filter($servicesUsed, function($serviceUsed) use ($service){
                    return preg_match('/' . $service . '$/i', $serviceUsed);
                }));

                if (!$match) {
                    throw new LogicException('Parameters problem with the function line ' . $functionLine . ', service not found');
                }
                $service = $match[0];
            }

            $entities[] = [
                'params' => $param,
                'service' => $service,
            ];

            if (count($parameters) > count($entities)) {
                throw new LogicException('Parameters problem with the function line ' . $functionLine . ', Some parameters were not found');
            }
        }

        return $entities;
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

    private function getNativeTypes(): array
    {
        return ['string', 'int', 'array', 'bool', '\DateTime', 'DateTime'];
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
