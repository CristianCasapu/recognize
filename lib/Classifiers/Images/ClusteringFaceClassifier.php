<?php

/*
 * Copyright (c) 2021-2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Classifiers\Images;

use OCA\Recognize\BackgroundJobs\ClusterFacesJob;
use OCA\Recognize\Classifiers\Classifier;
use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\FaceBackend;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\QueueService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use OCP\ITempManager;
use OCP\Share\IManager;
use Override;

final class ClusteringFaceClassifier extends Classifier {
	public const IMAGE_TIMEOUT = 120; // seconds
	public const IMAGE_PUREJS_TIMEOUT = 360; // seconds
	public const MIN_FACE_RECOGNITION_SCORE = 0.9;

	public const MAX_FACE_YAW = 50;
	public const MAX_FACE_ROLL = 30;

	public const MODEL_NAME = 'faces';
	public const PREVIEW_DIMENSIONS = [1024, 2048, 4096];
	/** In additive mode a new detection overlapping an existing one by more than this IoU is considered the same face */
	public const ADDITIVE_DUPLICATE_IOU = 0.5;

	private bool $additive = false;
	private ?FaceBackend $backend = null;

	private function getBackend(): FaceBackend {
		return $this->backend ??= \OCP\Server::get(FaceBackend::class);
	}

	/**
	 * @return list<string>
	 */
	#[Override]
	protected function getClassifierCommand(string $model): array {
		if ($this->getBackend()->isInsightface()) {
			return [
				$this->getBackend()->getPythonBinary(),
				dirname(__DIR__, 3) . '/src/classifier_faces_insightface.py',
				'-'
			];
		}
		return parent::getClassifierCommand($model);
	}

	public function __construct(
		Logger $logger,
		IAppConfig $config,
		private FaceDetectionMapper $faceDetections,
		QueueService $queue, IRootFolder $rootFolder,
		private IUserMountCache $userMountCache, private IJobList $jobList, ITempManager $tempManager, IPreview $previewProvider,
		private IManager $shareManager) {
		parent::__construct($logger, $config, $rootFolder, $queue, $tempManager, $previewProvider);
	}

	/**
	 * @param Node $node
	 * @return list<string>
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	private function getUsersWithFileAccess(Node $node): array {
		$mountInfos = $this->userMountCache->getMountsForFileId($node->getId());
		$userIds = array_map(static function (ICachedMountInfo $mountInfo) {
			return $mountInfo->getUser()->getUID();
		}, $mountInfos);

		return array_values(array_unique($userIds));
	}

	/**
	 * The face detector works on a 512px copy of the image, but landmarks and descriptors
	 * are computed on the preview handed to it, so a larger preview yields better descriptors
	 * for small faces (faces.previewDimension setting).
	 */
	#[Override]
	protected function getTempFileDimension(): int {
		$dimension = (int)$this->config->getAppValueString('faces.previewDimension', '1024', lazy: true);
		return in_array($dimension, self::PREVIEW_DIMENSIONS, true) ? $dimension : self::TEMP_FILE_DIMENSION;
	}

	/**
	 * @return array<string,string>
	 */
	#[Override]
	protected function getExtraEnvironment(): array {
		$env = [];
		if ($this->config->getAppValueString('faces.tiling', 'false', lazy: true) === 'true') {
			$env['RECOGNIZE_FACES_TILING'] = 'true';
		}
		if ($this->getBackend()->isInsightface()) {
			$env = array_merge($env, $this->getBackend()->getInsightfaceEnvironment());
		}
		return $env;
	}

	/**
	 * Additive mode re-scans files that already have face detections and only adds the
	 * faces that were not detected before (e.g. small faces found with tiling enabled),
	 * so existing detections, clusters and their names are preserved.
	 */
	public function setAdditive(bool $additive): void {
		$this->additive = $additive;
	}

	/**
	 * @param array{x: float, y: float, width: float, height: float} $face
	 * @param list<FaceDetection> $existing
	 */
	private function isKnownFace(array $face, array $existing): bool {
		foreach ($existing as $detection) {
			$x0 = max($face['x'], $detection->getX());
			$y0 = max($face['y'], $detection->getY());
			$x1 = min($face['x'] + $face['width'], $detection->getX() + $detection->getWidth());
			$y1 = min($face['y'] + $face['height'], $detection->getY() + $detection->getHeight());
			$intersection = max(0.0, $x1 - $x0) * max(0.0, $y1 - $y0);
			$union = $face['width'] * $face['height'] + $detection->getWidth() * $detection->getHeight() - $intersection;
			if ($union > 0 && $intersection / $union > self::ADDITIVE_DUPLICATE_IOU) {
				return true;
			}
		}
		return false;
	}

	#[Override]
	public function classify(array $queueFiles): void {
		if ($this->config->getAppValueString('tensorflow.purejs', 'false', lazy: true) === 'true') {
			$timeout = self::IMAGE_PUREJS_TIMEOUT;
		} else {
			$timeout = self::IMAGE_TIMEOUT;
		}

		$filteredQueueFiles = [];
		foreach ($queueFiles as $queueFile) {
			try {
				$facesByFileCount = count($this->faceDetections->findByFileId($queueFile->getFileId()));
			} catch (Exception $e) {
				$this->logger->debug('finding faces by file '.$queueFile->getFileId().' failed', ['exception' => $e]);
				$facesByFileCount = 1;
			}
			if ($facesByFileCount !== 0 && !$this->additive) {
				try {
					$this->logger->debug('Remove file with existing faces from queue '.$queueFile->getFileId());
					$this->queue->removeFromQueue(self::MODEL_NAME, $queueFile);
				} catch (Exception $e) {
					$this->logger->error('Could not remove file from queue', ['exception' => $e]);
				}
				continue;
			}
			$filteredQueueFiles[] = $queueFile;
		}

		$params = $this->getBackend()->getParams();
		$classifierProcess = $this->classifyFiles(self::MODEL_NAME, $filteredQueueFiles, $timeout);

		/**
		 * @var list<array> $faces
		 */
		foreach ($classifierProcess as $queueFile => $faces) {
			$this->logger->debug('Face results for ' . $queueFile->getFileId() . ' are in');
			try {
				\OCP\Server::get(\OCA\Recognize\Service\FaceProgress::class)->tick(count($faces));
			} catch (\Throwable $e) {
			}
			foreach ($faces as $face) {
				if ($face['score'] < $params['minScore']) {
					$this->logger->debug('Face score too low. continuing with next face.');
					continue;
				}
				if (abs($face['angle']['roll']) > $params['maxRoll'] || abs($face['angle']['yaw']) > $params['maxYaw']) {
					$this->logger->debug('Face is not straight. continuing with next face.');
					continue;
				}

				try {
					$node = $this->rootFolder->getFirstNodeById($queueFile->getFileId());
					$userIds = $node !== null ? $this->getUsersWithFileAccess($node) : [];
				} catch (InvalidPathException|NotFoundException $e) {
					$userIds = [];
				}

				// Insert face detection for all users with access
				foreach ($userIds as $userId) {
					if ($this->additive) {
						try {
							$existing = $this->faceDetections->findByFileIdAndUser($queueFile->getFileId(), $userId);
						} catch (Exception $e) {
							$this->logger->warning('Could not load existing face detections', ['exception' => $e]);
							$existing = [];
						}
						if ($this->isKnownFace($face, $existing)) {
							$this->logger->debug('Face already known for user ' . $userId . ', skipping');
							continue;
						}
						$this->logger->info('New face found in file ' . $queueFile->getFileId() . ' for user ' . $userId);
					}
					$this->logger->debug('preparing face detection for user ' . $userId);
					$faceDetection = new FaceDetection();
					$faceDetection->setX($face['x']);
					$faceDetection->setY($face['y']);
					$faceDetection->setWidth($face['width']);
					$faceDetection->setHeight($face['height']);
					$faceDetection->setVector($face['vector']);
					$faceDetection->setFileId($queueFile->getFileId());
					$faceDetection->setUserId($userId);
					try {
						$this->faceDetections->insert($faceDetection);
					} catch (\Throwable $e) {
						$this->logger->error('Could not store face detection in database', ['exception' => $e]);
						continue;
					}
					$this->logger->debug('scheduling ClusterFacesJob for user ' . $userId);
					$this->jobList->add(ClusterFacesJob::class, ['userId' => $userId]);
				}
				$this->config->setAppValueString(self::MODEL_NAME . '.status', 'true', lazy: true);
				$this->config->setAppValueString(self::MODEL_NAME . '.lastFile', (string)time(), lazy: true);
			}
		}
		$this->logger->debug('face classifier end');
	}
}
