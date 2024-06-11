<?php

namespace Korbeil\DoctrineAutomapperHydrator;

use AutoMapper\AutoMapper;
use AutoMapper\AutoMapperInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use ReflectionClass;
use Symfony\Component\VarExporter\LazyGhostTrait;

class AutoMapperHydrator extends AbstractHydrator
{
    use ResultTrait;

    private AutoMapperInterface $autoMapper;
    private array $idTemplate = [];
    private int $resultCounter = 0;
    private EntityManagerInterface $entityManager;
    private LazyGhostGenerator $lazyGhostGenerator;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
        $this->entityManager = $entityManager;
        $autoMapperDir = sprintf('%s/../../automapper', $entityManager->getConfiguration()->getProxyDir());
        $this->autoMapper = AutoMapper::create(cacheDirectory: $autoMapperDir);
        $this->lazyGhostGenerator = new LazyGhostGenerator(sprintf('%s/../../automapper-lazy-ghosts', $entityManager->getConfiguration()->getProxyDir()));
    }

    protected function prepare(): void
    {
        if (!array_key_exists(UnitOfWork::HINT_DEFEREAGERLOAD, $this->hints)) {
            $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] = true;
        }

        foreach ($this->resultSetMapping()->aliasMap as $dqlAlias => $className) {
            $this->idTemplate[$dqlAlias] = '';
            $this->autoMapper->getMapper('array', $className);
        }
    }

    protected function hydrateAllData(): array
    {
        $result = [];

        while ($row = $this->statement()->fetchAssociative()) {
            $this->hydrateRowData($row, $result);
        }

        return $result;
    }

    protected function hydrateRowData(array $row, array &$result): void
    {
        $idTemplate = $this->idTemplate;
        $nonemptyComponents = [];
        $rowData = $this->gatherRowData($row, $idTemplate, $nonemptyComponents);

        foreach ($rowData['data'] as $dqlAlias => $data) {
            $element = $this->getEntity($dqlAlias, $data);

            if (array_key_exists($dqlAlias, $this->resultSetMapping()->indexByMap)) {
                $resultKey = $row[$this->resultSetMapping()->indexByMap[$dqlAlias]];

                $result[$resultKey] = $element;
            } else {
                $resultKey = $this->resultCounter;
                ++$this->resultCounter;

                $result[$resultKey] = $element;
            }
        }
    }

    private function getEntity(string $dqlAlias, array $data): object
    {
        $className = $this->resultSetMapping()->aliasMap[$dqlAlias];
        $classMetadata = $this->getClassMetadata($className);
        $element = $this->autoMapper->map($data, $className);

        if (\count($classMetadata->associationMappings) > 0) {
            $elementRefl = new \ReflectionClass($element);

            foreach ($classMetadata->associationMappings as $field => $assoc) {
                $lazyGhostClass = $this->lazyGhostGenerator->generateAndLoad($assoc->targetEntity);

                $targetIdentifierField = $targetIdentifierValue = null;
                if ($assoc instanceof ToOneOwningSideMapping) {
                    foreach ($assoc->sourceToTargetKeyColumns as $sourceColumn => $targetColumn) {
                        if (array_key_exists($sourceColumn, $data)) {
                            $targetIdentifierField = $targetColumn;
                            $targetIdentifierValue = $data[$sourceColumn];
                            break;
                        }
                    }

                    if (null !== $targetIdentifierField && null !== $targetIdentifierValue) {
                        $classInstance = $lazyGhostClass::createLazyGhost(function (object $instance) use ($assoc, $targetIdentifierField, $targetIdentifierValue) {
                            $fromDatabase = $this->recoverEntityFromDatabase($assoc->targetEntity, $targetIdentifierField, $targetIdentifierValue);

                            $fromDatabaseValues = [];
                            $fromDatabaseRefl = new ReflectionClass($fromDatabase);
                            foreach ($fromDatabaseRefl->getProperties() as $property) {
                                if ($property->isInitialized($fromDatabase)) {
                                    $fromDatabaseValues[$property->getName()] = $property->getValue($fromDatabase);
                                }
                            }

                            $instanceRefl = new ReflectionClass($instance);
                            foreach ($instanceRefl->getProperties() as $property) {
                                if ('lazyObjectState' === $property->getName()) {
                                    continue;
                                }

                                $property->setValue($instance, $fromDatabaseValues[$property->getName()]);
                            }
                        });

                        $elementRefl->getProperty($field)->setValue($element, $classInstance);
                    }
                }
            }
        }


//        @fixme register entity into Doctrine UOW
//        $this->registerManaged($this->metadataCache[$className], $element, $data);

        return $element;
    }

    /**
     * @param class-string $className
     */
    private function recoverEntityFromDatabase(string $className, string $identifierField, mixed $identifierValue): mixed
    {
        $query = $this
            ->entityManager
            ->createQueryBuilder()
            ->select('o')
            ->from($className, 'o')
            ->andWhere(sprintf('o.%s = :identifierValue', $identifierField))
            ->setParameter('identifierValue', $identifierValue)
            ->getQuery();

        return static::getOneOrNullResult($query);
    }

    protected function cleanup(): void
    {
        parent::cleanup();

        $this->uow->hydrationComplete();
    }
}