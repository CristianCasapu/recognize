<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\IDBConnection;
use Rubix\ML\Kernels\Distance\Euclidean;

/**
 * Face tracking across bursts: photos of the same folder taken a few seconds apart show the
 * same people in (almost) the same place. An unassigned face — including the small ones that
 * are never clustered — whose box overlaps the box of a known person in a neighbouring frame
 * inherits that person, provided the embeddings are plausibly the same and the person is not
 * already in the photo.
 */
final class FaceTracker {
	/** seconds between two frames of the same burst */
	public const MAX_TIME_DIFF = 15;
	public const MIN_IOU = 0.4;
	/** loose: small faces have poor embeddings; the box overlap is the main evidence */
	public const MAX_DISTANCE = 1.0;
	public const MAX_PASSES = 4;

	private ?Euclidean $euclidean = null;

	public function __construct(
		private IDBConnection $db,
		private FaceDetectionMapper $detections,
		private Logger $logger,
	) {
	}

	/**
	 * @return int number of faces assigned
	 */
	public function track(string $userId, ?callable $progress = null): int {
		$rows = $this->loadDetections($userId);
		if (count($rows) === 0) {
			return 0;
		}

		// per folder: files ordered by time
		$folders = [];
		$byFile = [];
		foreach ($rows as $row) {
			$byFile[$row['file_id']][] = $row;
			$folders[$row['parent']][$row['file_id']] = $row['time'];
		}
		$assignedTotal = 0;
		$vectors = [];
		for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
			$assigned = 0;
			foreach ($folders as $files) {
				asort($files);
				$fileIds = array_keys($files);
				$times = array_values($files);
				$n = count($fileIds);
				for ($i = 0; $i < $n; $i++) {
					$fileId = $fileIds[$i];
					$present = [];
					foreach ($byFile[$fileId] as $d) {
						if ($d['cluster_id'] > 0) {
							$present[$d['cluster_id']] = true;
						}
					}
					foreach ($byFile[$fileId] as $k => $d) {
						if ($d['cluster_id'] !== null && $d['cluster_id'] !== -1) {
							continue; // assigned or ignored
						}
						$best = null;
						$bestIou = self::MIN_IOU;
						for ($j = max(0, $i - 20); $j < min($n, $i + 21); $j++) {
							if ($j === $i || abs($times[$j] - $times[$i]) > self::MAX_TIME_DIFF) {
								continue;
							}
							foreach ($byFile[$fileIds[$j]] as $o) {
								if ($o['cluster_id'] <= 0 || isset($present[$o['cluster_id']])) {
									continue;
								}
								$iou = self::iou($d, $o);
								if ($iou > $bestIou) {
									$bestIou = $iou;
									$best = $o;
								}
							}
						}
						if ($best === null) {
							continue;
						}
						$distance = $this->distance($d['id'], $best['id'], $vectors);
						if ($distance === null || $distance > self::MAX_DISTANCE) {
							continue;
						}
						$detection = $this->detections->find($d['id']);
						$detection->setClusterId($best['cluster_id']);
						$this->detections->update($detection);
						$byFile[$fileId][$k]['cluster_id'] = $best['cluster_id'];
						$present[$best['cluster_id']] = true;
						$assigned++;
					}
				}
			}
			$assignedTotal += $assigned;
			if ($progress !== null) {
				$progress($pass, $assigned);
			}
			if ($assigned === 0) {
				break;
			}
		}
		if ($assignedTotal > 0) {
			$this->logger->info('Face tracking: ' . $assignedTotal . ' faces assigned from neighbouring frames for ' . $userId);
		}
		return $assignedTotal;
	}

	/**
	 * @return list<array{id:int, file_id:int, x:float, y:float, width:float, height:float, cluster_id:?int, parent:int, time:int}>
	 */
	private function loadDetections(string $userId): array {
		$hasMemories = $this->db->tableExists('memories');
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.id', 'd.file_id', 'd.x', 'd.y', 'd.width', 'd.height', 'd.cluster_id', 'f.parent', 'f.mtime')
			->from('recognize_face_detections', 'd')
			->innerJoin('d', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'd.file_id'))
			->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)));
		if ($hasMemories) {
			$qb->addSelect('m.datetaken')->leftJoin('d', 'memories', 'm', $qb->expr()->eq('m.fileid', 'd.file_id'));
		}
		$rows = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$time = (int)$row['mtime'];
			if ($hasMemories && !empty($row['datetaken'])) {
				$t = strtotime((string)$row['datetaken']);
				if ($t !== false) {
					$time = $t;
				}
			}
			$rows[] = [
				'id' => (int)$row['id'],
				'file_id' => (int)$row['file_id'],
				'x' => (float)$row['x'],
				'y' => (float)$row['y'],
				'width' => (float)$row['width'],
				'height' => (float)$row['height'],
				'cluster_id' => $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null,
				'parent' => (int)$row['parent'],
				'time' => $time,
			];
		}
		return $rows;
	}

	/** @param array<string,float> $a @param array<string,float> $b */
	private static function iou(array $a, array $b): float {
		$x1 = max($a['x'], $b['x']);
		$y1 = max($a['y'], $b['y']);
		$x2 = min($a['x'] + $a['width'], $b['x'] + $b['width']);
		$y2 = min($a['y'] + $a['height'], $b['y'] + $b['height']);
		$inter = max(0.0, $x2 - $x1) * max(0.0, $y2 - $y1);
		$union = $a['width'] * $a['height'] + $b['width'] * $b['height'] - $inter;
		return $union > 0 ? $inter / $union : 0.0;
	}

	/** @param array<int, list<float>|null> $cache */
	private function distance(int $idA, int $idB, array &$cache): ?float {
		foreach ([$idA, $idB] as $id) {
			if (!array_key_exists($id, $cache)) {
				try {
					$vector = $this->detections->find($id)->getFaceVector();
					$cache[$id] = is_array($vector) && count($vector) > 0 ? array_map('floatval', $vector) : null;
				} catch (\Throwable $e) {
					$cache[$id] = null;
				}
			}
		}
		if ($cache[$idA] === null || $cache[$idB] === null || count($cache[$idA]) !== count($cache[$idB])) {
			return null;
		}
		$this->euclidean ??= new Euclidean();
		return $this->euclidean->compute($cache[$idA], $cache[$idB]);
	}
}
