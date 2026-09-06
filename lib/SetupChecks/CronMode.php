<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class CronMode implements ISetupCheck {
	/** Cron is expected every 5 minutes; complain when it has not run for this long */
	public const MAX_CRON_AGE = 30 * 60;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IAppConfig $appConfig,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: background jobs');
	}

	public function run(): SetupResult {
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'server']);
		if ($mode !== 'cron') {
			return SetupResult::error(
				$this->l10n->t('Background jobs run in "%s" mode. Recognize needs the "Cron" mode (a system cron job calling cron.php every 5 minutes), otherwise classification and face clustering will not run reliably.', [$mode]),
				$settingsUrl
			);
		}
		$lastCron = $this->appConfig->getValueInt('core', 'lastcron', 0);
		if ($lastCron > 0 && time() - $lastCron > self::MAX_CRON_AGE) {
			return SetupResult::warning(
				$this->l10n->t('The system cron job has not run for %s minutes. Check the crontab entry for cron.php: while cron is stalled, Recognize does not classify new files.', [(string)(int)((time() - $lastCron) / 60)]),
				$settingsUrl
			);
		}
		return SetupResult::success($this->l10n->t('Background jobs run via cron.'));
	}
}
