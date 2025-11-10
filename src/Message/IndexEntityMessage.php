<?php

namespace Algolia\SearchBundle\Message;

final class IndexEntityMessage
{
    public function __construct(
        public readonly string $operation, // 'persist' | 'update'
        public readonly string $className,
        /** @var array<string, mixed> */
        public readonly array $identifiers
    ) {
    }
}
