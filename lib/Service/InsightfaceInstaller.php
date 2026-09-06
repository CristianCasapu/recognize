<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCP\IBinaryFinder;
use Symfony\Component\Process\Process;

/**
 * Installs the Python side of the InsightFace backend into a virtualenv inside the Nextcloud
 * data directory (no root needed) and downloads the model pack.
 *
 * Also checks an existing installation (for the admin page and the setup checks).
 */
final class InsightfaceInstaller {
	public const LOG_SETTING = 'insightface.installLog';
	public const CHECK_CACHE = 'insightface.checkCache';
	public const CHECK_TTL = 3600;
	/** onnxruntime-gpu 1.18 is the last line built for CUDA 11 / cuDNN 8, which the bundled libtensorflow needs as well */
	public const PACKAGES_GPU = ['numpy<2', 'onnxruntime-gpu==1.18.1', 'opencv-python-headless', 'onnx', 'insightface', 'pillow', 'tokenizers', 'huggingface_hub'];
	public const PACKAGES_CPU = ['numpy<2', 'onnxruntime==1.18.1', 'opencv-python-headless', 'onnx', 'insightface', 'pillow', 'tokenizers', 'huggingface_hub'];

	public function __construct(
		private SettingsService $settingsService,
		private FaceBackend $backend,
		private IBinaryFinder $binaryFinder,
		private Logger $logger,
	) {
	}

	/**
	 * @param callable(string):void $log
	 * @throws \RuntimeException
	 */
	public function install(bool $gpu, ?string $directory, callable $log): void {
		$directory = $directory !== null && $directory !== '' ? rtrim($directory, '/') : $this->backend->getInsightfaceDir();
		$venv = $directory . '/venv';
		$python3 = $this->binaryFinder->findBinaryPath('python3');
		if ($python3 === false) {
			throw new \RuntimeException('python3 was not found on this server. Install Python 3.9+ (Debian/Ubuntu: apt install python3 python3-venv build-essential) and try again.');
		}
		if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
			throw new \RuntimeException('Could not create ' . $directory);
		}
		$env = ['TMPDIR' => $directory . '/tmp', 'PIP_CACHE_DIR' => $directory . '/pip-cache'];
		@mkdir($env['TMPDIR'], 0770, true);

		$log('Creating virtualenv in ' . $venv);
		$this->run([$python3, '-m', 'venv', $venv], $env, $log, 300);
		$pip = $venv . '/bin/pip';
		$log('Upgrading pip and build tools');
		$this->run([$pip, 'install', '--upgrade', 'pip', 'wheel', 'setuptools', 'cython'], $env, $log, 900);
		$packages = $gpu ? self::PACKAGES_GPU : self::PACKAGES_CPU;
		$log('Installing ' . implode(' ', $packages) . ' (this downloads a few hundred MB and may take several minutes)');
		$this->run([$pip, 'install', ...$packages], $env, $log, 3600);

		$model = $this->backend->getModelName();
		$log('Downloading model pack ' . $model . ' into ' . $directory);
		$snippet = 'from insightface.app import FaceAnalysis; import sys; a = FaceAnalysis(name=sys.argv[1], root=sys.argv[2], providers=["CPUExecutionProvider"]); a.prepare(ctx_id=-1); print("models ok")';
		$this->run([$venv . '/bin/python', '-c', $snippet, $model, $directory], $env, $log, 1800);

		$this->settingsService->setSetting('python_binary', $venv . '/bin/python');
		$this->settingsService->setSetting('insightface.root', $directory);
		$this->settingsService->setRawSetting(self::CHECK_CACHE, '');
		$log('InsightFace installed: python=' . $venv . '/bin/python, models=' . $directory . '/models/' . $model);
	}

	/**
	 * Check the installation (cached).
	 *
	 * @return array{ok:bool, python:string, root:string, model:string, modelsPresent:bool, providers:list<string>, gpu:bool, versions:array<string,string>, output:string, checkedAt:int}
	 */
	public function check(bool $force = false): array {
		if (!$force) {
			try {
				$cached = json_decode($this->settingsService->getRawSetting(self::CHECK_CACHE, ''), true, 512, JSON_THROW_ON_ERROR);
				if (is_array($cached) && isset($cached['checkedAt']) && time() - $cached['checkedAt'] < self::CHECK_TTL) {
					return $cached;
				}
			} catch (\Throwable $e) {
			}
		}
		$python = $this->backend->getPythonBinary();
		$root = $this->backend->getInsightfaceRoot();
		$model = $this->backend->getModelName();
		$modelsPresent = count(glob($root . '/models/' . $model . '/*.onnx') ?: []) > 0;
		$result = [
			'ok' => false,
			'python' => $python,
			'root' => $root,
			'model' => $model,
			'modelsPresent' => $modelsPresent,
			'providers' => [],
			'gpu' => false,
			'versions' => [],
			'output' => '',
			'checkedAt' => time(),
		];
		if (!is_executable($python)) {
			$result['output'] = 'Python binary not found: ' . $python;
		} else {
			$snippet = 'import json, insightface, onnxruntime, cv2, numpy; print(json.dumps({"providers": onnxruntime.get_available_providers(), "versions": {"insightface": insightface.__version__, "onnxruntime": onnxruntime.__version__, "opencv": cv2.__version__, "numpy": numpy.__version__}}))';
			try {
				$proc = new Process([$python, '-c', $snippet]);
				$proc->setTimeout(120);
				$proc->run();
				$output = trim($proc->getOutput());
				$result['output'] = mb_substr($output . "\n" . $proc->getErrorOutput(), -2000);
				if ($proc->getExitCode() === 0) {
					$info = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
					$result['providers'] = $info['providers'] ?? [];
					$result['versions'] = $info['versions'] ?? [];
					$result['gpu'] = in_array('CUDAExecutionProvider', $result['providers'], true);
					$result['ok'] = $modelsPresent;
				}
			} catch (\Throwable $e) {
				$result['output'] = $e->getMessage();
			}
		}
		try {
			$this->settingsService->setRawSetting(self::CHECK_CACHE, json_encode($result, JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
		}
		return $result;
	}

	/**
	 * @param list<string> $command
	 * @param array<string,string> $env
	 * @param callable(string):void $log
	 */
	private function run(array $command, array $env, callable $log, int $timeout): void {
		$proc = new Process($command, null, $env);
		$proc->setTimeout($timeout);
		$proc->run(function (string $type, string $data) use ($log): void {
			foreach (explode("\n", trim($data)) as $line) {
				if (trim($line) !== '' && !str_starts_with($line, '  ') && !str_contains($line, '%|')) {
					$log($line);
				}
			}
		});
		if ($proc->getExitCode() !== 0) {
			throw new \RuntimeException('Command failed (' . $proc->getExitCode() . '): ' . implode(' ', $command) . "\n" . mb_substr($proc->getErrorOutput(), -1500));
		}
	}
}
