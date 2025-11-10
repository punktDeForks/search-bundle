<?php

namespace Algolia\SearchBundle\MessageHandler;

use Algolia\SearchBundle\Message\IndexEntityMessage;
use Algolia\SearchBundle\SearchService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexEntityMessageHandler
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly ManagerRegistry $registry,
        #[Autowire(service: 'monolog.logger.search_index')]
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(IndexEntityMessage $message): void
    {
        $this->logger->info('Received message', ['class' => $message->className,'ids'=>$message->identifiers,'operation'=>$message->operation]);
        $class   = $message->className;
        $ids     = $message->identifiers;

        $em = $this->registry->getManagerForClass($class);
        if ($em === null) {
            $this->logger->info('Skip Indexing not registered class', ['class' => $class,'ids'=>$ids,'operation'=>$message->operation]);
            return;
        }
        $entity = $em->getRepository($class)->findOneBy($ids);
        if ($entity === null) {
            $this->logger->info('Skip Handling non-existent entity', ['class' => $class,'ids'=>$ids,'operation'=>$message->operation]);
            return;
        }

        if ($message->operation === 'update' || $message->operation === 'persist') {
            $this->logger->info('Indexing entity', ['entity' => $entity,'operation'=>$message->operation]);
            $this->searchService->index($em, $entity);
            return;
        }

        if ($message->operation === 'remove') {
            #$this->logger->info('Removing entity', ['entity' => $entity]);
            #$this->searchService->remove($em, $entity);
            return;
        }
    }
}
