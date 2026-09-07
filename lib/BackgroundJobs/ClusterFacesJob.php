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
use OCA\Recognize\Service\FaceTracker;
use OCA\Recognize\Service\FaceNameSnapshot;
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
	private FaceNameSnapshot $nameSnapshot;

	public function __construct(ITimeFactory $time, Logger $logger, IJobList $jobList, FaceClusterAnalyzer $clusterAnalyzer, SettingsService $settingsService, FaceClusterMerger $clusterMerger, AdminNotifier $adminNotifier, FaceNameSnapshot $nameSnapshot) {
		$this->nameSnapshot = $nameSnapshot;
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
			// HDBSCAN is O(n²) in the embedding dimension: 512-d InsightFace vectors get a 4× smaller batch
			$batchSize = $this->clusterAnalyzer->scaleBatchSize($batchSize);
			$this->clusterAnalyzer->calculateClusters($userId, $batchSize);
			// Fold unnamed clusters into the named cluster of the same person (no-op when faces.autoMergeThreshold is 0)
			$this->clusterMerger->merge($userId);
			// Bursts: hand known people to the unassigned (often small) faces of neighbouring frames
			\OCP\Server::get(FaceTracker::class)->track($userId);
			// After a backend switch: hand the saved person names to the matching new clusters
			$this->nameSnapshot->restorePending();
			// Chain: one run handles one batch — re-queue ourselves until the backlog is gone
			$remaining = \OCP\Server::get(\OCA\Recognize\Db\FaceDetectionMapper::class)->countUnclusteredForUser($userId);
			if ($remaining > 0) {
				$this->logger->debug('Clustering: ' . $remaining . ' faces still waiting for ' . $userId . ', scheduling the next batch');
				$this->jobList->add(self::class, $argument);
			}
		} catch (\Throwable $e) {
			$this->settingsService->setSetting('clusterFaces.status', 'false');
			$this->logger->error('Failed to calculate face clusters', ['exception' => $e]);
			$this->adminNotifier->notify(AdminNotifier::SUBJECT_CLUSTERING_FAILED, ['message' => $e->getMessage()], 'clustering');
		}
	}
}
