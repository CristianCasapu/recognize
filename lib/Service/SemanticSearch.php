<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\ClipEmbeddingMapper;
use OCP\ICacheFactory;
use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;

/**
 * Natural-language photo search: embeds the query with the CLIP text tower and ranks the
 * stored image embeddings by cosine similarity.
 *
 * Results are file ids; callers that show them to a user must scope them to the files that
 * user can see (Memories does that through its timeline query; the REST endpoint filters).
 */
final class SemanticSearch {
	/** laion CLIP cosine similarities: relevant photos usually score above this */
	public const DEFAULT_MIN_SCORE = 0.17;
	public const DEFAULT_LIMIT = 500;
	public const TEXT_CACHE_TTL = 24 * 3600;

	public function __construct(
		private ClipEmbeddingMapper $embeddings,
		private ClipModel $clipModel,
		private SettingsService $settingsService,
		private ICacheFactory $cacheFactory,
		private IRootFolder $rootFolder,
		private Logger $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->settingsService->getSetting('clip.enabled') === 'true' && $this->clipModel->isInstalled() && $this->clipModel->isPythonReady();
	}

	public function getMinScore(): float {
		$value = (float)$this->settingsService->getSetting('clip.minScore');
		return $value > 0 ? $value : self::DEFAULT_MIN_SCORE;
	}

	/**
	 * @return array<int, float> file id => score, best first
	 * @throws \RuntimeException|\OCP\DB\Exception
	 */
	public function search(string $text, int $limit = self::DEFAULT_LIMIT, ?float $minScore = null): array {
		$text = trim($text);
		if ($text === '') {
			return [];
		}
		$minScore ??= $this->getMinScore();
		$query = $this->embedText($text);
		$dimensions = count($query);
		$scores = [];
		foreach ($this->embeddings->iterateVectors($this->clipModel->getModelName()) as $fileId => $packed) {
			$vector = unpack('g*', $packed);
			if ($vector === false || count($vector) !== $dimensions) {
				continue;
			}
			$dot = 0.0;
			$i = 1;
			foreach ($query as $q) {
				$dot += $q * $vector[$i++];
			}
			if ($dot >= $minScore) {
				$scores[$fileId] = $dot;
			}
		}
		arsort($scores);
		return array_slice($scores, 0, $limit, true);
	}

	/**
	 * Like search(), restricted to files the user can access.
	 *
	 * @return array<int, float>
	 * @throws \RuntimeException|\OCP\DB\Exception
	 */
	public function searchForUser(string $userId, string $text, int $limit = self::DEFAULT_LIMIT, ?float $minScore = null): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$visible = [];
		foreach ($this->search($text, $limit * 3, $minScore) as $fileId => $score) {
			if ($userFolder->getFirstNodeById($fileId) !== null) {
				$visible[$fileId] = $score;
				if (count($visible) >= $limit) {
					break;
				}
			}
		}
		return $visible;
	}

	/**
	 * @return list<float>
	 * @throws \RuntimeException
	 */
	public function embedText(string $text): array {
		$cache = $this->cacheFactory->createDistributed('recognize_clip_text');
		$key = md5($this->clipModel->getModelName() . "\0" . mb_strtolower($text));
		$cached = $cache->get($key);
		if (is_array($cached) && count($cached) > 0) {
			return $cached;
		}
		$proc = new Process([$this->clipModel->getPythonBinary(), dirname(__DIR__, 2) . '/src/clip_text.py', $text], dirname(__DIR__, 2), $this->clipModel->getEnvironment());
		$proc->setTimeout(120);
		$proc->run();
		if ($proc->getExitCode() !== 0) {
			throw new \RuntimeException('Text embedding failed: ' . mb_substr($proc->getErrorOutput(), -500));
		}
		$vector = json_decode(trim(explode("\n", trim($proc->getOutput()))[0] ?? ''), true);
		if (!is_array($vector) || count($vector) === 0) {
			throw new \RuntimeException('Text embedding returned no vector');
		}
		$vector = array_map('floatval', $vector);
		$cache->set($key, $vector, self::TEXT_CACHE_TTL);
		return $vector;
	}
}
