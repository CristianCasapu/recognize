<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Live progress of face scanning, for the admin page, the Memories People page and
 * `occ recognize:status`: a session starts at a reset / backend switch or after an hour
 * of inactivity; every scanned photo is counted with a heartbeat.
 */
final class FaceProgress {
	public const SETTING = 'faces.progress';
	/** no heartbeat for this long = not running */
	public const RUNNING_WINDOW = 180;
	/** a pause longer than this starts a new session */
	public const SESSION_GAP = 3600;

	public function __construct(
		private SettingsService $settings,
		private IDBConnection $db,
		private FaceDetectionMapper $detections,
		private FaceClusterMapper $clusters,
	) {
	}

	/** One photo has been scanned (from the classifier loop). */
	public function tick(int $faces): void {
		$now = time();
		$p = $this->read();
		if ($p['startedAt'] === 0 || $now - $p['lastActivity'] > self::SESSION_GAP) {
			$p = ['startedAt' => $now, 'lastActivity' => $now, 'scanned' => 0, 'faces' => 0];
		}
		$p['scanned']++;
		$p['faces'] += $faces;
		$p['lastActivity'] = $now;
		$this->write($p);
	}

	/** Start a new session (reset, backend switch, full rescan). */
	public function reset(): void {
		$this->write(['startedAt' => time(), 'lastActivity' => time(), 'scanned' => 0, 'faces' => 0]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$p = $this->read();
		$now = time();
		$running = $p['lastActivity'] > 0 && $now - $p['lastActivity'] <= self::RUNNING_WINDOW;
		$total = $this->countImages();
		$photosWithFaces = $this->countDistinctFiles();
		$elapsed = max(1, $p['lastActivity'] - $p['startedAt']);
		$rate = $p['scanned'] > 0 ? $p['scanned'] / $elapsed : 0.0; // photos per second
		$remaining = max(0, $total - $photosWithFaces - $this->countQueued() > 0 ? $total - max($photosWithFaces, $p['scanned']) : 0);
		return [
			'running' => $running,
			'startedAt' => $p['startedAt'],
			'lastActivity' => $p['lastActivity'],
			'secondsSinceActivity' => $p['lastActivity'] > 0 ? $now - $p['lastActivity'] : null,
			'scanned' => $p['scanned'],
			'facesInSession' => $p['faces'],
			'total' => $total,
			'percent' => $total > 0 ? (int)min(100, round(100 * $p['scanned'] / $total)) : null,
			'ratePerMinute' => (int)round($rate * 60),
			'etaSeconds' => $running && $rate > 0 ? (int)round($remaining / $rate) : null,
			'queued' => $this->countQueued(),
			'faces' => $this->countDetections(),
			'photosWithFaces' => $photosWithFaces,
			'waitingForClustering' => $this->countWithClusterId(null),
			'unassigned' => $this->countWithClusterId(-1),
			'clusters' => $this->countClusters(false),
			'named' => $this->countClusters(true),
			'lastClustering' => (int)$this->settings->getSetting('clusterFaces.lastRun'),
			'clusterJobScheduled' => $this->clusterJobScheduled(),
		];
	}

	/** @return array{startedAt:int, lastActivity:int, scanned:int, faces:int} */
	private function read(): array {
		$raw = json_decode($this->settings->getRawSetting(self::SETTING, ''), true);
		return [
			'startedAt' => (int)($raw['startedAt'] ?? 0),
			'lastActivity' => (int)($raw['lastActivity'] ?? 0),
			'scanned' => (int)($raw['scanned'] ?? 0),
			'faces' => (int)($raw['faces'] ?? 0),
		];
	}

	/** @param array{startedAt:int, lastActivity:int, scanned:int, faces:int} $p */
	private function write(array $p): void {
		$this->settings->setRawSetting(self::SETTING, json_encode($p));
	}

	/** Image files in the users' home folders (what a full scan goes through) */
	private function countImages(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('f.fileid'))
			->from('filecache', 'f')
			->innerJoin('f', 'mimetypes', 'm', $qb->expr()->eq('m.id', 'f.mimetype'))
			->innerJoin('f', 'storages', 's', $qb->expr()->eq('s.numeric_id', 'f.storage'))
			->where($qb->expr()->like('m.mimetype', $qb->createNamedParameter('image/%')))
			->andWhere($qb->expr()->like('f.path', $qb->createNamedParameter('files/%')))
			->andWhere($qb->expr()->like('s.id', $qb->createNamedParameter('home::%')));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countQueued(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('recognize_queue_faces');
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countDetections(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('recognize_face_detections');
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countDistinctFiles(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT file_id)'))->from('recognize_face_detections');
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countWithClusterId(?int $clusterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('recognize_face_detections');
		if ($clusterId === null) {
			$qb->where($qb->expr()->isNull('cluster_id'));
		} else {
			$qb->where($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function countClusters(bool $namedOnly): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('recognize_face_clusters');
		if ($namedOnly) {
			$qb->where($qb->expr()->neq('title', $qb->createNamedParameter('')));
		}
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function clusterJobScheduled(): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('jobs')
			->where($qb->expr()->like('class', $qb->createNamedParameter('%ClusterFacesJob%')));
		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}
