<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\AppInfo\Application;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Sends Nextcloud notifications to all members of the admin group when
 * something in Recognize breaks (failed classifier runs, failed clustering, ...).
 *
 * Notifications are throttled per key so that a job that fails every 5 minutes
 * does not flood the admins.
 */
final class AdminNotifier {
	public const SUBJECT_CLASSIFIER_FAILED = 'classifier_failed';
	public const SUBJECT_CLUSTERING_FAILED = 'clustering_failed';
	public const SUBJECT_SETUP_PROBLEM = 'setup_problem';

	public const DEFAULT_THROTTLE = 24 * 60 * 60;
	public const MAX_PARAM_LENGTH = 300;

	public function __construct(
		private IManager $notificationManager,
		private IGroupManager $groupManager,
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $subject One of the SUBJECT_* constants
	 * @param array<string,string> $parameters
	 * @param string $throttleKey Notifications with the same key are sent at most once per $throttleSeconds
	 */
	public function notify(string $subject, array $parameters, string $throttleKey, int $throttleSeconds = self::DEFAULT_THROTTLE): void {
		try {
			if ($this->settingsService->getSetting('notifications.enabled') !== 'true') {
				return;
			}
			$throttleSetting = 'notify.' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $throttleKey);
			$now = time();
			$last = (int)$this->settingsService->getRawSetting($throttleSetting, '0');
			if ($now - $last < $throttleSeconds) {
				return;
			}

			$parameters = array_map(static fn (string $value): string => mb_substr($value, 0, self::MAX_PARAM_LENGTH), $parameters);
			$objectId = substr(md5($throttleKey), 0, 32);

			$admins = $this->groupManager->get('admin')?->getUsers() ?? [];
			foreach ($admins as $admin) {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($admin->getUID())
					->setObject('recognize', $objectId);
				// Replace a previous notification about the same problem instead of stacking them
				$this->notificationManager->markProcessed($notification);
				$notification->setDateTime(new \DateTime())
					->setSubject($subject, $parameters);
				$this->notificationManager->notify($notification);
			}
			$this->settingsService->setRawSetting($throttleSetting, (string)$now);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not send admin notification', ['exception' => $e]);
		}
	}
}
