<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\BackgroundJobs\ClassifyFacesJob;
use OCA\Recognize\BackgroundJobs\ClusterFacesJob;
use OCA\Recognize\BackgroundJobs\SchedulerJob;
use OCA\Recognize\Classifiers\Images\ClusteringFaceClassifier;
use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\BackgroundJob\IJobList;

/**
 * Switch between face backends: snapshot the person names, drop the (incompatible)
 * detections and clusters, store the new backend and schedule a fresh face crawl.
 */
final class FaceBackendSwitcher {
	public function __construct(
		private SettingsService $settingsService,
		private FaceBackend $backend,
		private FaceNameSnapshot $snapshot,
		private FaceClusterMapper $faceClusters,
		private FaceDetectionMapper $faceDetections,
		private QueueService $queue,
		private IJobList $jobList,
		private Logger $logger,
	) {
	}

	/**
	 * @return array{backend:string, snapshot:array{path:string, faces:int, titles:int}|null}
	 * @throws \OCP\DB\Exception
	 */
	public function switchTo(string $backend, bool $scheduleCrawl = true): array {
		if (!isset(FaceBackend::PARAMS[$backend])) {
			throw new \InvalidArgumentException('Unknown face backend ' . $backend . ' (known: ' . implode(', ', array_keys(FaceBackend::PARAMS)) . ')');
		}
		$snapshot = $this->snapshot->create();
		$this->logger->info('Face name snapshot written to ' . $snapshot['path'] . ' (' . $snapshot['faces'] . ' faces, ' . $snapshot['titles'] . ' names)');

		// Remove everything that depends on the old embeddings
		$this->queue->clearQueue(ClusteringFaceClassifier::MODEL_NAME);
		$this->jobList->remove(ClassifyFacesJob::class);
		$this->jobList->remove(ClusterFacesJob::class);
		$this->faceClusters->deleteAll();
		$this->faceDetections->deleteAll();
		\OCP\Server::get(FaceProgress::class)->reset();

		$this->settingsService->setSetting('faces.backend', $backend);
		$this->settingsService->setSetting('faces.status', 'null');
		$this->settingsService->setSetting('clusterFaces.status', 'null');
		// the merge threshold is backend specific; keep "off" if it was off
		if ((float)$this->settingsService->getSetting('faces.autoMergeThreshold') > 0) {
			$this->settingsService->setSetting('faces.autoMergeThreshold', (string)FaceBackend::PARAMS[$backend]['mergeThreshold']);
		}

		if ($scheduleCrawl && $this->settingsService->getSetting('faces.enabled') === 'true') {
			$this->jobList->add(SchedulerJob::class, ['models' => [ClusteringFaceClassifier::MODEL_NAME]]);
		}
		return ['backend' => $backend, 'snapshot' => $snapshot];
	}
}
