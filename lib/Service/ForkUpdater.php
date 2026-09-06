<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Symfony\Component\Process\Process;

/**
 * Keeps the forked apps (this app and Memories) up to date from the GitHub releases of the
 * fork, since they are not distributed through the Nextcloud app store.
 *
 * An update downloads the release tarball, extracts it next to the app, swaps the directories,
 * carries over the downloaded runtime files (Node.js, libtensorflow, ffmpeg, models) and runs
 * the app upgrade (migrations, repair steps). Apps that are a git checkout are left alone.
 */
final class ForkUpdater {
	public const REPOS = [
		'recognize' => 'CristianCasapu/recognize',
		'memories' => 'CristianCasapu/memories',
	];
	/** Directories that are downloaded at install time and must survive an update */
	private const PRESERVE = [
		'recognize' => ['bin', 'models', 'node_modules/@tensorflow/tfjs-node/lib', 'node_modules/@tensorflow/tfjs-node/deps/lib', 'node_modules/@tensorflow/tfjs-node-gpu/lib', 'node_modules/@tensorflow/tfjs-node-gpu/deps/lib', 'node_modules/ffmpeg-static/ffmpeg'],
		'memories' => ['bin-ext'],
	];
	public const CACHE_SETTING = 'forkUpdates.cache';
	public const LOG_SETTING = 'forkUpdates.log';
	public const CACHE_TTL = 6 * 3600;

	public function __construct(
		private IAppManager $appManager,
		private IClientService $clientService,
		private IConfig $config,
		private SettingsService $settingsService,
		private Logger $logger,
	) {
	}

	/**
	 * @return array<string, array{installed:string, installedTag:string, latestTag:?string, latestVersion:?string, asset:?string, published:?string, available:bool, git:bool, error:?string}>
	 */
	public function check(bool $force = false): array {
		if (!$force) {
			try {
				$cached = json_decode($this->settingsService->getRawSetting(self::CACHE_SETTING, ''), true, 512, JSON_THROW_ON_ERROR);
				if (is_array($cached) && isset($cached['checkedAt'], $cached['apps']) && time() - $cached['checkedAt'] < self::CACHE_TTL) {
					return $cached['apps'];
				}
			} catch (\Throwable $e) {
			}
		}
		$apps = [];
		foreach (self::REPOS as $app => $repo) {
			if (!$this->appManager->isEnabledForAnyone($app)) {
				continue;
			}
			$installed = $this->appManager->getAppVersion($app);
			$installedTag = $this->settingsService->getRawSetting('forkUpdates.installedTag.' . $app, '');
			if ($installedTag === '') {
				$installedTag = 'v' . $installed;
			}
			$entry = ['installed' => $installed, 'installedTag' => $installedTag, 'latestTag' => null, 'latestVersion' => null, 'asset' => null, 'published' => null, 'available' => false, 'git' => is_dir($this->appManager->getAppPath($app) . '/.git'), 'error' => null];
			try {
				$client = $this->clientService->newClient();
				$response = $client->get('https://api.github.com/repos/' . $repo . '/releases/latest', ['timeout' => 20, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'nextcloud-recognize-fork-updater']]);
				$release = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
				$tag = (string)($release['tag_name'] ?? '');
				$asset = null;
				foreach ($release['assets'] ?? [] as $candidate) {
					if (str_ends_with((string)$candidate['name'], '.tar.gz')) {
						$asset = (string)$candidate['browser_download_url'];
						break;
					}
				}
				$entry['latestTag'] = $tag;
				$entry['latestVersion'] = ltrim($tag, 'v');
				$entry['asset'] = $asset;
				$entry['published'] = $release['published_at'] ?? null;
				$entry['available'] = $tag !== '' && $asset !== null && $tag !== $installedTag && ltrim($tag, 'v') !== $installed;
			} catch (\Throwable $e) {
				$entry['error'] = $e->getMessage();
			}
			$apps[$app] = $entry;
		}
		try {
			$this->settingsService->setRawSetting(self::CACHE_SETTING, json_encode(['checkedAt' => time(), 'apps' => $apps], JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
		}
		return $apps;
	}

	/**
	 * @param callable(string):void $log
	 * @throws \RuntimeException
	 */
	public function update(string $app, callable $log): void {
		if (!isset(self::REPOS[$app])) {
			throw new \RuntimeException('Unknown app ' . $app . ' (known: ' . implode(', ', array_keys(self::REPOS)) . ')');
		}
		$info = $this->check(true)[$app] ?? null;
		if ($info === null) {
			throw new \RuntimeException($app . ' is not installed');
		}
		if ($info['error'] !== null) {
			throw new \RuntimeException('Could not query GitHub: ' . $info['error']);
		}
		if ($info['git']) {
			throw new \RuntimeException($app . ' is a git checkout; update it with git (git fetch && git checkout <tag>) instead');
		}
		if (!$info['available']) {
			$log($app . ' is already at ' . $info['installedTag']);
			return;
		}
		$appPath = $this->appManager->getAppPath($app);
		$appsDir = dirname($appPath);
		if (!is_writable($appsDir) || !is_writable($appPath)) {
			throw new \RuntimeException($appsDir . ' is not writable by the PHP user; run the update as the web server user or fix the permissions');
		}
		$workDir = rtrim($this->config->getSystemValueString('datadirectory'), '/') . '/appdata_' . $this->config->getSystemValueString('instanceid') . '/recognize/updates';
		if (!is_dir($workDir) && !mkdir($workDir, 0770, true) && !is_dir($workDir)) {
			throw new \RuntimeException('Could not create ' . $workDir);
		}
		$tarball = $workDir . '/' . $app . '-' . $info['latestTag'] . '.tar.gz';
		$log('Downloading ' . $info['asset']);
		$this->clientService->newClient()->get($info['asset'], ['sink' => $tarball, 'timeout' => 1800]);
		$log('Downloaded ' . round(filesize($tarball) / 1048576, 1) . ' MB');

		$extractDir = $workDir . '/extract-' . $app;
		$this->run(['rm', '-rf', $extractDir]);
		mkdir($extractDir, 0770, true);
		$this->run(['tar', '-xzf', $tarball, '-C', $extractDir]);
		if (!is_file($extractDir . '/' . $app . '/appinfo/info.xml')) {
			throw new \RuntimeException('The release tarball does not contain ' . $app . '/appinfo/info.xml');
		}
		$newPath = $appsDir . '/' . $app . '.new-' . time();
		$backupPath = $appsDir . '/' . $app . '.bak-' . time();
		$this->run(['mv', $extractDir . '/' . $app, $newPath]);

		$log('Carrying over downloaded runtime files');
		foreach (self::PRESERVE[$app] ?? [] as $relative) {
			if (!file_exists($appPath . '/' . $relative)) {
				continue;
			}
			$this->run(['rm', '-rf', $newPath . '/' . $relative]);
			@mkdir(dirname($newPath . '/' . $relative), 0770, true);
			$this->run(['cp', '-a', $appPath . '/' . $relative, $newPath . '/' . $relative]);
		}

		$log('Swapping ' . $appPath);
		$this->run(['mv', $appPath, $backupPath]);
		try {
			$this->run(['mv', $newPath, $appPath]);
		} catch (\Throwable $e) {
			$this->run(['mv', $backupPath, $appPath]);
			throw $e;
		}

		$log('Running the app upgrade (migrations, repair steps)');
		try {
			\OC_App::updateApp($app);
		} catch (\Throwable $e) {
			$log('Upgrade failed, restoring the previous version: ' . $e->getMessage());
			$this->run(['rm', '-rf', $appPath]);
			$this->run(['mv', $backupPath, $appPath]);
			throw new \RuntimeException('App upgrade failed: ' . $e->getMessage(), 0, $e);
		}
		$this->run(['rm', '-rf', $backupPath, $tarball]);
		$this->settingsService->setRawSetting('forkUpdates.installedTag.' . $app, (string)$info['latestTag']);
		$this->settingsService->setRawSetting(self::CACHE_SETTING, '');
		$log($app . ' updated to ' . $info['latestTag']);
	}

	/**
	 * @param list<string> $command
	 */
	private function run(array $command): void {
		$proc = new Process($command);
		$proc->setTimeout(1800);
		$proc->run();
		if ($proc->getExitCode() !== 0) {
			throw new \RuntimeException(implode(' ', $command) . ' failed: ' . trim($proc->getErrorOutput()));
		}
	}
}
