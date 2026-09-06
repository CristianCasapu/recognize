<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\ClipEmbeddingMapper;
use OCP\ICacheFactory;

/**
 * Groups of duplicate / near-duplicate photos (bursts, re-uploads, resized copies, screenshots
 * of the same picture), found with the perceptual hash and the CLIP embedding computed by the
 * clip classifier:
 *  - dHash Hamming distance <= HASH_STRICT  → same picture
 *  - HASH_STRICT < distance <= HASH_LOOSE and CLIP cosine >= CLIP_MIN → near-duplicate (burst)
 * Pairs are merged with union-find into groups. The result is cached for CACHE_TTL seconds.
 */
final class SimilarPhotos {
	public const HASH_STRICT = 6;
	public const HASH_LOOSE = 14;
	public const CLIP_MIN = 0.93;
	public const CACHE_TTL = 6 * 3600;
	public const CACHE_KEY = 'groups';

	public function __construct(
		private ClipEmbeddingMapper $embeddings,
		private ClipModel $clipModel,
		private ICacheFactory $cacheFactory,
		private Logger $logger,
	) {
	}

	/**
	 * @return list<array{id:string, files:list<int>, size:int}> largest groups first
	 * @throws \OCP\DB\Exception
	 */
	public function groups(bool $force = false): array {
		$cache = $this->cacheFactory->createDistributed('recognize_similar');
		if (!$force) {
			$cached = $cache->get(self::CACHE_KEY);
			if (is_array($cached)) {
				return $cached;
			}
		}
		$start = microtime(true);
		$hashes = $this->embeddings->findPhashes();
		$fileIds = array_keys($hashes);
		$ints = [];
		foreach ($hashes as $fileId => $hex) {
			if (strlen($hex) !== 16 || !ctype_xdigit($hex)) {
				continue;
			}
			$ints[$fileId] = unpack('J', hex2bin($hex))[1];
		}
		$fileIds = array_keys($ints);
		$n = count($fileIds);

		// union-find
		$parent = array_combine($fileIds, $fileIds);
		$find = static function (int $x) use (&$parent, &$find): int {
			while ($parent[$x] !== $x) {
				$parent[$x] = $parent[$parent[$x]];
				$x = $parent[$x];
			}
			return $x;
		};
		$vectors = [];
		$vector = function (int $fileId) use (&$vectors): ?array {
			if (!array_key_exists($fileId, $vectors)) {
				$entity = $this->embeddings->findByFileId($fileId);
				$vectors[$fileId] = $entity !== null ? $entity->getFloats() : null;
			}
			return $vectors[$fileId];
		};

		$pairs = 0;
		for ($i = 0; $i < $n; $i++) {
			$a = $ints[$fileIds[$i]];
			for ($j = $i + 1; $j < $n; $j++) {
				$distance = self::popcount($a ^ $ints[$fileIds[$j]]);
				if ($distance > self::HASH_LOOSE) {
					continue;
				}
				if ($distance > self::HASH_STRICT) {
					$va = $vector($fileIds[$i]);
					$vb = $vector($fileIds[$j]);
					if ($va === null || $vb === null || self::dot($va, $vb) < self::CLIP_MIN) {
						continue;
					}
				}
				$ra = $find($fileIds[$i]);
				$rb = $find($fileIds[$j]);
				if ($ra !== $rb) {
					$parent[$ra] = $rb;
					$pairs++;
				}
			}
		}

		$members = [];
		foreach ($fileIds as $fileId) {
			$members[$find($fileId)][] = $fileId;
		}
		$groups = [];
		foreach ($members as $files) {
			if (count($files) < 2) {
				continue;
			}
			sort($files);
			$groups[] = ['id' => substr(md5(implode(',', $files)), 0, 12), 'files' => $files, 'size' => count($files)];
		}
		usort($groups, static fn ($a, $b) => [$b['size'], $a['files'][0]] <=> [$a['size'], $b['files'][0]]);
		$this->logger->debug('Similar photos: ' . count($groups) . ' groups from ' . $n . ' hashes (' . $pairs . ' links) in ' . round(microtime(true) - $start, 2) . 's');
		$cache->set(self::CACHE_KEY, $groups, self::CACHE_TTL);
		return $groups;
	}

	/**
	 * @return array{id:string, files:list<int>, size:int}|null
	 * @throws \OCP\DB\Exception
	 */
	public function getGroup(string $id): ?array {
		foreach ($this->groups() as $group) {
			if ($group['id'] === $id) {
				return $group;
			}
		}
		return null;
	}

	public function invalidate(): void {
		$this->cacheFactory->createDistributed('recognize_similar')->remove(self::CACHE_KEY);
	}

	private static function popcount(int $x): int {
		// Kernighan; x may be negative (unsigned 64-bit bit pattern), loop ends after at most 64 steps
		$count = 0;
		while ($x !== 0) {
			$x &= $x - 1;
			$count++;
		}
		return $count;
	}

	/**
	 * @param list<float> $a
	 * @param list<float> $b
	 */
	private static function dot(array $a, array $b): float {
		$sum = 0.0;
		foreach ($a as $i => $x) {
			$sum += $x * ($b[$i] ?? 0.0);
		}
		return $sum;
	}
}
