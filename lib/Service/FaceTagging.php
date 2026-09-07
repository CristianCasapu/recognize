<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\FaceCluster;
use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Manual tagging and review of people: faces of a photo, assign / detach / ignore a face,
 * unnamed clusters with name suggestions, rename, merge, ignore a cluster.
 *
 * cluster_id semantics: NULL = not clustered yet, -1 = clustered but unassigned, -2 = ignored
 * ("not a face" / "not a person"): never clustered again and hidden from the unassigned list.
 */
final class FaceTagging {
	public const UNASSIGNED = -1;
	public const IGNORED = -2;
	public const SAMPLE_FACES = 6;
	public const SUGGESTION_MAX_DISTANCE = 1.05;

	public function __construct(
		private IDBConnection $db,
		private FaceDetectionMapper $detections,
		private FaceClusterMapper $clusters,
		private FaceClusterMerger $merger,
		private Logger $logger,
	) {
	}

	/**
	 * Faces detected in one photo, with the person they are assigned to.
	 *
	 * @return list<array{id:int, x:float, y:float, width:float, height:float, cluster_id:?int, title:?string, ignored:bool}>
	 */
	public function listFaces(string $userId, int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('d.id', 'd.x', 'd.y', 'd.width', 'd.height', 'd.cluster_id', 'c.title')
			->from('recognize_face_detections', 'd')
			->leftJoin('d', 'recognize_face_clusters', 'c', $qb->expr()->eq('d.cluster_id', 'c.id'))
			->where($qb->expr()->eq('d.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('d.file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('d.width', 'DESC');
		$faces = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$clusterId = $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null;
			$faces[] = [
				'id' => (int)$row['id'],
				'x' => (float)$row['x'],
				'y' => (float)$row['y'],
				'width' => (float)$row['width'],
				'height' => (float)$row['height'],
				'cluster_id' => $clusterId !== null && $clusterId > 0 ? $clusterId : null,
				'title' => $clusterId !== null && $clusterId > 0 ? (string)($row['title'] ?? '') : null,
				'ignored' => $clusterId === self::IGNORED,
			];
		}
		return $faces;
	}

	/**
	 * Assign a face to a person: an existing cluster (by id) or a name (existing person or a new one).
	 * A person cannot appear twice in one photo.
	 *
	 * @return array{cluster_id:int, title:string, created:bool}
	 * @throws \InvalidArgumentException when the person is already in the photo or the input is invalid
	 */
	public function assign(string $userId, int $detectionId, ?int $clusterId, ?string $title): array {
		$detection = $this->ownDetection($userId, $detectionId);
		$created = false;
		if ($clusterId !== null && $clusterId > 0) {
			$target = $this->ownCluster($userId, $clusterId);
		} else {
			$title = trim((string)$title);
			if ($title === '') {
				throw new \InvalidArgumentException('A person or a name is required');
			}
			try {
				/** @var FaceCluster $target */
				$target = $this->clusters->findByUserAndTitle($userId, $title);
			} catch (DoesNotExistException $e) {
				$target = new FaceCluster();
				$target->setTitle($title);
				$target->setUserId($userId);
				$target = $this->clusters->insert($target);
				$created = true;
			}
		}
		if ($detection->getClusterId() === $target->getId()) {
			return ['cluster_id' => $target->getId(), 'title' => $target->getTitle(), 'created' => false];
		}
		$this->assertPersonNotInPhoto($detection->getFileId(), $target->getId(), $detection->getId());

		$old = $detection->getClusterId();
		$detection->setClusterId($target->getId());
		$this->detections->update($detection);
		$this->dropIfEmpty($old);
		$this->logger->info('Face #' . $detectionId . ' assigned to "' . $target->getTitle() . '" (#' . $target->getId() . ') by ' . $userId);
		return ['cluster_id' => $target->getId(), 'title' => $target->getTitle(), 'created' => $created];
	}

	/** Remove a face from its person (it stays available for tagging and re-clustering). */
	public function detach(string $userId, int $detectionId): void {
		$detection = $this->ownDetection($userId, $detectionId);
		$old = $detection->getClusterId();
		$detection->setClusterId(self::UNASSIGNED);
		$this->detections->update($detection);
		$this->dropIfEmpty($old);
	}

	/** "Not a face": hide it for good. */
	public function ignoreDetection(string $userId, int $detectionId): void {
		$detection = $this->ownDetection($userId, $detectionId);
		$old = $detection->getClusterId();
		$detection->setClusterId(self::IGNORED);
		$this->detections->update($detection);
		$this->dropIfEmpty($old);
	}

	/**
	 * Unnamed people, biggest first, with sample faces and a "might be …" suggestion.
	 *
	 * @return array{people: list<array<string, mixed>>, named: list<array{cluster_id:int, title:string, count:int}>, unassigned:int, ignored:int}
	 */
	public function review(string $userId, int $limit = 200, int $minSize = 2): array {
		$counts = $this->countsByCluster($userId);
		$named = [];
		$unnamed = [];
		foreach ($this->clusters->findByUserId($userId) as $cluster) {
			$count = $counts[$cluster->getId()] ?? 0;
			if ($cluster->getTitle() !== '') {
				$named[] = ['cluster_id' => $cluster->getId(), 'title' => $cluster->getTitle(), 'count' => $count];
			} elseif ($count >= $minSize) {
				$unnamed[] = ['cluster_id' => $cluster->getId(), 'count' => $count];
			}
		}
		usort($named, static fn ($a, $b) => strcasecmp($a['title'], $b['title']));
		usort($unnamed, static fn ($a, $b) => $b['count'] <=> $a['count']);
		$unnamed = array_slice($unnamed, 0, $limit);

		$suggestions = [];
		if (count($named) > 0 && count($unnamed) > 0) {
			try {
				$threshold = $this->merger->getConfiguredThreshold();
				if ($threshold <= 0) {
					$threshold = $this->merger->getDefaultThreshold();
				}
				foreach ($this->merger->findCandidates($userId, $threshold) as $candidate) {
					$suggestions[$candidate['clusterId']] = $candidate;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Face review: suggestions failed: ' . $e->getMessage());
			}
		}

		$people = [];
		foreach ($unnamed as $entry) {
			$samples = [];
			foreach ($this->detections->findByClusterIdLimited($entry['cluster_id'], self::SAMPLE_FACES) as $detection) {
				$samples[] = [
					'id' => $detection->getId(),
					'file_id' => $detection->getFileId(),
					'x' => $detection->getX(),
					'y' => $detection->getY(),
					'width' => $detection->getWidth(),
					'height' => $detection->getHeight(),
				];
			}
			$suggestion = null;
			$s = $suggestions[$entry['cluster_id']] ?? null;
			if ($s !== null && $s['distance'] <= self::SUGGESTION_MAX_DISTANCE) {
				$suggestion = [
					'cluster_id' => $s['targetId'],
					'title' => $s['targetTitle'],
					'distance' => $s['distance'],
					'shared_files' => $s['sharedFiles'],
					'second_title' => $s['secondTitle'],
					'second_distance' => $s['secondDistance'],
					'confident' => $s['mergeable'],
				];
			}
			$people[] = [
				'cluster_id' => $entry['cluster_id'],
				'count' => $entry['count'],
				'faces' => $samples,
				'suggestion' => $suggestion,
			];
		}

		return [
			'people' => $people,
			'named' => $named,
			'unassigned' => $this->countWithClusterId($userId, self::UNASSIGNED),
			'ignored' => $this->countWithClusterId($userId, self::IGNORED),
		];
	}

	/**
	 * Name a person. If another person already has that name, the two are merged.
	 *
	 * @return array{cluster_id:int, title:string, merged:bool, moved:int}
	 */
	public function rename(string $userId, int $clusterId, string $title): array {
		$title = trim($title);
		if ($title === '') {
			throw new \InvalidArgumentException('The name must not be empty');
		}
		$cluster = $this->ownCluster($userId, $clusterId);
		try {
			/** @var FaceCluster $existing */
			$existing = $this->clusters->findByUserAndTitle($userId, $title);
		} catch (DoesNotExistException $e) {
			$existing = null;
		}
		if ($existing !== null && $existing->getId() !== $cluster->getId()) {
			$moved = $this->mergeInto($userId, $cluster->getId(), $existing->getId());
			return ['cluster_id' => $existing->getId(), 'title' => $existing->getTitle(), 'merged' => true, 'moved' => $moved];
		}
		$cluster->setTitle($title);
		$this->clusters->update($cluster);
		return ['cluster_id' => $cluster->getId(), 'title' => $title, 'merged' => false, 'moved' => 0];
	}

	/**
	 * Move every face of a cluster into another one and delete the source. Where both appeared
	 * in the same photo, the smaller face is unassigned (a person cannot appear twice).
	 *
	 * @return int number of faces moved
	 */
	public function mergeInto(string $userId, int $clusterId, int $targetId): int {
		if ($clusterId === $targetId) {
			return 0;
		}
		$source = $this->ownCluster($userId, $clusterId);
		$target = $this->ownCluster($userId, $targetId);
		if ($source->getTitle() !== '' && $target->getTitle() === '') {
			// keep the name when merging "the wrong way round"
			$target->setTitle($source->getTitle());
			$source->setTitle('');
			$this->clusters->update($source);
			$this->clusters->update($target);
		}
		$moved = $this->detections->moveToCluster($source->getId(), $target->getId());
		$this->clusters->delete($source);
		$this->resolveDuplicates($target->getId());
		$this->logger->info('Merged face cluster #' . $clusterId . ' (' . $moved . ' faces) into #' . $targetId . ' by ' . $userId);
		return $moved;
	}

	/** "Not a person" (statues, posters, screens …): hide the whole cluster for good. */
	public function ignoreCluster(string $userId, int $clusterId): int {
		$cluster = $this->ownCluster($userId, $clusterId);
		$n = $this->detections->moveToCluster($cluster->getId(), self::IGNORED);
		$this->clusters->delete($cluster);
		return $n;
	}

	/** Undo "ignore" for a photo: every ignored face of the file becomes unassigned again. */
	public function unignoreFile(string $userId, int $fileId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('recognize_face_detections')
			->set('cluster_id', $qb->createNamedParameter(self::UNASSIGNED, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter(self::IGNORED, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/** @throws \InvalidArgumentException */
	private function assertPersonNotInPhoto(int $fileId, int $clusterId, int $exceptDetectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('recognize_face_detections')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptDetectionId, IQueryBuilder::PARAM_INT)));
		if ((int)$qb->executeQuery()->fetchOne() > 0) {
			throw new \InvalidArgumentException('This person is already tagged in the photo (a person cannot appear twice)');
		}
	}

	/** After a merge: keep the largest face per photo, unassign the others. */
	private function resolveDuplicates(int $clusterId): void {
		$detections = $this->detections->findByClusterId($clusterId);
		$byFile = [];
		foreach ($detections as $detection) {
			$byFile[$detection->getFileId()][] = $detection;
		}
		foreach ($byFile as $list) {
			if (count($list) < 2) {
				continue;
			}
			usort($list, static fn (FaceDetection $a, FaceDetection $b) => ($b->getWidth() * $b->getHeight()) <=> ($a->getWidth() * $a->getHeight()));
			foreach (array_slice($list, 1) as $extra) {
				$extra->setClusterId(self::UNASSIGNED);
				$this->detections->update($extra);
			}
		}
	}

	private function dropIfEmpty(?int $clusterId): void {
		if ($clusterId === null || $clusterId <= 0) {
			return;
		}
		if ($this->detections->countByClusterId($clusterId) === 0) {
			try {
				$this->clusters->delete($this->clusters->find($clusterId));
			} catch (DoesNotExistException $e) {
			}
		}
	}

	/** @return array<int,int> cluster id => number of faces */
	private function countsByCluster(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cluster_id')
			->selectAlias($qb->func()->count('id'), 'n')
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gt('cluster_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('cluster_id');
		$counts = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$counts[(int)$row['cluster_id']] = (int)$row['n'];
		}
		return $counts;
	}

	private function countWithClusterId(string $userId, int $clusterId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('recognize_face_detections')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('cluster_id', $qb->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	private function ownDetection(string $userId, int $detectionId): FaceDetection {
		$detection = $this->detections->find($detectionId);
		if ($detection->getUserId() !== $userId) {
			throw new DoesNotExistException('Unknown face');
		}
		return $detection;
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	private function ownCluster(string $userId, int $clusterId): FaceCluster {
		$cluster = $this->clusters->find($clusterId);
		if ($cluster->getUserId() !== $userId) {
			throw new DoesNotExistException('Unknown person');
		}
		return $cluster;
	}
}
