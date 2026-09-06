<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Migration;

use OCA\Recognize\BackgroundJobs\InstallInsightfaceJob;
use OCA\Recognize\Service\FaceBackend;
use OCA\Recognize\Service\InsightfaceInstaller;
use OCA\Recognize\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\IBinaryFinder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Zero-touch defaults on install: enable face recognition with tiled detection, use the GPU when
 * one is visible, and install InsightFace in the background (the install job switches to it
 * automatically as long as no faces have been detected with the built-in model yet).
 *
 * Only settings the admin never touched are changed.
 */
final class AutoSetup implements IRepairStep {
	public function __construct(
		private SettingsService $settingsService,
		private IJobList $jobList,
		private IBinaryFinder $binaryFinder,
		private InsightfaceInstaller $installer,
		private FaceBackend $backend,
	) {
	}

	public function getName(): string {
		return 'Apply the Recognize fork defaults (faces, GPU, InsightFace)';
	}

	public function run(IOutput $output): void {
		try {
			if (!$this->settingsService->isSet('faces.enabled')) {
				$this->settingsService->setSetting('faces.enabled', 'true');
				$output->info('Face recognition enabled');
			}
			if (!$this->settingsService->isSet('faces.tiling')) {
				$this->settingsService->setSetting('faces.tiling', 'true');
			}
			if (!$this->settingsService->isSet('tensorflow.gpu') && $this->hasNvidiaGpu()) {
				$this->settingsService->setSetting('tensorflow.gpu', 'true');
				$output->info('NVIDIA GPU detected: GPU mode enabled (CUDA 11 + cuDNN 8 are needed for TensorFlow, see the setup checks)');
			}
			if (!$this->settingsService->isSet('faces.autoInstallInsightface')) {
				$this->settingsService->setSetting('faces.autoInstallInsightface', 'true');
			}
			if ($this->settingsService->getSetting('faces.autoInstallInsightface') === 'true'
				&& !$this->backend->isInsightface()
				&& !$this->installer->check(true)['ok']
				&& $this->binaryFinder->findBinaryPath('python3') !== false) {
				$this->jobList->add(InstallInsightfaceJob::class, ['gpu' => $this->settingsService->getSetting('tensorflow.gpu') === 'true']);
				$output->info('InsightFace installation scheduled as a background job; the face backend switches to it automatically when done');
			}
		} catch (\Throwable $e) {
			$output->warning('Recognize auto setup failed: ' . $e->getMessage());
		}
	}

	private function hasNvidiaGpu(): bool {
		return file_exists('/dev/nvidia0') || $this->binaryFinder->findBinaryPath('nvidia-smi') !== false;
	}
}
