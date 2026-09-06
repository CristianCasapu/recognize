<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\Service\ErrorLog;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class RecentErrors implements ISetupCheck {
	public const WINDOW = 24 * 60 * 60;
	public const SHOWN = 3;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateTimeFormatter,
		private ErrorLog $errorLog,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: recent errors');
	}

	public function run(): SetupResult {
		$entries = $this->errorLog->getSince(time() - self::WINDOW);
		if (count($entries) === 0) {
			return SetupResult::success($this->l10n->t('No errors were logged by Recognize in the last 24 hours.'));
		}
		$lines = [];
		foreach (array_slice($entries, 0, self::SHOWN) as $entry) {
			$line = $this->dateTimeFormatter->formatDateTime($entry['time'], 'short', 'short') . ': ' . $entry['message'];
			if ($entry['detail'] !== '') {
				$line .= ' — ' . $entry['detail'];
			}
			if ($entry['count'] > 1) {
				$line .= ' (×' . $entry['count'] . ')';
			}
			$lines[] = $line;
		}
		$total = array_sum(array_map(static fn ($entry) => $entry['count'], $entries));
		$hasErrors = count(array_filter($entries, static fn ($entry) => $entry['level'] !== 'warning')) > 0;
		$description = $this->l10n->n(
			'Recognize logged %n problem in the last 24 hours:',
			'Recognize logged %n problems in the last 24 hours:',
			$total
		) . ' ' . implode(' | ', $lines) . ' — ' . $this->l10n->t('The full list is in the Recognize admin settings under "Recent errors".');
		$link = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		return $hasErrors ? SetupResult::warning($description, $link) : SetupResult::info($description, $link);
	}
}
