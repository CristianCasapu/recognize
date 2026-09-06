<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use Symfony\Component\Process\Process;

/**
 * The CLIP model used for natural-language photo search: where it lives, whether it is
 * installed, how to download it (ONNX exports published by the Immich project on Hugging Face).
 */
final class ClipModel {
	public const DEFAULT_MODEL = 'XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k';
	public const HF_ORG = 'immich-app';
	/** Multilingual models understand Romanian, English, … ; the openai one is English only but smaller */
	public const KNOWN_MODELS = [
		'XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k' => 'multilingual, ViT-B/32 (recommended, ~1.6 GB)',
		'nllb-clip-base-siglip__v1' => 'multilingual (200 languages), SigLIP base',
		'ViT-B-32__openai' => 'English only, smallest (~600 MB)',
	];
	public const INSTALL_LOG = 'clip.installLog';

	public function __construct(
		private SettingsService $settingsService,
		private FaceBackend $faceBackend,
		private Logger $logger,
	) {
	}

	public function getModelName(): string {
		$model = trim($this->settingsService->getSetting('clip.model'));
		return $model !== '' ? $model : self::DEFAULT_MODEL;
	}

	public function getBaseDir(): string {
		$dir = trim($this->settingsService->getSetting('clip.dir'));
		return $dir !== '' ? rtrim($dir, '/') : dirname($this->faceBackend->getInsightfaceDir()) . '/clip';
	}

	public function getModelDir(): string {
		return $this->getBaseDir() . '/' . $this->getModelName();
	}

	public function isInstalled(): bool {
		return is_file($this->getModelDir() . '/visual/model.onnx') && is_file($this->getModelDir() . '/textual/model.onnx');
	}

	public function getPythonBinary(): string {
		return $this->faceBackend->getPythonBinary();
	}

	public function isPythonReady(): bool {
		return is_executable($this->getPythonBinary());
	}

	/**
	 * @return array<string,string>
	 */
	public function getEnvironment(): array {
		return ['RECOGNIZE_CLIP_MODEL_DIR' => $this->getModelDir()];
	}

	/**
	 * Download the model pack from Hugging Face into the base directory (needs the Python environment).
	 *
	 * @param callable(string):void $log
	 * @throws \RuntimeException
	 */
	public function download(callable $log): void {
		if (!$this->isPythonReady()) {
			throw new \RuntimeException('The Python environment is missing; install it first (occ recognize:install-insightface or the button in the admin settings)');
		}
		$dir = $this->getBaseDir();
		if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
			throw new \RuntimeException('Could not create ' . $dir);
		}
		$log('Downloading ' . self::HF_ORG . '/' . $this->getModelName() . ' to ' . $this->getModelDir() . ' (this can take a few minutes)');
		$proc = new Process([$this->getPythonBinary(), dirname(__DIR__, 2) . '/src/clip_download.py', self::HF_ORG . '/' . $this->getModelName(), $this->getModelDir()], null, ['TMPDIR' => $dir . '/tmp', 'HF_HUB_DISABLE_PROGRESS_BARS' => '1']);
		@mkdir($dir . '/tmp', 0770, true);
		$proc->setTimeout(3600);
		$proc->run(function (string $type, string $data) use ($log): void {
			foreach (explode("\n", trim($data)) as $line) {
				if (trim($line) !== '') {
					$log($line);
				}
			}
		});
		if ($proc->getExitCode() !== 0 || !$this->isInstalled()) {
			throw new \RuntimeException('Model download failed: ' . mb_substr($proc->getErrorOutput(), -800));
		}
		$log('Model ready');
	}
}
