<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\Service\TensorflowCheck;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Verifies that TensorFlow can actually be loaded in the configured mode.
 *
 * The GPU case is the important one: tfjs-node-gpu happily falls back to the CPU
 * when CUDA or cuDNN are missing, so without this check an admin never learns that
 * the GPU is not used at all.
 */
final class Tensorflow implements ISetupCheck {
	public const GPU_DOC = 'https://github.com/nextcloud/recognize/wiki/GPU-mode';

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private TensorflowCheck $tensorflowCheck,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: TensorFlow');
	}

	public function run(): SetupResult {
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		try {
			$result = $this->tensorflowCheck->run();
		} catch (\Throwable $e) {
			return SetupResult::warning($this->l10n->t('Could not test TensorFlow: %s', [$e->getMessage()]), $settingsUrl);
		}

		if ($result['ok']) {
			return SetupResult::success(match ($result['mode']) {
				TensorflowCheck::MODE_GPU => $this->l10n->t('TensorFlow is running on the GPU.'),
				TensorflowCheck::MODE_WASM => $this->l10n->t('TensorFlow WASM mode is working.'),
				default => $this->l10n->t('Native TensorFlow (CPU) is working.'),
			});
		}

		$missing = $result['missingLibraries'];
		$missingText = count($missing) > 0
			? ' ' . $this->l10n->t('Node.js reported these missing shared libraries: %s.', [implode(', ', $missing)])
			: '';

		return match ($result['mode']) {
			TensorflowCheck::MODE_GPU => SetupResult::warning(
				$this->l10n->t('GPU mode is enabled but TensorFlow cannot use the GPU, so all classification silently runs on the CPU.') . $missingText . ' '
				. $this->l10n->t('TensorFlow %1$s needs the CUDA 11 runtime and cuDNN 8: install them on the server (Debian/Ubuntu: "apt install nvidia-cudnn" plus the CUDA 11 runtime packages, or download them from NVIDIA), make sure the libraries are found by the dynamic linker ("ldconfig -p | grep cudnn"), or set the "LD_LIBRARY_PATH for classifiers" option in the Recognize admin settings. Alternatively disable GPU mode. Re-check with "occ setupchecks" after installing.', ['2.9']),
				self::GPU_DOC
			),
			TensorflowCheck::MODE_WASM => SetupResult::error(
				$this->l10n->t('TensorFlow WASM mode cannot be loaded in Node.js, so no classification is possible. Re-install the app dependencies ("occ maintenance:repair" or "Re-install dependencies" in the Recognize admin settings) and check the Node.js binary.') . $missingText,
				$settingsUrl
			),
			default => SetupResult::error(
				$this->l10n->t('Native TensorFlow cannot be loaded in Node.js, so no classification is possible. Either the CPU lacks AVX support, the system uses musl libc, or the libtensorflow download failed. Re-install the dependencies ("occ maintenance:repair" or "Re-install dependencies" in the Recognize admin settings) or enable WASM mode in the settings.') . $missingText,
				$settingsUrl
			),
		};
	}
}
