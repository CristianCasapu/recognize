<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Notification;

use OCA\Recognize\AppInfo\Application;
use OCA\Recognize\Service\AdminNotifier;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

final class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Recognize');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$message = (string)($params['message'] ?? '');

		switch ($notification->getSubject()) {
			case AdminNotifier::SUBJECT_CLASSIFIER_FAILED:
				$model = (string)($params['model'] ?? '');
				$subject = $l->t('Recognize: the "%s" classification job failed', [$model]);
				break;
			case AdminNotifier::SUBJECT_CLUSTERING_FAILED:
				$subject = $l->t('Recognize: face clustering failed');
				break;
			case AdminNotifier::SUBJECT_SETUP_PROBLEM:
				$name = (string)($params['name'] ?? '');
				$subject = $l->t('Recognize: setup problem (%s)', [$name]);
				break;
			default:
				throw new UnknownNotificationException();
		}

		$link = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		$notification->setParsedSubject($subject)
			->setParsedMessage($message !== '' ? $message : $l->t('Open the Recognize admin settings for details and instructions.'))
			->setLink($link)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')));

		return $notification;
	}
}
