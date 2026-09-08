<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Service\FaceQuality;
use OCA\Recognize\Service\Logger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Scores the prominence of faces that have none yet (detections made before 12.7, or made
 * by a classifier that could not measure the crop), one batch of files per run; re-queues
 * itself until every face is scored. Scheduled by ClusterFacesJob and by the admin "Rescan".
 */
final class FaceQualityJob extends QueuedJob {
	public const FILES_PER_RUN = 150;

	public function __construct(ITimeFactory $time, private FaceQuality $quality, private IJobList $jobList, private Logger $logger) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$result = $this->quality->backfill(self::FILES_PER_RUN);
			$this->logger->debug('Face quality: scored ' . $result['faces'] . ' faces in ' . $result['files'] . ' files, ' . $result['remaining'] . ' faces left');
			if ($result['remaining'] > 0 && $result['files'] > 0) {
				$this->jobList->add(self::class, $argument);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Face quality job failed', ['exception' => $e]);
		}
	}

	/** Make sure one run is queued when faces are still unscored */
	public static function scheduleIfNeeded(IJobList $jobList, FaceQuality $quality): bool {
		if ($quality->countMissing() === 0 || $jobList->has(self::class, null)) {
			return false;
		}
		$jobList->add(self::class, null);
		return true;
	}
}
