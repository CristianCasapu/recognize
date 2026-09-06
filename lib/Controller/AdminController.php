<?php

declare(strict_types=1);

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Controller;

use OCA\Recognize\BackgroundJobs\ClassifyFacesJob;
use OCA\Recognize\BackgroundJobs\ClassifyImagenetJob;
use OCA\Recognize\BackgroundJobs\ClassifyLandmarksJob;
use OCA\Recognize\BackgroundJobs\ClassifyMovinetJob;
use OCA\Recognize\BackgroundJobs\ClassifyMusicnnJob;
use OCA\Recognize\BackgroundJobs\ClusterFacesJob;
use OCA\Recognize\BackgroundJobs\SchedulerJob;
use OCA\Recognize\BackgroundJobs\StorageCrawlJob;
use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Migration\InstallDeps;
use OCA\Recognize\Service\ErrorLog;
use OCA\Recognize\Service\FaceClusterMerger;
use OCA\Recognize\Service\QueueService;
use OCA\Recognize\Service\SettingsService;
use OCA\Recognize\Service\TagManager;
use OCA\Recognize\Service\TensorflowCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\QueryException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IBinaryFinder;
use OCP\IRequest;
use OCP\Migration\IOutput;

final class AdminController extends Controller {
	private TagManager $tagManager;
	private IJobList $jobList;
	private SettingsService $settingsService;
	private QueueService $queue;
	private FaceClusterMapper $clusterMapper;
	private FaceDetectionMapper $detectionMapper;
	private IAppConfig $config;
	private FaceDetectionMapper $faceDetections;
	private IBinaryFinder $binaryFinder;
	private ErrorLog $errorLog;
	private TensorflowCheck $tensorflowCheck;
	private FaceClusterMerger $clusterMerger;
	private InstallDeps $installDeps;

	public function __construct(string $appName, IRequest $request, TagManager $tagManager, IJobList $jobList, SettingsService $settingsService, QueueService $queue, FaceClusterMapper $clusterMapper, FaceDetectionMapper $detectionMapper, IAppConfig $config, FaceDetectionMapper $faceDetections, IBinaryFinder $binaryFinder, ErrorLog $errorLog, TensorflowCheck $tensorflowCheck, FaceClusterMerger $clusterMerger, InstallDeps $installDeps) {
		parent::__construct($appName, $request);
		$this->errorLog = $errorLog;
		$this->tensorflowCheck = $tensorflowCheck;
		$this->clusterMerger = $clusterMerger;
		$this->installDeps = $installDeps;
		$this->tagManager = $tagManager;
		$this->jobList = $jobList;
		$this->settingsService = $settingsService;
		$this->queue = $queue;
		$this->clusterMapper = $clusterMapper;
		$this->detectionMapper = $detectionMapper;
		$this->config = $config;
		$this->faceDetections = $faceDetections;
		$this->binaryFinder = $binaryFinder;
	}

	public function reset(): JSONResponse {
		$this->tagManager->resetClassifications();
		return new JSONResponse([]);
	}

	public function clearAllJobs(): JSONResponse {
		$this->queue->clearQueue('imagenet');
		$this->queue->clearQueue('faces');
		$this->queue->clearQueue('landmarks');
		$this->queue->clearQueue('movinet');
		$this->queue->clearQueue('musicnn');
		$this->jobList->remove(ClassifyFacesJob::class);
		$this->jobList->remove(ClassifyImagenetJob::class);
		$this->jobList->remove(ClassifyLandmarksJob::class);
		$this->jobList->remove(ClassifyMusicnnJob::class);
		$this->jobList->remove(ClassifyMovinetJob::class);
		$this->jobList->remove(ClusterFacesJob::class);
		$this->jobList->remove(SchedulerJob::class);
		$this->jobList->remove(StorageCrawlJob::class);
		return new JSONResponse([]);
	}

	public function recrawl(): JSONResponse {
		$this->clearAllJobs();
		$this->jobList->add(SchedulerJob::class);
		return new JSONResponse([]);
	}

	public function resetFaces(): JSONResponse {
		try {
			$this->clusterMapper->deleteAll();
			$this->detectionMapper->deleteAll();
		} catch (Exception $e) {
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse([]);
	}

	public function count(): JSONResponse {
		$count = count($this->tagManager->findClassifiedFiles());
		return new JSONResponse(['count' => $count]);
	}

	public function countMissed(): JSONResponse {
		$count = count($this->tagManager->findMissedClassifications());
		return new JSONResponse(['count' => $count]);
	}

	public function countQueued(): JSONResponse {
		$imagenetCount = $this->queue->count('imagenet');
		$facesCount = $this->queue->count('faces');
		$landmarksCount = $this->queue->count('landmarks');
		$movinetCount = $this->queue->count('movinet');
		$musicnnCount = $this->queue->count('musicnn');
		$clusterFacesCount = $this->faceDetections->countUnclustered();
		return new JSONResponse([
			'imagenet' => $imagenetCount,
			'faces' => $facesCount,
			'landmarks' => $landmarksCount,
			'movinet' => $movinetCount,
			'musicnn' => $musicnnCount,
			'clusterFaces' => $clusterFacesCount,
		]);
	}

	public function hasJobs(string $task): JSONResponse {
		$tasks = [
			'faces' => ClassifyFacesJob::class,
			'imagenet' => ClassifyImagenetJob::class,
			'landmarks' => ClassifyLandmarksJob::class,
			'musicnn' => ClassifyMusicnnJob::class,
			'movinet' => ClassifyMovinetJob::class,
			'clusterFaces' => ClusterFacesJob::class,
		];
		if (!isset($tasks[$task])) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
		$iterator = $this->jobList->getJobsIterator($tasks[$task], null, 0);
		$lastRun = [];
		foreach ($iterator as $job) {
			$lastRun[] = $job->getLastRun();
		}
		$count = count($lastRun);
		$lastRun = $lastRun? max($lastRun) : 0;
		return new JSONResponse([
			'scheduled' => $count,
			'lastRun' => $lastRun,
		]);
	}

	public function avx(): JSONResponse {
		try {
			$cpuinfo = file_get_contents('/proc/cpuinfo');
		} catch (\Throwable $e) {
			return new JSONResponse(['avx' => null]);
		}
		return new JSONResponse(['avx' => $cpuinfo !== false && strpos($cpuinfo, 'avx') !== false]);
	}

	public function platform(): JSONResponse {
		return new JSONResponse(['platform' => php_uname('m')]);
	}

	public function musl(): JSONResponse {
		try {
			exec('ldd /bin/sh' . ' 2>&1', $output, $returnCode);
		} catch (\Throwable $e) {
			return new JSONResponse(['musl' => null]);
		}

		if ($returnCode !== 0) {
			return new JSONResponse(['musl' => null]);
		}

		$ldd = trim(implode("\n", $output));
		return new JSONResponse(['musl' => strpos($ldd, 'musl') !== false]);
	}

	public function nice(): JSONResponse {
		/* use nice binary from settings if available */
		if ($this->config->getAppValueString('nice_binary', '', lazy: true) !== '') {
			$nice_path = $this->config->getAppValueString('nice_binary', lazy: true);
		} else {
			/* returns the path to the nice binary or false if not found */
			$nice_path = $this->binaryFinder->findBinaryPath('nice');
		}

		if ($nice_path !== false) {
			$this->config->setAppValueString('nice_binary', $nice_path, lazy: true);
		} else {
			$this->config->setAppValueString('nice_binary', '', lazy: true);
			return new JSONResponse(['nice' => false]);
		}

		try {
			exec($nice_path . ' true' . ' 2>&1', $output, $returnCode);
		} catch (\Throwable $e) {
			return new JSONResponse(['nice' => false]);
		}

		if ($returnCode !== 0) {
			return new JSONResponse(['nice' => false]);
		}

		return new JSONResponse(['nice' => $nice_path]);
	}

	public function nodejs(): JSONResponse {
		try {
			exec($this->settingsService->getSetting('node_binary') . ' --version' . ' 2>&1', $output, $returnCode);
		} catch (\Throwable $e) {
			return new JSONResponse(['nodejs' => false]);
		}

		if ($returnCode !== 0) {
			return new JSONResponse(['nodejs' => false]);
		}

		$version = trim(implode("\n", $output));
		return new JSONResponse(['nodejs' => $version]);
	}

	public function ffmpeg(): JSONResponse {
		try {
			exec($this->settingsService->getSetting('ffmpeg_binary') . ' -version' . ' 2>&1', $output, $returnCode);
		} catch (\Throwable $e) {
			return new JSONResponse(['ffmpeg' => false]);
		}

		if ($returnCode !== 0) {
			return new JSONResponse(['ffmpeg' => false]);
		}

		if (!isset($output[0]) || preg_match('/version (.*?) /', trim($output[0]), $matches) !== 1) {
			return new JSONResponse(['ffmpeg' => false]);
		}
		$version = $matches[1];
		return new JSONResponse(['ffmpeg' => $version]);
	}

	public function libtensorflow(): JSONResponse {
		$result = $this->tensorflowCheck->test(TensorflowCheck::MODE_CPU);
		return new JSONResponse(['libtensorflow' => $result['ok'], 'missingLibraries' => $result['missingLibraries']]);
	}

	public function wasmtensorflow(): JSONResponse {
		$result = $this->tensorflowCheck->test(TensorflowCheck::MODE_WASM);
		return new JSONResponse(['wasmtensorflow' => $result['ok']]);
	}

	public function gputensorflow(): JSONResponse {
		$result = $this->tensorflowCheck->test(TensorflowCheck::MODE_GPU);
		return new JSONResponse(['gputensorflow' => $result['ok'], 'missingLibraries' => $result['missingLibraries']]);
	}

	/**
	 * Fresh smoke test of the configured TensorFlow mode, with diagnostics (missing libraries, Node.js output).
	 */
	public function tensorflowStatus(): JSONResponse {
		return new JSONResponse($this->tensorflowCheck->run(true));
	}

	public function errors(): JSONResponse {
		return new JSONResponse(['errors' => $this->errorLog->getRecent()]);
	}

	public function clearErrors(): JSONResponse {
		$this->errorLog->clear();
		return new JSONResponse([]);
	}

	/**
	 * Re-run the dependency installation (Node.js binary, libtensorflow, ffmpeg) from the UI.
	 * Everything that needs root (CUDA, cuDNN) still has to be installed on the server.
	 */
	public function installDeps(): JSONResponse {
		$messages = [];
		$output = new class($messages) implements IOutput {
			/** @param list<string> $messages */
			public function __construct(private array &$messages) {
			}
			public function debug(string $message): void {
			}
			public function info($message): void {
				$this->messages[] = (string)$message;
			}
			public function warning($message): void {
				$this->messages[] = 'Warning: ' . (string)$message;
			}
			public function startProgress($max = 0): void {
			}
			public function advance($step = 1, $description = ''): void {
			}
			public function finishProgress(): void {
			}
		};
		try {
			$this->installDeps->run($output);
		} catch (\Throwable $e) {
			$messages[] = 'Error: ' . $e->getMessage();
			return new JSONResponse(['ok' => false, 'messages' => $messages], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['ok' => true, 'messages' => $messages]);
	}

	/**
	 * Dry run of the automatic cluster merge for every user with face detections.
	 */
	public function mergeSuggestions(): JSONResponse {
		$threshold = $this->clusterMerger->getConfiguredThreshold();
		if ($threshold <= 0) {
			$threshold = FaceClusterMerger::DEFAULT_THRESHOLD;
		}
		try {
			$users = [];
			foreach ($this->faceDetections->findUserIds() as $userId) {
				$users[$userId] = $this->clusterMerger->findCandidates($userId, $threshold);
			}
		} catch (Exception $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['threshold' => $threshold, 'users' => $users]);
	}

	public function autoMerge(): JSONResponse {
		$threshold = $this->clusterMerger->getConfiguredThreshold();
		if ($threshold <= 0) {
			$threshold = FaceClusterMerger::DEFAULT_THRESHOLD;
		}
		try {
			$users = [];
			foreach ($this->faceDetections->findUserIds() as $userId) {
				$users[$userId] = $this->clusterMerger->merge($userId, $threshold);
			}
		} catch (Exception $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['threshold' => $threshold, 'users' => $users]);
	}

	public function cron(): JSONResponse {
		try {
			/** @var IAppConfig $appConfig */
			$appConfig = \OC::$server->getRegisteredAppContainer('core')->get(IAppConfig::class);
			$cron = $appConfig->getAppValueString('backgroundjobs_mode', '');
		} catch (QueryException|AppConfigTypeConflictException $e) {
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['cron' => $cron]);
	}

	/**
	 * @param string $setting
	 * @param scalar $value
	 * @return JSONResponse
	 */
	public function setSetting(string $setting, float|bool|int|string $value): JSONResponse {
		try {
			$this->settingsService->setSetting($setting, (string) $value);
			return new JSONResponse([], Http::STATUS_OK);
		} catch (\OCA\Recognize\Exception\Exception $e) {
			return new JSONResponse([], Http::STATUS_BAD_REQUEST);
		}
	}

	public function getSetting(string $setting): JSONResponse {
		return new JSONResponse(['value' => $this->settingsService->getSetting($setting)]);
	}
}
