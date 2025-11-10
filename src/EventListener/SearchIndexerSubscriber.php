<?php

namespace Algolia\SearchBundle\EventListener;

use Algolia\SearchBundle\Message\IndexEntityMessage;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SearchIndexerSubscriber
{
    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Only these classes will be processed by the mapping.
     *
     * @var array<int, string>
     */
    private array $supportedClasses = [
        \App\Entity\Product\Product::class => [ 'update' ],
        \App\Entity\Product\ProductVariant::class => [ 'persist', 'update' ],
    ];

    public function __construct(MessageBusInterface $bus, LoggerInterface $logger)
    {
        $this->bus    = $bus;
        $this->logger = $logger;
    }

    /**
     * @param PostPersistEventArgs $args
     * @return void
     * @throws ExceptionInterface
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handleEvent($args->getObjectManager(), $args->getObject(), 'persist');
    }

    /**
     * @param PostUpdateEventArgs $args
     * @return void
     * @throws ExceptionInterface
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handleEvent($args->getObjectManager(), $args->getObject(), 'update');
    }

    /**
     * @param PreRemoveEventArgs $args
     * @return void
     * @throws ExceptionInterface
     */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        #$this->handleEvent($args->getObjectManager(), $args->getObject(), 'remove');
    }

    /**
     * @param ObjectManager $objectManager
     * @param object $entity
     * @param 'index'|'remove' $operation
     *
     * @return void
     * @throws ExceptionInterface
     */
    private function handleEvent(ObjectManager $objectManager, object $entity, string $operation): void
    {
        $class = get_class($entity);
        $ids   = $objectManager->getClassMetadata($class)->getIdentifierValues($entity);

        // Resolve to product variants according to mapping
        // Resolve to product variants according to mapping
        $targets = $this->mapToVariantIdentifiers($objectManager, $class, $ids, $operation);

        if (empty($targets)) {
            return;
        }

        // Dispatch one message per resolved product variant
        foreach ($targets as [$targetClass, $targetIds]) {
            $this->bus->dispatch(new IndexEntityMessage($operation, $targetClass, $targetIds));
        }

        // Log dispatch summary
        $this->logger->info('Dispatched index messages for product variants.', [
            'operation' => $operation,
            'count' => count($targets),
            'targets' => array_map(static function (array $t) {
                return ['class' => $t[0], 'identifierValues' => $t[1]];
            }, $targets),
        ]);
    }

    /**
     * Map a received entity class/identifiers into a list of product variant identifiers to index.
     *
     * Rules:
     * - If the entity is a Product: find all variants for that product.
     * - If the entity is a ProductVariant: find its parent product, then all variants of that product.
     * - Only process classes declared in $supportedClasses.
     *
     * @param ObjectManager $om
     * @param string $class
     * @param array<string, mixed> $identifierValues
     * @param string $operation
     *
     * @return array<int, array{0: class-string, 1: array<string, mixed>}>
     */
    private function mapToVariantIdentifiers(ObjectManager $om, string $class, array $identifierValues, string $operation): array
    {
        if (!isset($this->supportedClasses[$class]) || !in_array($operation, $this->supportedClasses[$class], true)) {
            return [];
        }

        $targets = [];

        // Resolve by class
        if ($class === \App\Entity\Product\Product::class) {
            // Load the product
            $product = $om->getRepository(\App\Entity\Product\Product::class)->findOneBy($identifierValues);
            if ($product === null) {
                $this->logger->warning('Product not found while mapping.', ['identifierValues' => $identifierValues]);
                return [];
            }

            // Get all variants of the product
            foreach ($product->getVariants() as $variant) {
                $variantIds = $om->getClassMetadata(\App\Entity\Product\ProductVariant::class)->getIdentifierValues($variant);
                if (!empty($variantIds)) {
                    $targets[] = [\App\Entity\Product\ProductVariant::class, $variantIds];
                }
            }

            return $targets;
        }

        if ($class === \App\Entity\Product\ProductVariant::class) {
            // Load the variant
            $variant = $om->getRepository(\App\Entity\Product\ProductVariant::class)->findOneBy($identifierValues);
            if ($variant === null) {
                $this->logger->warning('ProductVariant not found while mapping.', ['identifierValues' => $identifierValues]);
                return [];
            } else {
                $targets[] = [\App\Entity\Product\ProductVariant::class, $om->getClassMetadata(\App\Entity\Product\ProductVariant::class)->getIdentifierValues($variant)];
            }
            return $targets;
        }

        // Fallback (shouldn’t be reached because of supportedClasses gate)
        return [];
    }
}
