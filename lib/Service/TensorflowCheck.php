<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use Symfony\Component\Process\Process;

/**
 * Runs the Node.js smoke tests for the configured TensorFlow mode (cpu / gpu / wasm)
 * and extracts actionable diagnostics (e.g. missing CUDA/cuDNN shared libraries).
 *
 * Results are cached in the app config for a while because starting Node.js with
 * libtensorflow takes a few seconds and setup checks run on every admin overview load.
 */
final class TensorflowCheck {
	/** Suffixed with the PHP SAPI: the web server process is often sandboxed (systemd PrivateDevices) and cannot see the GPU */
	public const CACHE_KEY = 'tensorflow.checkCache';
	public const CACHE_TTL = 3600;
	public const TIMEOUT = 120;

	public const MODE_CPU = 'cpu';
	public const MODE_GPU = 'gpu';
	public const MODE_WASM = 'wasm';

	private const SCRIPTS = [
		self::MODE_CPU => 'test_libtensorflow.js',
		self::MODE_GPU => 'test_gputensorflow.js',
		self::MODE_WASM => 'test_wasmtensorflow.js',
	];

	public function __construct(
		private SettingsService $settingsService,
	) {
	}

	/**
	 * The mode the classifiers will run in with the current settings.
	 */
	public function getConfiguredMode(): string {
		if ($this->settingsService->getSetting('tensorflow.purejs') === 'true') {
			return self::MODE_WASM;
		}
		if ($this->settingsService->getSetting('tensorflow.gpu') === 'true') {
			return self::MODE_GPU;
		}
		return self::MODE_CPU;
	}

	/**
	 * Check the configured mode, using a cached result if it is recent enough.
	 *
	 * @return array{mode:string, ok:bool, missingLibraries:list<string>, output:string, checkedAt:int}
	 */
	public function run(bool $force = false): array {
		$mode = $this->getConfiguredMode();
		if (!$force) {
			$cached = $this->getCached(self::currentContext());
			if ($cached !== null && $cached['mode'] === $mode && time() - $cached['checkedAt'] < self::CACHE_TTL) {
				return $cached;
			}
		}
		$result = $this->test($mode);
		$this->settingsService->setRawSetting(self::CACHE_KEY . '.' . self::currentContext(), json_encode($result, JSON_THROW_ON_ERROR));
		return $result;
	}

	/**
	 * 'cli' for occ/cron, 'web' for requests handled by the web server.
	 */
	public static function currentContext(): string {
		return PHP_SAPI === 'cli' ? 'cli' : 'web';
	}

	/**
	 * The last result obtained from a CLI process (occ, cron). Background jobs run in that
	 * context, so this is the result that matters when the web server cannot see the GPU.
	 *
	 * @return array{mode:string, ok:bool, missingLibraries:list<string>, output:string, checkedAt:int}|null
	 */
	public function getLastCliResult(): ?array {
		return $this->getCached('cli');
	}

	/**
	 * Whether the failed check most likely failed only because the web server process is
	 * sandboxed away from the GPU device (systemd PrivateDevices=yes), not because CUDA is missing.
	 *
	 * @param array{mode:string, ok:bool, missingLibraries:list<string>, output:string, checkedAt:int} $result
	 */
	public static function looksLikeDeviceSandbox(array $result): bool {
		return !$result['ok']
			&& $result['mode'] === self::MODE_GPU
			&& count($result['missingLibraries']) === 0
			&& (str_contains($result['output'], 'does not exist') || str_contains($result['output'], 'CUDA_ERROR_NO_DEVICE'));
	}

	/**
	 * Run the smoke test for a specific mode (never cached).
	 *
	 * @return array{mode:string, ok:bool, missingLibraries:list<string>, output:string, checkedAt:int}
	 */
	public function test(string $mode): array {
		if (!isset(self::SCRIPTS[$mode])) {
			throw new \InvalidArgumentException('Unknown tensorflow mode ' . $mode);
		}
		$node = $this->settingsService->getSetting('node_binary');
		$script = dirname(__DIR__, 2) . '/src/' . self::SCRIPTS[$mode];
		$output = '';
		$ok = false;
		try {
			$proc = new Process([$node, $script], dirname(__DIR__, 2));
			$proc->setEnv($this->settingsService->getClassifierEnvironment());
			$proc->setTimeout(self::TIMEOUT);
			$proc->run();
			$output = $proc->getOutput() . $proc->getErrorOutput();
			$ok = $proc->getExitCode() === 0;
		} catch (\Throwable $e) {
			$output = $e->getMessage();
		}

		return [
			'mode' => $mode,
			'ok' => $ok,
			'missingLibraries' => self::extractMissingLibraries($output),
			'output' => mb_substr($output, -4000),
			'checkedAt' => time(),
		];
	}

	/**
	 * @return list<string>
	 */
	public static function extractMissingLibraries(string $output): array {
		if (preg_match_all("/Could not load dynamic library '([^']+)'/", $output, $matches) === false) {
			return [];
		}
		return array_values(array_unique($matches[1]));
	}

	/**
	 * @return array{mode:string, ok:bool, missingLibraries:list<string>, output:string, checkedAt:int}|null
	 */
	private function getCached(string $context): ?array {
		try {
			$cached = json_decode($this->settingsService->getRawSetting(self::CACHE_KEY . '.' . $context, ''), true, 512, JSON_THROW_ON_ERROR);
		} catch (\Throwable $e) {
			return null;
		}
		if (!is_array($cached) || !isset($cached['mode'], $cached['ok'], $cached['checkedAt'])) {
			return null;
		}
		$cached['missingLibraries'] = $cached['missingLibraries'] ?? [];
		$cached['output'] = $cached['output'] ?? '';
		return $cached;
	}
}
