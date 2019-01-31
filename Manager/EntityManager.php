<?php

namespace BehatTestGenerator\Manager;

use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\Asset\Exception\LogicException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\AbstractType;
use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManagerInterface;


class EntityManager
{
    private const IGNORED_PROPERTIES = ['id', 'uuid', 'updatedAt', 'createdAt', 'deletedAt'];
    private const NATIVE_TYPES = ['string', 'int', 'array', 'bool', '\DateTime', 'DateTime'];

    private $formFactory;
    private $em;

    public function __construct(DoctrineEntityManagerInterface $em, FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
        $this->em = $em;
    }

    public function getEntityRequiredFieldsMappings(array $entity): array
    {
        $meta = $this->em->getClassMetadata($entity['service']);
        $filedsSetInContructor = $this->getFieldsSetInConstructor($entity, $meta->fieldMappings);
        $requiredFields = $this->getRequiredFieldsMappings($meta->fieldMappings, $filedsSetInContructor);
        $requiredRelations = $this->getAssociatedObjects($meta->associationMappings, $filedsSetInContructor);
        $fields = array_merge($requiredFields, $requiredRelations);

        $this->checkProperiesAccessors($entity['service'], $fields);

        return $fields;
    }

    public function getEntitiesRequired(string $namespace, array $servicesUsed): array
    {
        $reflector = new \ReflectionClass($namespace);
        $filePath = $reflector->getFileName();

        $entities = $this->getEntitiesUsedInParamsFromFile($filePath, $servicesUsed, $namespace);

        return $this->removeSameEntities($entities);
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
                if ($class->getParentClass() && $class->getParentClass()->getName() == AbstractType::class) {
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

            if (in_array($service, self::NATIVE_TYPES) || empty($service)) {
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

    private function getFieldsSetInConstructor(array $entity, array $fieldMappings): array
    {
        $fieldsSetInContructor = $this->getAttributesSetInConstructor($entity['service']);

        // Create the function below to avoid parsing n times the same file
        $fieldSetInDeclaredModels = [];
        foreach ($fieldMappings as $key => $infos) {
            if (isset($infos['declared']) && !in_array($infos['declared'], $fieldSetInDeclaredModels)) {
                $fieldSetInDeclaredModels[$infos['declared']] = $this->getAttributesSetInConstructor($infos['declared']);
            }
        }

        foreach ($fieldSetInDeclaredModels as $fieldSetInDeclaredModel) {
            foreach ($fieldSetInDeclaredModel as $attribute) {
                $fieldsSetInContructor[] = $attribute;
            }
        }

        return $fieldsSetInContructor;
    }

    private function getAttributesSetInConstructor(string $namespace): array
    {
        try {
            $func = new \ReflectionMethod($namespace, '__construct');
        } catch (\ReflectionException $e) {
            // __constructor in class doesn't exists
            return [];
        }

        $filename = $func->getFileName();
        $start_line = $func->getStartLine();
        $end_line = $func->getEndLine();
        $length = $end_line - $start_line;
        $source = file($filename);
        $lines = array_slice($source, $start_line, $length);

        $attributesSet = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\\$this->.+=/', $line)) {
                $attribute = trim(explode('->', explode('=', $line)[0])[1]);
                $attributesSet[] = $attribute;
            }
        }

        return $attributesSet;
    }

    private function getRequiredFieldsMappings(array $fields, array $filedsSetInContructor): array
    {
        $requiredFields = [];
        foreach ($fields as $key => $infos) {
            if (in_array($key, self::IGNORED_PROPERTIES)) {
                continue;
            }

            if ((!isset($infos['nullable']) || !$infos['nullable']) && !in_array($key, $filedsSetInContructor)) {
                $requiredFields[$key] = $fields[$key];
            }
        }

        return $requiredFields;
    }

    private function getAssociatedObjects(array $associationMappings, array $filedsSetInContructor): array
    {
        return array_filter($associationMappings, function($associationMappings) use ($filedsSetInContructor) {
            return !in_array($associationMappings['fieldName'], $filedsSetInContructor);
        });
    }

    // Is in double with the propertyInfo used in setFixturesInformations, but kept for now due to some
    // unexpected behavior from my side with the propertyInfo (->getTypes notoriously)
    private function checkProperiesAccessors(string $entityNamespace, array &$fields): void
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        $entity = new $entityNamespace();
        foreach ($fields as $key => $value) {
            if (!$propertyAccessor->isReadable($entity, $value['fieldName'])
            || !$propertyAccessor->isWritable($entity, $value['fieldName'])) {
                unset($fields[$key]);
            }
        }
    }
}
