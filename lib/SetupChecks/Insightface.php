<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\Service\FaceBackend;
use OCA\Recognize\Service\InsightfaceInstaller;
use OCA\Recognize\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class Insightface implements ISetupCheck {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private SettingsService $settingsService,
		private FaceBackend $backend,
		private InsightfaceInstaller $installer,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: InsightFace face backend');
	}

	public function run(): SetupResult {
		if (!$this->backend->isInsightface()) {
			return SetupResult::success($this->l10n->t('The built-in face-api backend is used. InsightFace (RetinaFace + ArcFace) tells similar people apart much better; it can be enabled in the Recognize admin settings.'));
		}
		if ($this->settingsService->getSetting('faces.enabled') !== 'true') {
			return SetupResult::success($this->l10n->t('Face recognition is disabled.'));
		}
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		$check = $this->installer->check();
		$install = ' ' . $this->l10n->t('Install it with "occ recognize:install-insightface" (add --cpu without an NVIDIA GPU) or with the "Install InsightFace" button in the Recognize admin settings; it needs python3, python3-venv and a C compiler on the server and downloads about 500 MB.');
		if (!is_executable($check['python'])) {
			return SetupResult::error($this->l10n->t('The InsightFace face backend is selected but its Python environment is missing (%s).', [$check['python']]) . $install, $settingsUrl);
		}
		if (count($check['providers']) === 0) {
			return SetupResult::error($this->l10n->t('The Python environment "%1$s" cannot load insightface/onnxruntime: %2$s', [$check['python'], mb_substr($check['output'], -300)]) . $install, $settingsUrl);
		}
		if (!$check['modelsPresent']) {
			return SetupResult::error($this->l10n->t('The InsightFace model pack "%1$s" is missing in %2$s/models.', [$check['model'], $check['root']]) . $install, $settingsUrl);
		}
		if ($this->settingsService->getSetting('tensorflow.gpu') === 'true' && !$check['gpu']) {
			return SetupResult::warning($this->l10n->t('InsightFace is installed but ONNX Runtime has no CUDA provider, so face detection runs on the CPU. Install onnxruntime-gpu 1.18 (CUDA 11 / cuDNN 8) into the environment: "%s -m pip install onnxruntime-gpu==1.18.1".', [$check['python']]), $settingsUrl);
		}
		return SetupResult::success($this->l10n->t('InsightFace %1$s with ONNX Runtime %2$s is installed (%3$s).', [
			$check['versions']['insightface'] ?? '?',
			$check['versions']['onnxruntime'] ?? '?',
			$check['gpu'] ? 'GPU' : 'CPU',
		]));
	}
}
