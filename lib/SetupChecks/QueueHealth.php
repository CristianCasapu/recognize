<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\SetupChecks;

use OCA\Recognize\BackgroundJobs\ClassifyFacesJob;
use OCA\Recognize\BackgroundJobs\ClassifyImagenetJob;
use OCA\Recognize\BackgroundJobs\ClassifyLandmarksJob;
use OCA\Recognize\BackgroundJobs\ClassifyMovinetJob;
use OCA\Recognize\BackgroundJobs\ClassifyMusicnnJob;
use OCA\Recognize\BackgroundJobs\ClusterFacesJob;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\QueueService;
use OCA\Recognize\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Detects the two silent failure modes of the classifier pipeline:
 * a queue with files but no job scheduled to process them, and a classifier
 * whose last run failed.
 */
final class QueueHealth implements ISetupCheck {
	private const JOBS = [
		'faces' => ClassifyFacesJob::class,
		'imagenet' => ClassifyImagenetJob::class,
		'landmarks' => ClassifyLandmarksJob::class,
		'movinet' => ClassifyMovinetJob::class,
		'musicnn' => ClassifyMusicnnJob::class,
	];

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private SettingsService $settingsService,
		private QueueService $queue,
		private IJobList $jobList,
		private FaceDetectionMapper $faceDetections,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return $this->l10n->t('Recognize: classification queues');
	}

	public function run(): SetupResult {
		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'recognize']);
		$errors = [];
		$warnings = [];
		foreach (self::JOBS as $model => $jobClass) {
			if ($this->settingsService->getSetting($model . '.enabled') !== 'true') {
				continue;
			}
			if ($this->settingsService->getSetting($model . '.status') === 'false') {
				$errors[] = $this->l10n->t('the last "%s" classification run failed', [$model]);
			}
			try {
				$queued = $this->queue->count($model);
			} catch (\Throwable $e) {
				$errors[] = $this->l10n->t('the "%1$s" queue cannot be read (%2$s)', [$model, $e->getMessage()]);
				continue;
			}
			if ($queued > 0 && !$this->jobList->has($jobClass, null) && !$this->hasAnyJob($jobClass)) {
				$warnings[] = $this->l10n->t('%1$s files are waiting in the "%2$s" queue but no background job is scheduled for them', [(string)$queued, $model]);
			}
		}
		if ($this->settingsService->getSetting('faces.enabled') === 'true') {
			if ($this->settingsService->getSetting('clusterFaces.status') === 'false') {
				$errors[] = $this->l10n->t('the last face clustering run failed');
			}
			try {
				$unclustered = $this->faceDetections->countUnclustered();
				if ($unclustered > 0 && !$this->hasAnyJob(ClusterFacesJob::class)) {
					$warnings[] = $this->l10n->t('%s detected faces are waiting to be clustered but no clustering job is scheduled', [(string)$unclustered]);
				}
			} catch (\Throwable $e) {
				$errors[] = $this->l10n->t('face detections cannot be read (%s)', [$e->getMessage()]);
			}
		}

		$hint = ' ' . $this->l10n->t('See "Recent errors" in the Recognize admin settings for the cause. To re-schedule processing of all files run "occ recognize:recrawl" (or click "Rescan all files" in the settings); to run it in the foreground use "occ recognize:classify" and "occ recognize:cluster-faces".');
		if (count($errors) > 0) {
			return SetupResult::error(ucfirst(implode('; ', array_merge($errors, $warnings))) . '.' . $hint, $settingsUrl);
		}
		if (count($warnings) > 0) {
			return SetupResult::warning(ucfirst(implode('; ', $warnings)) . '.' . $hint, $settingsUrl);
		}
		return SetupResult::success($this->l10n->t('Classification queues are healthy.'));
	}

	private function hasAnyJob(string $jobClass): bool {
		foreach ($this->jobList->getJobsIterator($jobClass, 1, 0) as $job) {
			return true;
		}
		return false;
	}
}
