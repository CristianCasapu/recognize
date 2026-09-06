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
use Symfony\Component\Process\Process;

final class NodeBinary implements ISetupCheck {
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
		return $this->l10n->t('Recognize: Node.js');
	}

	public function run(): SetupResult {
		$node = $this->settingsService->getSetting('node_binary');
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		if ($node === '') {
			return SetupResult::error(
				$this->l10n->t('No Node.js binary is configured, so Recognize cannot run any classifier. Nextcloud tried to download one when the app was installed; run "occ maintenance:repair" (or use "Re-install dependencies" in the Recognize admin settings) to try again, or set the path to a Node.js binary in the settings.'),
				$settingsUrl
			);
		}
		try {
			$proc = new Process([$node, '--version']);
			$proc->setTimeout(30);
			$proc->run();
			$version = trim($proc->getOutput());
			if ($proc->getExitCode() !== 0 || $version === '') {
				return SetupResult::error(
					$this->l10n->t('The Node.js binary "%1$s" cannot be executed (%2$s). Recognize cannot classify anything until this is fixed: make sure the file is executable, or set a different binary in the Recognize admin settings.', [$node, trim($proc->getErrorOutput()) ?: 'exit code ' . $proc->getExitCode()]),
					$settingsUrl
				);
			}
		} catch (\Throwable $e) {
			return SetupResult::error(
				$this->l10n->t('The Node.js binary "%1$s" cannot be executed: %2$s', [$node, $e->getMessage()]),
				$settingsUrl
			);
		}
		return SetupResult::success($this->l10n->t('Node.js %s is working.', [$version]));
	}
}
