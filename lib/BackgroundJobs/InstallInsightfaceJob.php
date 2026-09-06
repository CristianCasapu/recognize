<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\AdminNotifier;
use OCA\Recognize\Service\FaceBackend;
use OCA\Recognize\Service\FaceBackendSwitcher;
use OCA\Recognize\Service\InsightfaceInstaller;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Installs the InsightFace Python environment from the admin settings ("install from the UI").
 * Progress is written to the insightface.installLog app config value which the admin page polls.
 */
final class InstallInsightfaceJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private Logger $logger,
		private InsightfaceInstaller $installer,
		private SettingsService $settingsService,
		private AdminNotifier $adminNotifier,
		private FaceBackend $backend,
		private FaceBackendSwitcher $switcher,
		private FaceDetectionMapper $faceDetections,
		private \OCP\BackgroundJob\IJobList $jobList,
	) {
		parent::__construct($time);
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param array{gpu?: bool} $argument
	 */
	protected function run($argument): void {
		$lines = ['[' . date('c') . '] installation started'];
		$log = function (string $line) use (&$lines): void {
			$lines[] = $line;
			$lines = array_slice($lines, -200);
			$this->settingsService->setRawSetting(InsightfaceInstaller::LOG_SETTING, json_encode(['running' => true, 'lines' => $lines]));
		};
		try {
			$this->installer->install((bool)($argument['gpu'] ?? true), null, $log);
			// Zero-touch: nothing detected with the built-in model yet → use the better backend right away
			if (!$this->backend->isInsightface()
				&& $this->settingsService->getSetting('faces.autoInstallInsightface') === 'true'
				&& $this->faceDetections->countAll() === 0) {
				$this->switcher->switchTo(FaceBackend::INSIGHTFACE);
				$log('No faces detected yet: switched the face backend to InsightFace');
			}
			// Zero-touch: the same environment powers natural-language search
			if (!$this->settingsService->isSet('clip.enabled')) {
				$this->jobList->add(InstallClipJob::class);
				$log('Natural-language search model download scheduled');
			}
			$lines[] = '[' . date('c') . '] done';
			$this->settingsService->setRawSetting(InsightfaceInstaller::LOG_SETTING, json_encode(['running' => false, 'ok' => true, 'lines' => $lines]));
		} catch (\Throwable $e) {
			$lines[] = 'ERROR: ' . $e->getMessage();
			$this->settingsService->setRawSetting(InsightfaceInstaller::LOG_SETTING, json_encode(['running' => false, 'ok' => false, 'lines' => $lines]));
			$this->logger->error('InsightFace installation failed', ['exception' => $e]);
			$this->adminNotifier->notify(AdminNotifier::SUBJECT_SETUP_PROBLEM, ['name' => 'InsightFace', 'message' => $e->getMessage()], 'insightface.install', 3600);
		}
	}
}
