<?php

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\FaceClusterAnalyzer;
use OCA\Recognize\Service\FaceClusterMerger;
use OCA\Recognize\Service\FaceNameSnapshot;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\SettingsService;
use OCP\DB\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ClusterFaces extends Command {
	private Logger $logger;

	private FaceDetectionMapper $detectionMapper;

	private FaceClusterAnalyzer $clusterAnalyzer;
	private SettingsService $settingsService;
	private FaceClusterMerger $clusterMerger;
	private FaceNameSnapshot $nameSnapshot;

	public function __construct(Logger $logger, FaceDetectionMapper $detectionMapper, FaceClusterAnalyzer $clusterAnalyzer, SettingsService $settingsService, FaceClusterMerger $clusterMerger, FaceNameSnapshot $nameSnapshot) {
		$this->nameSnapshot = $nameSnapshot;
		parent::__construct();
		$this->logger = $logger;
		$this->detectionMapper = $detectionMapper;
		$this->clusterAnalyzer = $clusterAnalyzer;
		$this->settingsService = $settingsService;
		$this->clusterMerger = $clusterMerger;
	}

	/**
	 * Configure the command
	 *
	 * @return void
	 */
	protected function configure() {
		$this->setName('recognize:cluster-faces')
			->setDescription('Cluster detected faces per user (Memory usage will grow with O(n²): n=2000: 450MB, n=4000: 700MB, n=5000: 1200MB)')
			->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'The number of face detections to cluster in one go. 0 for no limit.', 10_000);
	}

	/**
	 * Execute the command
	 *
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->logger->setCliOutput($output);

		try {
			$userIds = $this->detectionMapper->findUserIds();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return 1;
		}

		foreach ($userIds as $userId) {
			$this->logger->info('Clustering face detections for user ' . $userId);
			try {
				$this->clusterAnalyzer->calculateClusters($userId, (int)$input->getOption('batch-size'));
				$merged = $this->clusterMerger->merge($userId);
				if (count($merged) > 0) {
					$this->logger->info('Auto-merged ' . count($merged) . ' unnamed cluster(s) into named clusters for user ' . $userId);
				}
				$restored = $this->nameSnapshot->restorePending();
				if (count($restored) > 0) {
					$this->logger->info('Restored ' . count($restored) . ' person name(s) from the pending snapshot: ' . implode(', ', array_keys($restored)));
				}
			} catch (\JsonException|Exception $e) {
				$this->settingsService->setSetting('clusterFaces.status', 'false');
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
		}
		return 0;
	}
}
