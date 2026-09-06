<?php

declare(strict_types=1);

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Service;

use OCA\Recognize\BackgroundJobs\SchedulerJob;
use OCA\Recognize\Classifiers\Audio\MusicnnClassifier;
use OCA\Recognize\Classifiers\Images\ClusteringFaceClassifier;
use OCA\Recognize\Classifiers\Images\ImagenetClassifier;
use OCA\Recognize\Classifiers\Images\LandmarksClassifier;
use OCA\Recognize\Classifiers\Video\MovinetClassifier;
use OCA\Recognize\Exception\Exception;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;

final class SettingsService {
	/** @var array<string,string>  */
	private const DEFAULTS = [
		'tensorflow.cores' => '0',
		'tensorflow.gpu' => 'false',
		'tensorflow.purejs' => 'false',
		'geo.enabled' => 'false',
		'imagenet.enabled' => 'false',
		'landmarks.enabled' => 'false',
		'faces.enabled' => 'false',
		'musicnn.enabled' => 'false',
		'movinet.enabled' => 'false',
		'node_binary' => '',
		'clusterFaces.status' => 'null',
		'faces.status' => 'null',
		'imagenet.status' => 'null',
		'landmarks.status' => 'null',
		'movinet.status' => 'null',
		'musicnn.status' => 'null',
		'clusterFaces.lastRun' => '0',
		'faces.lastFile' => '0',
		'imagenet.lastFile' => '0',
		'landmarks.lastFile' => '0',
		'movinet.lastFile' => '0',
		'musicnn.lastFile' => '0',
		'faces.batchSize' => '500',
		'imagenet.batchSize' => '100',
		'landmarks.batchSize' => '100',
		'movinet.batchSize' => '20',
		'musicnn.batchSize' => '100',
		'nice_binary' => '',
		'nice_value' => '0',
		'concurrency.enabled' => 'false',
		'ffmpeg_binary' => '',
		// Face detection: size of the downscaled image handed to the detector (1024, 2048 or 4096).
		// The detector itself works on 512px, so larger previews mainly improve descriptor quality.
		'faces.previewDimension' => '1024',
		// Additionally run the detector on overlapping tiles so that small faces get found
		'faces.tiling' => 'false',
		// Faces smaller than this fraction of the image are not clustered (unreliable descriptors)
		'faces.minDetectionSize' => '0.03',
		// Merge unnamed clusters into a named one when their centroids are closer than this (0 = off)
		'faces.autoMergeThreshold' => '0',
		// Extra library path for the Node.js classifier processes (e.g. where CUDA/cuDNN live)
		'tensorflow.ldLibraryPath' => '',
		// Notify admins via Nextcloud notifications when jobs fail
		'notifications.enabled' => 'true',
		// Face backend: 'faceapi' (Node.js, built in) or 'insightface' (Python, RetinaFace + ArcFace)
		'faces.backend' => 'faceapi',
		'python_binary' => '',
		'insightface.root' => '',
		'insightface.model' => 'buffalo_l',
		// Advanced clustering overrides (empty = backend default, see FaceBackend::PARAMS)
		'faces.clusterSeparation' => '',
		'faces.clusterEdgeLength' => '',
		'faces.assignThreshold' => '',
	];

	/** @var array<string,string>  */
	private const PUREJS_DEFAULTS = [
		'faces.batchSize' => '50',
		'imagenet.batchSize' => '20',
		'landmarks.batchSize' => '20',
		'movinet.batchSize' => '5',
		'musicnn.batchSize' => '20',
	];
	public const LAZY_SETTINGS = [
		'tensorflow.purejs',
		'node_binary',
		'nice_binary',
		'nice_value',
		'ffmpeg_binary',
		'clusterFaces.status',
		'faces.status',
		'imagenet.status',
		'landmarks.status',
		'movinet.status',
		'musicnn.status',
		'faces.lastFile',
		'imagenet.lastFile',
		'landmarks.lastFile',
		'movinet.lastFile',
		'musicnn.lastFile',
		'clusterFaces.lastRun',
		'faces.batchSize',
		'imagenet.batchSize',
		'landmarks.batchSize',
		'movinet.batchSize',
		'musicnn.batchSize',
		'concurrency.enabled',
		'faces.previewDimension',
		'faces.tiling',
		'faces.minDetectionSize',
		'faces.autoMergeThreshold',
		'tensorflow.ldLibraryPath',
		'notifications.enabled',
		'python_binary',
		'insightface.root',
		'insightface.model',
		'faces.clusterSeparation',
		'faces.clusterEdgeLength',
		'faces.assignThreshold',
	];

	private IAppConfig $config;
	private IJobList $jobList;

	public function __construct(IAppConfig $config, IJobList $jobList) {
		$this->config = $config;
		$this->jobList = $jobList;
	}

	/**
	 * @param string $key
	 * @return string
	 */
	public function getSetting(string $key): string {
		if (strpos($key, 'batchSize') !== false) {
			return $this->config->getAppValueString($key, $this->getSetting('tensorflow.purejs') === 'false' ? self::DEFAULTS[$key] : self::PUREJS_DEFAULTS[$key]);
		}
		$lazy = false;
		if (in_array($key, self::LAZY_SETTINGS, true)) {
			$lazy = true;
		}
		return $this->config->getAppValueString($key, self::DEFAULTS[$key], lazy: $lazy);
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @return void
	 * @throws \OCA\Recognize\Exception\Exception
	 */
	public function setSetting(string $key, string $value): void {
		if (!array_key_exists($key, self::DEFAULTS)) {
			throw new Exception('Unknown settings key '.$key);
		}
		if ($value === 'true' && $this->config->getAppValueString($key, 'false') === 'false') {
			// Additional model enabled: Schedule new crawl run for the affected mime types
			switch ($key) {
				case ClusteringFaceClassifier::MODEL_NAME . '.enabled':
					$this->jobList->add(SchedulerJob::class, ['models' => [ClusteringFaceClassifier::MODEL_NAME]]);
					break;
				case ImagenetClassifier::MODEL_NAME . '.enabled':
					$this->jobList->add(SchedulerJob::class, ['models' => [ImagenetClassifier::MODEL_NAME]]);
					break;
				case LandmarksClassifier::MODEL_NAME . '.enabled':
					$this->jobList->add(SchedulerJob::class, ['models' => [LandmarksClassifier::MODEL_NAME]]);
					break;
				case MovinetClassifier::MODEL_NAME . '.enabled':
					$this->jobList->add(SchedulerJob::class, ['models' => [MovinetClassifier::MODEL_NAME]]);
					break;
				case MusicnnClassifier::MODEL_NAME . '.enabled':
					$this->jobList->add(SchedulerJob::class, ['models' => [MusicnnClassifier::MODEL_NAME]]);
					break;
				default:
					break;
			}
		}
		$lazy = false;
		if (in_array($key, self::LAZY_SETTINGS, true)) {
			$lazy = true;
		}
		$this->config->setAppValueString($key, $value, lazy: $lazy);
	}

	/**
	 * Read an internal app config value that is not a user-facing setting
	 * (caches, notification throttles, the error log).
	 */
	public function getRawSetting(string $key, string $default = ''): string {
		return $this->config->getAppValueString($key, $default, lazy: true);
	}

	public function setRawSetting(string $key, string $value): void {
		$this->config->setAppValueString($key, $value, lazy: true);
	}

	/**
	 * Environment variables for the Node.js classifier processes and the TensorFlow smoke tests.
	 *
	 * @return array<string,string>
	 */
	public function getClassifierEnvironment(): array {
		$env = [];
		if ($this->getSetting('tensorflow.gpu') === 'true') {
			$env['RECOGNIZE_GPU'] = 'true';
		}
		if ($this->getSetting('tensorflow.purejs') === 'true') {
			$env['RECOGNIZE_PUREJS'] = 'true';
		}
		$cores = $this->getSetting('tensorflow.cores');
		if ($cores !== '0') {
			$env['RECOGNIZE_CORES'] = $cores;
		}
		$ldLibraryPath = trim($this->getSetting('tensorflow.ldLibraryPath'));
		if ($ldLibraryPath !== '') {
			$existing = getenv('LD_LIBRARY_PATH');
			$env['LD_LIBRARY_PATH'] = is_string($existing) && $existing !== '' ? $ldLibraryPath . ':' . $existing : $ldLibraryPath;
		}
		return $env;
	}

	/**
	 * @return array
	 */
	public function getAll(): array {
		$settings = [];
		foreach (array_keys(self::DEFAULTS) as $key) {
			$settings[$key] = $this->getSetting($key);
		}
		return $settings;
	}
}
