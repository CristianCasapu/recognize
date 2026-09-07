<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use OCP\Util;

/**
 * Recognize writes downscaled previews to the temporary directory and inserts
 * thousands of rows into the database while crawling. A full temp or database
 * disk makes the queue inserts fail ("table is full") and files silently never
 * get classified, so warn well before that happens.
 */
final class DiskSpace implements ISetupCheck {
	public const WARNING_BYTES = 2 * 1024 * 1024 * 1024;
	public const ERROR_BYTES = 512 * 1024 * 1024;

	public function __construct(
		private IL10N $l10n,
		private IConfig $config,
		private ITempManager $tempManager,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: free disk space');
	}

	public function run(): SetupResult {
		if (!function_exists('disk_free_space')) {
			return SetupResult::info($this->l10n->t('The PHP function "disk_free_space" is disabled, so free disk space cannot be checked.'));
		}
		$paths = [
			$this->l10n->t('Nextcloud temporary directory') => $this->safeTempDir(),
			$this->l10n->t('PHP temporary directory') => sys_get_temp_dir(),
			$this->l10n->t('data directory') => (string)$this->config->getSystemValue('datadirectory', ''),
			$this->l10n->t('Recognize app directory') => dirname(__DIR__, 2),
		];
		$seen = [];
		$errors = [];
		$warnings = [];
		foreach ($paths as $label => $path) {
			if ($path === '' || !is_dir($path)) {
				continue;
			}
			$free = @disk_free_space($path);
			$total = @disk_total_space($path);
			if ($free === false || $total === false) {
				continue;
			}
			// Several paths usually live on the same filesystem; report each filesystem once
			$key = $total . ':' . (int)($free / (1024 * 1024));
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$text = $label . ' (' . $path . '): ' . Util::humanFileSize((int)$free) . ' ' . $this->l10n->t('free') . ' (' . (int)round(100 * $free / max(1, $total)) . ' %)';
			// Small dedicated filesystems (a 2 GB /tmp volume) are fine while they are mostly empty:
			// warn on absolute free space only when the filesystem is also more than half full.
			$mostlyFull = $free / max(1, $total) < 0.5;
			if ($free < self::ERROR_BYTES) {
				$errors[] = $text;
			} elseif ($free < self::WARNING_BYTES && $mostlyFull) {
				$warnings[] = $text;
			}
		}
		$hint = ' ' . $this->l10n->t('When these run full, preview generation and queue inserts fail and files are never classified. Free up space, or move the temporary directory with the "tempdirectory" option in config.php and the database temp directory ("tmpdir" for MariaDB/MySQL) to a bigger filesystem.');
		if (count($errors) > 0) {
			return SetupResult::error($this->l10n->t('Disk space is critically low: %s.', [implode('; ', array_merge($errors, $warnings))]) . $hint);
		}
		if (count($warnings) > 0) {
			return SetupResult::warning($this->l10n->t('Disk space is getting low: %s.', [implode('; ', $warnings)]) . $hint);
		}
		return SetupResult::success($this->l10n->t('Enough free disk space for temporary files and the database.'));
	}

	private function safeTempDir(): string {
		try {
			return (string)$this->tempManager->getTempBaseDir();
		} catch (\Throwable $e) {
			return '';
		}
	}
}
