<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\BackgroundJobs;

use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\SettingsService;
use OCA\Recognize\Service\SimilarPhotos;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Keeps the duplicate-photo groups warm in the cache so the Memories "Similar photos" page
 * never has to run the pairwise scan inside a web request.
 */
final class SimilarPhotosJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Logger $logger,
		private SimilarPhotos $similar,
		private SettingsService $settingsService,
	) {
		parent::__construct($time);
		$this->setInterval(3 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if ($this->settingsService->getSetting('clip.enabled') !== 'true') {
			return;
		}
		try {
			$groups = $this->similar->groups(true);
			$this->logger->debug('Similar photos job: ' . count($groups) . ' groups');
		} catch (\Throwable $e) {
			$this->logger->warning('Similar photos job failed', ['exception' => $e]);
		}
	}
}
