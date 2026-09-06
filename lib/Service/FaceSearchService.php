<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use \OCA\Recognize\Vendor\Rubix\ML\Kernels\Distance\Euclidean;
use OCA\Recognize\Db\FaceCluster;
use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

/**
 * "Find this person in more photos": on demand (from the Memories/Photos person page),
 * pull every face that is close enough to a cluster into that cluster — unassigned faces,
 * rejected faces and faces sitting in *unnamed* clusters — optionally limited to a folder.
 *
 * Same rules as the background clustering: unambiguous centroid distance and never
 * two faces of one photo in the same cluster.
 */
final class FaceSearchService {
	private ?Euclidean $distance = null;

	public function __construct(
		private FaceDetectionMapper $faceDetections,
		private FaceClusterMapper $faceClusters,
		private FaceBackend $backend,
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private Logger $logger,
	) {
	}

	/**
	 * @return array{assigned:int, candidates:int, threshold:float}
	 * @throws \OCP\DB\Exception
	 */
	public function findMore(string $userId, FaceCluster $cluster, ?string $folderPath = null): array {
		$params = $this->backend->getParams();
		// face-api has no assignment threshold (not discriminative enough): fall back to its merge threshold
		$threshold = $params['assignThreshold'] > 0 ? $params['assignThreshold'] : $params['mergeThreshold'];
		$margin = $params['assignMargin'];

		$members = $this->faceDetections->findByClusterIdLimited($cluster->getId(), FaceClusterMerger::SAMPLE_SIZE);
		if (count($members) === 0) {
			return ['assigned' => 0, 'candidates' => 0, 'threshold' => $threshold];
		}
		$centroid = FaceClusterAnalyzer::calculateCentroidOfDetections($members);

		// centroids of the other *named* clusters, to keep assignments unambiguous
		$others = [];
		foreach ($this->faceClusters->findByUserId($userId) as $other) {
			if ($other->getId() === $cluster->getId() || $other->getTitle() === '') {
				continue;
			}
			$sample = $this->faceDetections->findByClusterIdLimited($other->getId(), FaceClusterMerger::SAMPLE_SIZE);
			if (count($sample) > 0) {
				$others[$other->getId()] = FaceClusterAnalyzer::calculateCentroidOfDetections($sample);
			}
		}

		$fileIds = $folderPath !== null ? $this->getFileIdsBelow($userId, $folderPath) : null;
		$candidates = $this->findCandidates($userId, $cluster->getId(), $fileIds);

		// photos that already contain this person
		$filesWithPerson = [];
		foreach ($this->faceDetections->findByClusterId($cluster->getId()) as $member) {
			$filesWithPerson[$member->getFileId()] = true;
		}

		$assigned = 0;
		$byDistance = [];
		foreach ($candidates as $candidate) {
			$distance = $this->distance($candidate->getVector(), $centroid);
			if ($distance >= $threshold) {
				continue;
			}
			if ($candidate->getThreshold() > 0.0 && $distance >= $candidate->getThreshold()) {
				continue; // the user moved this face away before
			}
			$second = INF;
			foreach ($others as $otherCentroid) {
				$second = min($second, $this->distance($candidate->getVector(), $otherCentroid));
			}
			if ($second - $distance < $margin) {
				continue;
			}
			$byDistance[] = [$distance, $candidate];
		}
		usort($byDistance, static fn ($a, $b) => $a[0] <=> $b[0]);
		foreach ($byDistance as [$distance, $candidate]) {
			if (isset($filesWithPerson[$candidate->getFileId()])) {
				continue; // a person cannot be twice in one photo
			}
			$this->faceDetections->assocWithCluster($candidate, $cluster);
			$filesWithPerson[$candidate->getFileId()] = true;
			$assigned++;
		}
		$this->logger->info('Find-more for cluster #' . $cluster->getId() . ' (' . $cluster->getTitle() . '): ' . $assigned . ' of ' . count($candidates) . ' candidate faces assigned' . ($folderPath !== null ? ' within ' . $folderPath : ''));
		return ['assigned' => $assigned, 'candidates' => count($candidates), 'threshold' => $threshold];
	}

	/**
	 * Faces of the user that are not in a named cluster (unassigned, rejected or in an unnamed cluster).
	 *
	 * @param list<int>|null $fileIds
	 * @return list<FaceDetection>
	 * @throws \OCP\DB\Exception
	 */
	private function findCandidates(string $userId, int $clusterId, ?array $fileIds): array {
		if ($fileIds !== null && count($fileIds) === 0) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select(array_map(static fn ($col) => 'd.' . $col, FaceDetection::$columns))
			->from('recognize_face_detections', 'd')
			->leftJoin('d', 'recognize_face_clusters', 'c', $qb->expr()->eq('d.cluster_id', 'c.id'))
			->where($qb->expr()->eq('d.user_id', $qb->createPositionalParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('d.cluster_id'),
				$qb->expr()->lt('d.cluster_id', $qb->createPositionalParameter(1, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('c.title', $qb->createPositionalParameter(''))
			))
			->andWhere($qb->expr()->neq('d.cluster_id', $qb->createPositionalParameter($clusterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('d.width', $qb->createPositionalParameter($this->faceDetections->getMinDetectionSize())))
			->andWhere($qb->expr()->gte('d.height', $qb->createPositionalParameter($this->faceDetections->getMinDetectionSize())));
		if ($fileIds !== null) {
			$qb->andWhere($qb->expr()->in('d.file_id', $qb->createPositionalParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		/** @var list<FaceDetection> $detections */
		$detections = $this->faceDetections->findEntitiesPublic($qb);
		return $detections;
	}

	/**
	 * File ids of the user's files below a folder path (relative to the user's files root).
	 *
	 * @return list<int>
	 */
	private function getFileIdsBelow(string $userId, string $folderPath): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$node = $userFolder->get('/' . ltrim($folderPath, '/'));
		$storageId = $node->getStorage()->getCache()->getNumericStorageId();
		$internal = rtrim($node->getInternalPath(), '/');
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')
			->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createPositionalParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('path', $qb->createPositionalParameter($this->db->escapeLikeParameter($internal) . '/%')));
		$result = $qb->executeQuery();
		/** @var list<int> $ids */
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @param list<float> $v1
	 * @param list<float> $v2
	 */
	private function distance(array $v1, array $v2): float {
		$this->distance ??= new Euclidean();
		return $this->distance->compute($v1, $v2);
	}
}
