<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Classifiers\Images\ClipClassifier;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\QueueService;
use OCA\Recognize\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\IUserMountCache;

final class ClassifyClipJob extends ClassifierJob {
	public const MODEL_NAME = 'clip';

	public function __construct(
		ITimeFactory $time,
		Logger $logger,
		QueueService $queue,
		private SettingsService $settingsService,
		private ClipClassifier $clip,
		IUserMountCache $mountCache,
		IJobList $jobList,
	) {
		parent::__construct($time, $logger, $queue, $mountCache, $jobList, $settingsService);
	}

	/**
	 * @param array{storageId: int, rootId: int} $argument
	 */
	protected function run($argument): void {
		$this->runClassifier(self::MODEL_NAME, $argument);
	}

	/**
	 * @param list<\OCA\Recognize\Db\QueueFile> $files
	 */
	protected function classify(array $files): void {
		$this->clip->classify($files);
	}

	protected function getBatchSize(): int {
		return intval($this->settingsService->getSetting('clip.batchSize'));
	}
}
