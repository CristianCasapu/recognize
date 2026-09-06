<?php

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Service\AdminNotifier;
use OCA\Recognize\Service\FaceClusterAnalyzer;
use OCA\Recognize\Service\FaceClusterMerger;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

final class ClusterFacesJob extends QueuedJob {
	private FaceClusterAnalyzer $clusterAnalyzer;
	private IJobList $jobList;
	private LoggerInterface $logger;
	private SettingsService $settingsService;
	private FaceClusterMerger $clusterMerger;
	private AdminNotifier $adminNotifier;

	public function __construct(ITimeFactory $time, Logger $logger, IJobList $jobList, FaceClusterAnalyzer $clusterAnalyzer, SettingsService $settingsService, FaceClusterMerger $clusterMerger, AdminNotifier $adminNotifier) {
		parent::__construct($time);
		$this->logger = $logger;
		$this->jobList = $jobList;
		$this->clusterAnalyzer = $clusterAnalyzer;
		$this->settingsService = $settingsService;
		$this->clusterMerger = $clusterMerger;
		$this->adminNotifier = $adminNotifier;
	}

	/**
	 * @param array{storageId: int, rootId: int, userId: string} $argument
	 */
	protected function run($argument): void {
		$userId = (string)$argument['userId'];
		try {
			$iniValue = ini_get('memory_limit');
			if ($iniValue === false || $iniValue === '') {
				$batchSize = 10_000;
			} else {
				$memoryBytes = ini_parse_quantity($iniValue);
				if ($memoryBytes === -1) {
					$batchSize = 10_000;
				} else {
					$batchSize = (int)($memoryBytes * 5_000 / 120_000_0000);
				}
			}
			$this->clusterAnalyzer->calculateClusters($userId, $batchSize);
			// Fold unnamed clusters into the named cluster of the same person (no-op when faces.autoMergeThreshold is 0)
			$this->clusterMerger->merge($userId);
		} catch (\Throwable $e) {
			$this->settingsService->setSetting('clusterFaces.status', 'false');
			$this->logger->error('Failed to calculate face clusters', ['exception' => $e]);
			$this->adminNotifier->notify(AdminNotifier::SUBJECT_CLUSTERING_FAILED, ['message' => $e->getMessage()], 'clustering');
		}
	}
}
