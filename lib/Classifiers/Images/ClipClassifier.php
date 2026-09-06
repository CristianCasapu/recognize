<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Classifiers\Images;

use OCA\Recognize\Classifiers\Classifier;
use OCA\Recognize\Db\ClipEmbeddingMapper;
use OCA\Recognize\Service\ClipModel;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\QueueService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\ITempManager;
use Override;

/**
 * Computes a CLIP image embedding (for natural-language search) and a perceptual hash
 * (for near-duplicate detection) per photo, with the Python/ONNX Runtime environment.
 */
final class ClipClassifier extends Classifier {
	public const IMAGE_TIMEOUT = 120; // seconds per image (includes model load for the first one)
	public const MODEL_NAME = 'clip';

	public function __construct(
		Logger $logger,
		IAppConfig $config,
		QueueService $queue,
		IRootFolder $rootFolder,
		ITempManager $tempManager,
		IPreview $previewProvider,
		private ClipEmbeddingMapper $embeddings,
		private ClipModel $clipModel,
	) {
		parent::__construct($logger, $config, $rootFolder, $queue, $tempManager, $previewProvider);
	}

	/**
	 * @return list<string>
	 */
	#[Override]
	protected function getClassifierCommand(string $model): array {
		return [$this->clipModel->getPythonBinary(), dirname(__DIR__, 3) . '/src/classifier_clip.py', '-'];
	}

	/**
	 * @return array<string,string>
	 */
	#[Override]
	protected function getExtraEnvironment(): array {
		return $this->clipModel->getEnvironment();
	}

	#[Override]
	public function classify(array $queueFiles): void {
		if (!$this->clipModel->isInstalled()) {
			throw new \ErrorException('The CLIP model is not installed (occ recognize:install-clip)');
		}
		$model = $this->clipModel->getModelName();
		foreach ($this->classifyFiles(self::MODEL_NAME, $queueFiles, self::IMAGE_TIMEOUT) as $queueFile => $result) {
			if (!is_array($result) || !isset($result['vector']) || !is_array($result['vector']) || count($result['vector']) === 0) {
				$this->logger->debug('No embedding for file ' . $queueFile->getFileId());
				continue;
			}
			try {
				$this->embeddings->store($queueFile->getFileId(), $model, array_map('floatval', $result['vector']), isset($result['phash']) ? (string)$result['phash'] : null);
			} catch (\Throwable $e) {
				$this->logger->error('Could not store embedding for file ' . $queueFile->getFileId(), ['exception' => $e]);
				continue;
			}
			$this->config->setAppValueString(self::MODEL_NAME . '.status', 'true', lazy: true);
			$this->config->setAppValueString(self::MODEL_NAME . '.lastFile', (string)time(), lazy: true);
		}
	}
}
