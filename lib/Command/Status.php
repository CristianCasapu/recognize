<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\BackgroundJobs\ClassifyClipJob;
use OCA\Recognize\BackgroundJobs\ClassifyFacesJob;
use OCA\Recognize\BackgroundJobs\ClassifyImagenetJob;
use OCA\Recognize\BackgroundJobs\ClassifyLandmarksJob;
use OCA\Recognize\BackgroundJobs\ClassifyMovinetJob;
use OCA\Recognize\BackgroundJobs\ClassifyMusicnnJob;
use OCA\Recognize\BackgroundJobs\ClusterFacesJob;
use OCA\Recognize\BackgroundJobs\ProcessFsActionsJob;
use OCA\Recognize\BackgroundJobs\SchedulerJob;
use OCA\Recognize\BackgroundJobs\StorageCrawlJob;
use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\ErrorLog;
use OCA\Recognize\Service\FaceBackend;
use OCA\Recognize\Service\FaceNameSnapshot;
use OCA\Recognize\Service\QueueService;
use OCA\Recognize\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-screen answer to "is it running, what is it doing, what is left?"
 */
final class Status extends Command {
	public function __construct(
		private SettingsService $settingsService,
		private QueueService $queue,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private IDBConnection $db,
		private FaceDetectionMapper $faceDetections,
		private FaceClusterMapper $faceClusters,
		private FaceBackend $faceBackend,
		private ErrorLog $errorLog,
		private FaceNameSnapshot $nameSnapshot,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:status')
			->setDescription('Show what Recognize is doing: queues, scheduled background jobs, last runs, face statistics and recent errors');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// local time of the server (the log and the Nextcloud UI use it too)
		$tz = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
		try {
			$tz = new \DateTimeZone((string)(\OC::$server->get(\OCP\IConfig::class)->getSystemValue('default_timezone', '') ?: (trim((string)@file_get_contents('/etc/timezone')) ?: $tz->getName())));
		} catch (\Throwable $e) {
		}
		$fmt = static fn (string $ts): string => (int)$ts > 0 ? (new \DateTimeImmutable('@' . (int)$ts))->setTimezone($tz)->format('Y-m-d H:i:s T') : 'never';

		$output->writeln('<info>Background processing</info>');
		$mode = $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		$lastCron = $this->appConfig->getValueInt('core', 'lastcron', 0);
		try {
			$pr = \OCP\Server::get(\OCA\Recognize\Service\FaceProgress::class)->status();
			$output->writeln('  face scan: ' . ($pr['running'] ? '<info>RUNNING</info>' : 'idle') . ' — session: ' . $pr['scanned'] . ' photos scanned' . ($pr['percent'] !== null ? ' (' . $pr['percent'] . ' % of ' . $pr['total'] . ' images)' : '') . ', ' . $pr['ratePerMinute'] . '/min' . ($pr['etaSeconds'] !== null ? ', ~' . (int)ceil($pr['etaSeconds'] / 60) . ' min left' : '') . ($pr['secondsSinceActivity'] !== null ? ', last activity ' . $pr['secondsSinceActivity'] . ' s ago' : ''));
		} catch (\Throwable $e) {
		}
		$output->writeln('  cron mode: ' . $mode . ($mode !== 'cron' ? ' (must be "cron")' : '') . ', last cron run: ' . $fmt((string)$lastCron));
		$output->writeln('  pending file events (new/changed files waiting to be queued): ' . $this->countRows('recognize_fs_creations') . ' created, ' . $this->countRows('recognize_fs_moves') . ' moved, ' . $this->countRows('recognize_fs_deletions') . ' deleted');
		$output->writeln('  scheduled jobs: crawl=' . $this->countJobs(SchedulerJob::class) + $this->countJobs(StorageCrawlJob::class)
			. ' file-events=' . $this->countJobs(ProcessFsActionsJob::class)
			. ' cluster-faces=' . $this->countJobs(ClusterFacesJob::class)
			. ' (reserved = running now: ' . ($this->jobList->hasReservedJob(ClassifyFacesJob::class) || $this->jobList->hasReservedJob(ClusterFacesJob::class) ? 'yes' : 'no') . ')');

		$output->writeln('');
		$output->writeln('<info>Classifiers</info>');
		$table = new Table($output);
		$table->setHeaders(['model', 'enabled', 'queued files', 'jobs scheduled', 'last file classified', 'last run ok?']);
		foreach ([
			'faces' => ClassifyFacesJob::class,
			'imagenet' => ClassifyImagenetJob::class,
			'landmarks' => ClassifyLandmarksJob::class,
			'movinet' => ClassifyMovinetJob::class,
			'musicnn' => ClassifyMusicnnJob::class,
			'clip' => ClassifyClipJob::class,
		] as $model => $jobClass) {
			$status = $this->settingsService->getSetting($model . '.status');
			$table->addRow([
				$model,
				$this->settingsService->getSetting($model . '.enabled') === 'true' ? 'yes' : 'no',
				$this->countQueue($model),
				$this->countJobs($jobClass),
				$fmt($this->settingsService->getSetting($model . '.lastFile')),
				$status === 'true' ? 'yes' : ($status === 'false' ? 'FAILED' : '-'),
			]);
		}
		$table->render();

		$output->writeln('');
		$output->writeln('<info>Faces</info>');
		$output->writeln('  backend: ' . $this->faceBackend->getName() . ', tiling: ' . $this->settingsService->getSetting('faces.tiling') . ', preview: ' . $this->settingsService->getSetting('faces.previewDimension') . 'px, min face size: ' . $this->settingsService->getSetting('faces.minDetectionSize') . ', auto-merge threshold: ' . $this->settingsService->getSetting('faces.autoMergeThreshold'));
		$detections = $this->countRows('recognize_face_detections');
		$unclustered = $this->faceDetections->countUnclustered();
		$rejected = $this->countRows('recognize_face_detections', 'cluster_id = -1');
		$output->writeln('  detected faces: ' . $detections . ' in ' . $this->countDistinctFiles() . ' photos; waiting for clustering: ' . $unclustered . '; not assignable to anyone yet: ' . $rejected);
		$unscored = $this->countRows('recognize_face_detections', 'quality IS NULL');
		$output->writeln('  prominence scores (Memories "best photos first"): ' . ($detections - $unscored) . ' of ' . $detections . ' faces' . ($unscored > 0 ? ' — ' . $unscored . ' still to score (occ recognize:face-quality, or automatically after clustering)' : ''));
		$unweighed = $this->countRows('recognize_face_detections', 'subject IS NULL');
		$threshold = \OCP\Server::get(\OCA\Recognize\Service\FaceQuality::class)->subjectThreshold();
		$subjects = $this->countRows('recognize_face_detections', 'subject >= ' . $threshold);
		$output->writeln(sprintf(
			'  people the picture is about: %d of %d faces score %.2f or more%s',
			$subjects,
			$detections - $unweighed,
			$threshold,
			$unweighed > 0 ? ' — ' . $unweighed . ' faces never weighed (occ recognize:face-subjects)' : '',
		));
		$clusters = $this->countRows('recognize_face_clusters');
		$named = $this->countRows('recognize_face_clusters', "title <> ''");
		$clusterStatus = $this->settingsService->getSetting('clusterFaces.status');
		$output->writeln('  people (clusters): ' . $clusters . ', named: ' . $named . '; last clustering run: ' . $fmt($this->settingsService->getSetting('clusterFaces.lastRun')) . ($clusterStatus === 'false' ? ' (FAILED)' : ''));
		$pending = $this->nameSnapshot->getPendingPath();
		if ($pending !== null) {
			$output->writeln('  pending name snapshot (names restored after clustering): ' . $pending);
		}

		$errors = $this->errorLog->getSince(time() - 24 * 3600);
		$output->writeln('');
		$output->writeln('<info>Errors in the last 24 hours: ' . count($errors) . '</info>');
		foreach (array_slice($errors, 0, 5) as $entry) {
			$output->writeln('  ' . date('Y-m-d H:i', $entry['time']) . ' [' . $entry['level'] . '] ' . $entry['message'] . ($entry['detail'] !== '' ? ' — ' . $entry['detail'] : '') . ($entry['count'] > 1 ? ' (×' . $entry['count'] . ')' : ''));
		}
		return 0;
	}

	private function countQueue(string $model): string {
		try {
			return (string)$this->queue->count($model);
		} catch (\Throwable $e) {
			return '-';
		}
	}

	private function countJobs(string $class): int {
		$n = 0;
		foreach ($this->jobList->getJobsIterator($class, null, 0) as $job) {
			$n++;
		}
		return $n;
	}

	private function countRows(string $table, ?string $where = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		if ($where !== null) {
			$qb->where($qb->createFunction($where));
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetch(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		return $count;
	}

	private function countDistinctFiles(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count($qb->createFunction('DISTINCT file_id')))->from('recognize_face_detections');
		$result = $qb->executeQuery();
		$count = (int)$result->fetch(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		return $count;
	}
}
