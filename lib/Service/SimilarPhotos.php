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
	public const HASH_LOOSE = 12;
	public const CLIP_MIN = 0.95;
	/** near-duplicates (bursts) must have been taken within this many seconds of each other */
	public const LOOSE_MAX_TIME_DIFF = 24 * 3600;
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
		$rows = $this->embeddings->findPhashesWithMtime();
		ksort($rows);
		$ints = [];
		$mtimes = [];
		foreach ($rows as $fileId => $row) {
			$hex = $row['phash'];
			if (strlen($hex) !== 16 || !ctype_xdigit($hex)) {
				continue;
			}
			$ints[$fileId] = unpack('J', hex2bin($hex))[1];
			$mtimes[$fileId] = $row['mtime'];
		}
		$fileIds = array_keys($ints);
		$n = count($fileIds);
		$values = array_values($ints);
		$table = self::popcountTable();

		// Every file is compared with the *representative* (first member) of each existing group and joins
		// the first one that matches; comparing with any member would chain a whole photo series
		// (A~B, B~C, C~D …) into one group although A and D look nothing alike.
		/** @var list<int> $representatives index into $fileIds */
		$representatives = [];
		/** @var array<int, list<int>> $members representative index => file ids */
		$members = [];
		$vectors = [];
		$vector = function (int $fileId) use (&$vectors): ?array {
			if (!array_key_exists($fileId, $vectors)) {
				$entity = $this->embeddings->findByFileId($fileId);
				$vectors[$fileId] = $entity !== null ? $entity->getFloats() : null;
			}
			return $vectors[$fileId];
		};

		$pairs = 0;
		for ($j = 0; $j < $n; $j++) {
			$b = $values[$j];
			$joined = false;
			foreach ($representatives as $i) {
				// Hamming distance of the two 64-bit hashes with a 16-bit lookup table (hot loop)
				$x = $values[$i] ^ $b;
				$distance = $table[$x & 0xffff] + $table[($x >> 16) & 0xffff] + $table[($x >> 32) & 0xffff] + $table[($x >> 48) & 0xffff];
				if ($distance > self::HASH_LOOSE) {
					continue;
				}
				if ($distance > self::HASH_STRICT) {
					// near-duplicate: same shooting session and (almost) identical content for CLIP
					if (abs($mtimes[$fileIds[$i]] - $mtimes[$fileIds[$j]]) > self::LOOSE_MAX_TIME_DIFF) {
						continue;
					}
					$va = $vector($fileIds[$i]);
					$vb = $vector($fileIds[$j]);
					if ($va === null || $vb === null || self::dot($va, $vb) < self::CLIP_MIN) {
						continue;
					}
				}
				$members[$i][] = $fileIds[$j];
				$joined = true;
				$pairs++;
				break;
			}
			if (!$joined) {
				$representatives[] = $j;
				$members[$j] = [$fileIds[$j]];
			}
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

	/**
	 * @return list<int> number of set bits for every 16-bit value
	 */
	private static function popcountTable(): array {
		static $table = null;
		if ($table === null) {
			$table = [0];
			for ($i = 1; $i < 65536; $i++) {
				$table[$i] = $table[$i >> 1] + ($i & 1);
			}
		}
		return $table;
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
