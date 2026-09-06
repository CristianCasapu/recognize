<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Service\AdminNotifier;
use OCA\Recognize\Service\ForkUpdater;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Updates a forked app from its GitHub release (triggered from the admin settings).
 */
final class SelfUpdateJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Logger $logger,
		private ForkUpdater $updater,
		private SettingsService $settingsService,
		private AdminNotifier $adminNotifier,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param array{app: string} $argument
	 */
	protected function run($argument): void {
		$app = (string)($argument['app'] ?? '');
		$lines = ['[' . date('c') . '] updating ' . $app];
		$log = function (string $line) use (&$lines, $app): void {
			$lines[] = $line;
			$lines = array_slice($lines, -100);
			$this->settingsService->setRawSetting(ForkUpdater::LOG_SETTING, json_encode(['running' => true, 'app' => $app, 'lines' => $lines]));
		};
		try {
			$this->updater->update($app, $log);
			$lines[] = '[' . date('c') . '] done';
			$this->settingsService->setRawSetting(ForkUpdater::LOG_SETTING, json_encode(['running' => false, 'ok' => true, 'lines' => $lines]));
		} catch (\Throwable $e) {
			$lines[] = 'ERROR: ' . $e->getMessage();
			$this->settingsService->setRawSetting(ForkUpdater::LOG_SETTING, json_encode(['running' => false, 'ok' => false, 'lines' => $lines]));
			$this->logger->error('Update of ' . $app . ' failed', ['exception' => $e]);
			$this->adminNotifier->notify(AdminNotifier::SUBJECT_SETUP_PROBLEM, ['name' => $app . ' update', 'message' => $e->getMessage()], 'update.' . $app, 3600);
		}
	}
}
