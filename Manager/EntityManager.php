<?php

namespace BehatTestGenerator\Manager;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManagerInterface;

class EntityManager
{
    private const IGNORED_PROPERTIES = ['id', 'uuid', 'updatedAt', 'createdAt', 'deletedAt'];

    private $em;

    public function __construct(DoctrineEntityManagerInterface $em)
    {
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