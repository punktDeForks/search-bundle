<?php

namespace Algolia\SearchBundle\Command;

use Algolia\AlgoliaSearch\Response\IndexingResponse;
use Algolia\SearchBundle\Responses\SearchServiceResponse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'search:clear')]
final class SearchClearCommand extends IndexCommand
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Clear index (remove all data but keep index and settings)')
            ->addOption('indices', 'i', InputOption::VALUE_OPTIONAL, 'Comma-separated list of index names')
            ->addArgument(
                'extra',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Check your engine documentation for available options'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entitiesToClear = $this->getEntitiesFromArgs($input, $output);

        // Build selected indices filter (unprefixed keys) from --indices option
        $indicesOption = (string) $input->getOption('indices');
        $selectedIndexKeys = [];
        if (!empty($indicesOption)) {
            $selectedIndexKeys = array_filter(array_map('trim', explode(',', $indicesOption)));
        }

        $config = $this->searchService->getConfiguration();

        foreach ($entitiesToClear as $entityClassName) {
            // Determine indices mapped to this class
            $classIndexKeys = [];
            foreach ($config['indices'] as $indexKey => $indexDetails) {
                if (($indexDetails['class'] ?? null) === $entityClassName) {
                    $classIndexKeys[] = $indexKey;
                }
            }

            // If indices were specified, intersect them with class indices
            $targetIndexKeys = !empty($selectedIndexKeys)
                ? array_values(array_intersect($classIndexKeys, $selectedIndexKeys))
                : $classIndexKeys;

            // Execute clear scoped to desired indices
            /** @var SearchServiceResponse $response */
            $response = $this->searchService->clear($entityClassName, ['_indices' => $targetIndexKeys]);

            $body = $response->getBody();
            if (empty($body)) {
                $output->writeln('<error>No indices could be cleared for <comment>' . $entityClassName . '</comment></error>');
                continue;
            }

            // Print per targeted index name (prefixed)
            $prefix = (string) ($config['prefix'] ?? '');
            foreach ($targetIndexKeys as $idx) {
                $output->writeln('Cleared <info>' . $prefix . $idx . '</info> index of <comment>' . $entityClassName . '</comment> ');
            }

            // Also report non-indexing responses as errors if present
            foreach ($body as $indexResponse) {
                if (!($indexResponse instanceof IndexingResponse)) {
                    $output->writeln('<error>Some indices couldn\'t be cleared for <comment>' . $entityClassName . '</comment></error>');
                    break;
                }
            }
        }

        $output->writeln('<info>Done!</info>');

        return 0;
    }
}
