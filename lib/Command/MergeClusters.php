<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\FaceClusterMerger;
use OCA\Recognize\Service\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MergeClusters extends Command {
	public function __construct(
		private Logger $logger,
		private FaceDetectionMapper $detectionMapper,
		private FaceClusterMerger $merger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:merge-clusters')
			->setDescription('Merge unnamed face clusters into the named cluster of the same person (by centroid distance)')
			->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Maximum centroid distance for a merge (default: the faces.autoMergeThreshold setting, or ' . FaceClusterMerger::DEFAULT_THRESHOLD . ' if that is 0)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the candidates, do not merge anything')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only process this user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->logger->setCliOutput($output);

		$threshold = $input->getOption('threshold') !== null
			? (float)$input->getOption('threshold')
			: $this->merger->getConfiguredThreshold();
		if ($threshold <= 0) {
			$threshold = $this->merger->getDefaultThreshold();
		}

		try {
			$userIds = $input->getOption('user') !== null ? [(string)$input->getOption('user')] : $this->detectionMapper->findUserIds();
		} catch (\OCP\DB\Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return 1;
		}

		foreach ($userIds as $userId) {
			$output->writeln('<info>User ' . $userId . ' (threshold ' . $threshold . ', ambiguity margin ' . FaceClusterMerger::AMBIGUITY_MARGIN . ')</info>');
			try {
				$candidates = array_merge($this->merger->findCandidates($userId, $threshold), $this->merger->findUnnamedPairs($userId, $threshold));
			} catch (\OCP\DB\Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
				return 1;
			}
			if (count($candidates) === 0) {
				$output->writeln('No unnamed clusters with a named counterpart found');
				continue;
			}
			$table = new Table($output);
			$table->setHeaders(['Unnamed cluster', 'Faces', 'Nearest cluster', 'Distance', '2nd nearest', 'Distance', 'Photos together', 'Merge?']);
			foreach ($candidates as $candidate) {
				$table->addRow([
					'#' . $candidate['clusterId'],
					$candidate['size'],
					$candidate['targetTitle'] . ' (#' . $candidate['targetId'] . ')',
					$candidate['distance'],
					$candidate['secondTitle'] ?? '-',
					$candidate['secondDistance'] ?? '-',
					$candidate['sharedFiles'],
					$candidate['mergeable'] ? 'yes' : 'no',
				]);
			}
			$table->render();

			if ($input->getOption('dry-run')) {
				continue;
			}
			try {
				$merged = $this->merger->merge($userId, $threshold);
			} catch (\OCP\DB\Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
				return 1;
			}
			$output->writeln('Merged ' . count($merged) . ' cluster(s)');
		}
		return 0;
	}
}
