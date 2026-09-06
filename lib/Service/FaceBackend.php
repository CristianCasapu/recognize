<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCP\IConfig;

/**
 * The face detection / embedding backends and their distance thresholds.
 *
 * - faceapi: the built-in @vladmandic/face-api models in Node.js (SSD MobileNet + 128-d ResNet
 *   descriptor). Distances are plain Euclidean, same person ~0.3-0.5, different people > 0.6.
 * - insightface: RetinaFace + ArcFace (512-d, L2 normalised) via ONNX Runtime in Python.
 *   Much better at telling similar people (children, relatives) apart. Euclidean distance on
 *   unit vectors is monotonic with cosine similarity: d = sqrt(2 - 2·cos). Measured on real
 *   data: same person cos 0.49-0.83 (d 0.58-1.01), different people cos < 0.15 (d > 1.30).
 *
 * Embeddings of different backends are incompatible; switching requires resetting the
 * detections (see `occ recognize:switch-face-backend`).
 */
final class FaceBackend {
	public const FACEAPI = 'faceapi';
	public const INSIGHTFACE = 'insightface';

	public const PARAMS = [
		self::FACEAPI => [
			'dimensions' => 128,
			'minScore' => 0.9,
			'maxYaw' => 50.0,
			'maxRoll' => 30.0,
			'clusterSeparation' => 0.35,
			'clusterEdgeLength' => 0.5,
			'mergeThreshold' => 0.4,
			// no direct assignment: the descriptor is not discriminative enough
			'assignThreshold' => 0.0,
			'assignMargin' => 0.0,
		],
		self::INSIGHTFACE => [
			'dimensions' => 512,
			'minScore' => 0.6,
			'maxYaw' => 60.0,
			'maxRoll' => 45.0,
			'clusterSeparation' => 0.75,
			'clusterEdgeLength' => 1.05,
			'mergeThreshold' => 0.85,
			// assign a new face straight to an existing cluster when its centroid is closer than this
			// (cos ≈ 0.45) and the runner-up cluster is at least assignMargin farther away
			'assignThreshold' => 1.05,
			'assignMargin' => 0.12,
		],
	];

	public function __construct(
		private SettingsService $settingsService,
		private IConfig $config,
	) {
	}

	public function getName(): string {
		$backend = $this->settingsService->getSetting('faces.backend');
		return isset(self::PARAMS[$backend]) ? $backend : self::FACEAPI;
	}

	public function isInsightface(): bool {
		return $this->getName() === self::INSIGHTFACE;
	}

	/**
	 * @return array{dimensions:int, minScore:float, maxYaw:float, maxRoll:float, clusterSeparation:float, clusterEdgeLength:float, mergeThreshold:float, assignThreshold:float, assignMargin:float}
	 */
	public function getParams(): array {
		$params = self::PARAMS[$this->getName()];
		// optional overrides from the settings (empty = backend default)
		foreach (['clusterSeparation' => 'faces.clusterSeparation', 'clusterEdgeLength' => 'faces.clusterEdgeLength', 'assignThreshold' => 'faces.assignThreshold'] as $param => $setting) {
			$value = trim($this->settingsService->getSetting($setting));
			if ($value !== '' && is_numeric($value)) {
				$params[$param] = (float)$value;
			}
		}
		return $params;
	}

	/**
	 * Directory for the Python virtualenv and the InsightFace model packs (inside the Nextcloud data dir,
	 * so it survives app updates).
	 */
	public function getInsightfaceDir(): string {
		return rtrim($this->config->getSystemValueString('datadirectory'), '/') . '/appdata_' . $this->config->getSystemValueString('instanceid') . '/recognize/insightface';
	}

	public function getInsightfaceRoot(): string {
		$root = trim($this->settingsService->getSetting('insightface.root'));
		return $root !== '' ? $root : $this->getInsightfaceDir();
	}

	public function getPythonBinary(): string {
		$python = trim($this->settingsService->getSetting('python_binary'));
		return $python !== '' ? $python : $this->getInsightfaceDir() . '/venv/bin/python';
	}

	public function getModelName(): string {
		$model = trim($this->settingsService->getSetting('insightface.model'));
		return $model !== '' ? $model : 'buffalo_l';
	}

	/**
	 * Environment variables for the Python classifier process.
	 *
	 * @return array<string,string>
	 */
	public function getInsightfaceEnvironment(): array {
		return [
			'RECOGNIZE_INSIGHTFACE_ROOT' => $this->getInsightfaceRoot(),
			'RECOGNIZE_INSIGHTFACE_MODEL' => $this->getModelName(),
		];
	}
}
