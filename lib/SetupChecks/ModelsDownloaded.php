<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class ModelsDownloaded implements ISetupCheck {
	/** Model directories needed per enabled classifier */
	private const MODEL_DIRS = [
		'faces' => ['node_modules/@vladmandic/face-api/model'],
		'imagenet' => ['models/efficientnetv2'],
		'landmarks' => ['models/landmarks_europe'],
		'movinet' => ['models/movinet-a3'],
		'musicnn' => ['models/musicnn'],
	];

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private SettingsService $settingsService,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: machine learning models');
	}

	public function run(): SetupResult {
		$appDir = dirname(__DIR__, 2);
		$missing = [];
		foreach (self::MODEL_DIRS as $model => $dirs) {
			if ($this->settingsService->getSetting($model . '.enabled') !== 'true') {
				continue;
			}
			foreach ($dirs as $dir) {
				if (!is_dir($appDir . '/' . $dir)) {
					$missing[] = $model . ' (' . $dir . ')';
				}
			}
		}
		if (count($missing) > 0) {
			return SetupResult::error(
				$this->l10n->t('The models for the following enabled classifiers are missing: %s. Run "occ recognize:download-models" on the server or click "Re-install dependencies" in the Recognize admin settings.', [implode(', ', $missing)]),
				$this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize'])
			);
		}
		return SetupResult::success($this->l10n->t('All models for the enabled classifiers are available.'));
	}
}
