<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Preserves the person names users assigned to face clusters across a full re-detection
 * (e.g. when switching the face backend, whose embeddings are incompatible).
 *
 * A snapshot stores (user, file, box, title) for every detection of a named cluster. After the
 * faces have been detected and clustered again, every new detection that overlaps a snapshot
 * face votes for that title; each title is given to the single new cluster with the most votes.
 */
final class FaceNameSnapshot {
	public const PENDING_SETTING = 'faces.pendingNameSnapshot';
	public const MATCH_IOU = 0.4;
	public const MIN_VOTES = 2;
	public const MIN_VOTE_SHARE = 0.5;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private SettingsService $settingsService,
		private FaceClusterMapper $faceClusters,
		private FaceDetectionMapper $faceDetections,
		private Logger $logger,
	) {
	}

	public function getDirectory(): string {
		return rtrim($this->config->getSystemValueString('datadirectory'), '/') . '/appdata_' . $this->config->getSystemValueString('instanceid') . '/recognize';
	}

	/**
	 * Write the snapshot file and mark it as pending.
	 *
	 * @return array{path:string, faces:int, titles:int}
	 * @throws \OCP\DB\Exception
	 */
	public function create(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.user_id', 'd.file_id', 'd.x', 'd.y', 'd.width', 'd.height', 'c.title')
			->from('recognize_face_detections', 'd')
			->innerJoin('d', 'recognize_face_clusters', 'c', $qb->expr()->eq('d.cluster_id', 'c.id'))
			->where($qb->expr()->neq('c.title', $qb->createPositionalParameter('')));
		$result = $qb->executeQuery();
		$faces = [];
		$titles = [];
		while ($row = $result->fetch()) {
			$faces[] = [
				'user' => (string)$row['user_id'],
				'file' => (int)$row['file_id'],
				'box' => [(float)$row['x'], (float)$row['y'], (float)$row['width'], (float)$row['height']],
				'title' => (string)$row['title'],
			];
			$titles[(string)$row['title']] = true;
		}
		$result->closeCursor();

		$dir = $this->getDirectory();
		if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
			throw new \RuntimeException('Could not create ' . $dir);
		}
		$path = $dir . '/face-names-' . date('Ymd-His') . '.json';
		file_put_contents($path, json_encode(['createdAt' => time(), 'faces' => $faces], JSON_THROW_ON_ERROR));
		$this->settingsService->setRawSetting(self::PENDING_SETTING, $path);
		return ['path' => $path, 'faces' => count($faces), 'titles' => count($titles)];
	}

	public function getPendingPath(): ?string {
		$path = $this->settingsService->getRawSetting(self::PENDING_SETTING, '');
		return $path !== '' && is_file($path) ? $path : null;
	}

	public function clearPending(): void {
		$this->settingsService->setRawSetting(self::PENDING_SETTING, '');
	}

	/**
	 * Apply the pending snapshot, if any (called after every clustering run; idempotent).
	 *
	 * @return array<string,string> title => cluster id that got named in this run
	 */
	public function restorePending(): array {
		$path = $this->getPendingPath();
		if ($path === null) {
			return [];
		}
		try {
			return $this->restore($path);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not restore face names from ' . $path, ['exception' => $e]);
			return [];
		}
	}

	/**
	 * @return array<string,string> title => cluster id named in this run
	 * @throws \OCP\DB\Exception|\JsonException
	 */
	public function restore(string $path): array {
		$data = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
		$faces = $data['faces'] ?? [];
		if (count($faces) === 0) {
			return [];
		}

		// group snapshot faces by file so each file's new detections are loaded once
		$byFile = [];
		foreach ($faces as $face) {
			$byFile[(int)$face['file']][] = $face;
		}
		/** @var array<string, array<int,int>> $votes title => [clusterId => votes] */
		$votes = [];
		/** @var array<string,int> $matched title => matched faces */
		$matched = [];
		foreach ($byFile as $fileId => $snapshotFaces) {
			$detections = $this->faceDetections->findByFileId($fileId);
			foreach ($snapshotFaces as $face) {
				foreach ($detections as $detection) {
					if ($detection->getUserId() !== $face['user'] || $detection->getClusterId() === null || $detection->getClusterId() < 0) {
						continue;
					}
					if (self::iou($face['box'], [$detection->getX(), $detection->getY(), $detection->getWidth(), $detection->getHeight()]) < self::MATCH_IOU) {
						continue;
					}
					$title = $face['title'];
					$votes[$title][$detection->getClusterId()] = ($votes[$title][$detection->getClusterId()] ?? 0) + 1;
					$matched[$title] = ($matched[$title] ?? 0) + 1;
					break;
				}
			}
		}

		// Give each title to the cluster with the most votes, best titles first so that a cluster
		// is never renamed by a weaker candidate
		$candidates = [];
		foreach ($votes as $title => $clusters) {
			arsort($clusters);
			$clusterId = (int)array_key_first($clusters);
			$count = $clusters[$clusterId];
			if ($count < self::MIN_VOTES || $count < self::MIN_VOTE_SHARE * $matched[$title]) {
				continue;
			}
			$candidates[] = ['title' => $title, 'clusterId' => $clusterId, 'votes' => $count];
		}
		usort($candidates, static fn ($a, $b) => $b['votes'] <=> $a['votes']);

		$named = [];
		$taken = [];
		foreach ($candidates as $candidate) {
			if (isset($taken[$candidate['clusterId']])) {
				continue;
			}
			$cluster = $this->faceClusters->find($candidate['clusterId']);
			if ($cluster->getTitle() === $candidate['title']) {
				$taken[$candidate['clusterId']] = true;
				continue;
			}
			if ($cluster->getTitle() !== '') {
				$this->logger->info('Not restoring name "' . $candidate['title'] . '": cluster #' . $candidate['clusterId'] . ' is already named "' . $cluster->getTitle() . '"');
				continue;
			}
			$cluster->setTitle($candidate['title']);
			$this->faceClusters->update($cluster);
			$taken[$candidate['clusterId']] = true;
			$named[$candidate['title']] = (string)$candidate['clusterId'];
			$this->logger->info('Restored name "' . $candidate['title'] . '" on cluster #' . $candidate['clusterId'] . ' (' . $candidate['votes'] . ' matching faces)');
		}
		return $named;
	}

	/**
	 * @param array{0:float,1:float,2:float,3:float} $a
	 * @param array{0:float,1:float,2:float,3:float} $b
	 */
	private static function iou(array $a, array $b): float {
		$x0 = max($a[0], $b[0]);
		$y0 = max($a[1], $b[1]);
		$x1 = min($a[0] + $a[2], $b[0] + $b[2]);
		$y1 = min($a[1] + $a[3], $b[1] + $b[3]);
		$intersection = max(0.0, $x1 - $x0) * max(0.0, $y1 - $y0);
		$union = $a[2] * $a[3] + $b[2] * $b[3] - $intersection;
		return $union > 0 ? $intersection / $union : 0.0;
	}
}
