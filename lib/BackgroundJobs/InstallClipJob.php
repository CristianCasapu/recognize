<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Classifiers\Images\ClipClassifier;
use OCA\Recognize\Service\AdminNotifier;
use OCA\Recognize\Service\ClipModel;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Downloads the CLIP model for natural-language search and schedules the embedding crawl.
 */
final class InstallClipJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Logger $logger,
		private ClipModel $clipModel,
		private SettingsService $settingsService,
		private IJobList $jobList,
		private AdminNotifier $adminNotifier,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$lines = ['[' . date('c') . '] download started'];
		$log = function (string $line) use (&$lines): void {
			$lines[] = $line;
			$lines = array_slice($lines, -100);
			$this->settingsService->setRawSetting(ClipModel::INSTALL_LOG, json_encode(['running' => true, 'lines' => $lines]));
		};
		try {
			$this->clipModel->download($log);
			$this->settingsService->setSetting('clip.enabled', 'true');
			$this->jobList->add(SchedulerJob::class, ['models' => [ClipClassifier::MODEL_NAME]]);
			$lines[] = '[' . date('c') . '] done, photo indexing scheduled';
			$this->settingsService->setRawSetting(ClipModel::INSTALL_LOG, json_encode(['running' => false, 'ok' => true, 'lines' => $lines]));
		} catch (\Throwable $e) {
			$lines[] = 'ERROR: ' . $e->getMessage();
			$this->settingsService->setRawSetting(ClipModel::INSTALL_LOG, json_encode(['running' => false, 'ok' => false, 'lines' => $lines]));
			$this->logger->error('CLIP model installation failed', ['exception' => $e]);
			$this->adminNotifier->notify(AdminNotifier::SUBJECT_SETUP_PROBLEM, ['name' => 'CLIP search model', 'message' => $e->getMessage()], 'clip.install', 3600);
		}
	}
}
