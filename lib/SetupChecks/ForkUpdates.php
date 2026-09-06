<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\Service\ForkUpdater;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class ForkUpdates implements ISetupCheck {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private ForkUpdater $updater,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize / Memories fork updates');
	}

	public function run(): SetupResult {
		$apps = $this->updater->check();
		$available = [];
		$errors = [];
		foreach ($apps as $app => $info) {
			if ($info['error'] !== null) {
				$errors[] = $app . ': ' . $info['error'];
			} elseif ($info['available']) {
				$available[] = $app . ' ' . $info['installedTag'] . ' → ' . $info['latestTag'];
			}
		}
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		if (count($available) > 0) {
			return SetupResult::info($this->l10n->t('Updates are available from GitHub: %s. Install them from the Recognize admin settings ("Fork updates") or with "occ recognize:self-update <app>".', [implode(', ', $available)]), $settingsUrl);
		}
		if (count($errors) > 0) {
			return SetupResult::info($this->l10n->t('Could not check GitHub for updates: %s', [implode('; ', $errors)]), $settingsUrl);
		}
		return SetupResult::success($this->l10n->t('The forked apps are up to date.'));
	}
}
