<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Keeps the most recent errors and warnings of this app in the app config,
 * so that admins can see them in the Nextcloud UI (admin settings and setup checks)
 * without having to dig through nextcloud.log.
 */
final class ErrorLog {
	public const KEY = 'errors.recent';
	public const MAX_ENTRIES = 30;
	public const MAX_MESSAGE_LENGTH = 500;
	/** Identical consecutive messages within this window are folded into one entry with a counter */
	public const DEDUPE_WINDOW = 300;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function record(string $level, string $message, array $context = []): void {
		try {
			$exception = $context['exception'] ?? null;
			$detail = $exception instanceof \Throwable
				? mb_substr(get_class($exception) . ': ' . $exception->getMessage(), 0, self::MAX_MESSAGE_LENGTH)
				: '';
			$message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
			$now = time();

			$entries = $this->getRecent();
			if (count($entries) > 0
				&& $entries[0]['message'] === $message
				&& $entries[0]['detail'] === $detail
				&& $now - $entries[0]['time'] < self::DEDUPE_WINDOW) {
				$entries[0]['count']++;
				$entries[0]['time'] = $now;
			} else {
				array_unshift($entries, [
					'time' => $now,
					'level' => $level,
					'message' => $message,
					'detail' => $detail,
					'count' => 1,
					'cli' => PHP_SAPI === 'cli',
				]);
				$entries = array_slice($entries, 0, self::MAX_ENTRIES);
			}
			$this->config->setAppValueString(self::KEY, json_encode($entries, JSON_THROW_ON_ERROR), lazy: true);
		} catch (\Throwable $e) {
			// Recording an error must never break the operation that failed in the first place
		}
	}

	/**
	 * @return list<array{time:int, level:string, message:string, detail:string, count:int, cli:bool}>
	 */
	public function getRecent(): array {
		try {
			$entries = json_decode($this->config->getAppValueString(self::KEY, '[]', lazy: true), true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			return [];
		}
		if (!is_array($entries)) {
			return [];
		}
		return array_values(array_filter($entries, static fn ($entry) => is_array($entry) && isset($entry['time'], $entry['message'])));
	}

	/**
	 * @return list<array{time:int, level:string, message:string, detail:string, count:int, cli:bool}>
	 */
	public function getSince(int $timestamp): array {
		return array_values(array_filter($this->getRecent(), static fn ($entry) => $entry['time'] >= $timestamp));
	}

	public function clear(): void {
		$this->config->setAppValueString(self::KEY, '[]', lazy: true);
	}
}
