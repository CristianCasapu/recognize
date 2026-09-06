<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\Db\ClipEmbeddingMapper;
use OCA\Recognize\Service\ClipModel;
use OCA\Recognize\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class ClipSearch implements ISetupCheck {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private SettingsService $settingsService,
		private ClipModel $clipModel,
		private ClipEmbeddingMapper $embeddings,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: natural-language photo search');
	}

	public function run(): SetupResult {
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		if ($this->settingsService->getSetting('clip.enabled') !== 'true') {
			return SetupResult::success($this->l10n->t('Natural-language photo search is disabled. Enable it in the Recognize admin settings (needs the Python environment and a ~1.6 GB model download).'));
		}
		if (!$this->clipModel->isPythonReady()) {
			return SetupResult::error($this->l10n->t('Natural-language search is enabled but the Python environment is missing: run "occ recognize:install-insightface" or use the button in the Recognize admin settings.'), $settingsUrl);
		}
		if (!$this->clipModel->isInstalled()) {
			return SetupResult::error($this->l10n->t('Natural-language search is enabled but the model "%1$s" is not downloaded (%2$s): run "occ recognize:install-clip" or use the button in the Recognize admin settings.', [$this->clipModel->getModelName(), $this->clipModel->getModelDir()]), $settingsUrl);
		}
		if ($this->settingsService->getSetting('clip.status') === 'false') {
			return SetupResult::error($this->l10n->t('The last photo indexing run for natural-language search failed; see "Recent errors" in the Recognize admin settings.'), $settingsUrl);
		}
		try {
			$count = $this->embeddings->countAll();
		} catch (\Throwable $e) {
			$count = 0;
		}
		return SetupResult::success($this->l10n->t('Natural-language photo search is ready (%1$s, %2$s photos indexed).', [$this->clipModel->getModelName(), (string)$count]));
	}
}
