<?php

/*
 * Copyright (c) 2024 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\Exception;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\TensorflowCheck;

final class MaintenanceJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private Logger $logger,
		private IJobList $jobList,
		private FaceDetectionMapper $faceDetectionMapper,
		private TensorflowCheck $tensorflowCheck,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60 * 12);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param array{storageId: int, rootId: int} $argument
	 */
	protected function run($argument): void {
		// Keep a fresh TensorFlow/GPU check result from the cron (CLI) context for the setup checks
		try {
			$this->tensorflowCheck->run(true);
		} catch (\Throwable $e) {
			$this->logger->warning('TensorFlow check failed', ['exception' => $e]);
		}

		// Trigger clustering in case it's stuck
		try {
			$users = $this->faceDetectionMapper->getUsersForUnclustered();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return;
		}
		foreach ($users as $userId) {
			$this->jobList->add(ClusterFacesJob::class, ['userId' => $userId]);
		}
	}
}
