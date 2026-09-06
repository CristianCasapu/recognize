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
use OCA\Recognize\Db\FaceDetectionMapper;

/**
 * Merges unnamed face clusters into named ones when their centroids are close enough.
 *
 * HDBSCAN never merges two clusters that already exist in the database (existing
 * clusters only "vote" for newly clustered detections), so the same person that
 * was clustered in two batches ends up as one named and one unnamed cluster. This
 * service closes that gap conservatively: an unnamed cluster is merged only into a
 * *named* cluster, only when the centroid distance is below the threshold, and only
 * when no other named cluster is almost as close (ambiguity margin).
 */
final class FaceClusterMerger {
	/** Same person clusters typically sit at < 0.4, distinct people at > 0.45 (128-d face-api descriptors) */
	public const DEFAULT_THRESHOLD = 0.4;
	/** The second-closest named cluster must be at least this much farther away than the closest one */
	public const AMBIGUITY_MARGIN = 0.08;
	public const SAMPLE_SIZE = 60;
	public const MIN_CLUSTER_SIZE = 2;

	private ?Euclidean $distance = null;

	public function __construct(
		private FaceDetectionMapper $faceDetections,
		private FaceClusterMapper $faceClusters,
		private Logger $logger,
		private SettingsService $settingsService,
	) {
	}

	public function getConfiguredThreshold(): float {
		return (float)$this->settingsService->getSetting('faces.autoMergeThreshold');
	}

	/**
	 * Find unnamed clusters that could be merged into a named cluster.
	 *
	 * @return list<array{clusterId:int, size:int, targetId:int, targetTitle:string, distance:float, secondDistance:?float, secondTitle:?string, mergeable:bool}>
	 * @throws \OCP\DB\Exception
	 */
	public function findCandidates(string $userId, float $threshold): array {
		$clusters = $this->faceClusters->findByUserId($userId);
		$named = [];
		$unnamed = [];
		foreach ($clusters as $cluster) {
			if ($cluster->getTitle() !== '') {
				$named[] = $cluster;
			} else {
				$unnamed[] = $cluster;
			}
		}
		if (count($named) === 0 || count($unnamed) === 0) {
			return [];
		}

		$namedCentroids = [];
		foreach ($named as $cluster) {
			$centroid = $this->getCentroid($cluster);
			if ($centroid !== null) {
				$namedCentroids[$cluster->getId()] = ['title' => $cluster->getTitle(), 'centroid' => $centroid];
			}
		}

		$candidates = [];
		foreach ($unnamed as $cluster) {
			$sample = $this->faceDetections->findClusterSample($cluster->getId(), self::SAMPLE_SIZE);
			if (count($sample) < self::MIN_CLUSTER_SIZE) {
				continue;
			}
			$centroid = FaceClusterAnalyzer::calculateCentroidOfDetections($sample);
			$distances = [];
			foreach ($namedCentroids as $targetId => $target) {
				$distances[] = ['id' => $targetId, 'title' => $target['title'], 'distance' => $this->distance($centroid, $target['centroid'])];
			}
			usort($distances, static fn ($a, $b) => $a['distance'] <=> $b['distance']);
			$best = $distances[0];
			$second = $distances[1] ?? null;
			$mergeable = $best['distance'] < $threshold
				&& ($second === null || $second['distance'] - $best['distance'] >= self::AMBIGUITY_MARGIN);
			$candidates[] = [
				'clusterId' => $cluster->getId(),
				'size' => $this->faceDetections->countByClusterId($cluster->getId()),
				'targetId' => $best['id'],
				'targetTitle' => $best['title'],
				'distance' => round($best['distance'], 3),
				'secondDistance' => $second !== null ? round($second['distance'], 3) : null,
				'secondTitle' => $second !== null ? $second['title'] : null,
				'mergeable' => $mergeable,
			];
		}
		usort($candidates, static fn ($a, $b) => $a['distance'] <=> $b['distance']);
		return $candidates;
	}

	/**
	 * Merge all mergeable candidates of a user.
	 *
	 * @return list<array{clusterId:int, size:int, targetId:int, targetTitle:string, distance:float}>
	 * @throws \OCP\DB\Exception
	 */
	public function merge(string $userId, ?float $threshold = null): array {
		$threshold ??= $this->getConfiguredThreshold();
		if ($threshold <= 0) {
			return [];
		}
		$merged = [];
		foreach ($this->findCandidates($userId, $threshold) as $candidate) {
			if (!$candidate['mergeable']) {
				continue;
			}
			$moved = $this->faceDetections->moveToCluster($candidate['clusterId'], $candidate['targetId']);
			$this->faceClusters->delete($this->faceClusters->find($candidate['clusterId']));
			$this->logger->info('Merged unnamed face cluster #' . $candidate['clusterId'] . ' (' . $moved . ' faces) into "' . $candidate['targetTitle'] . '" (#' . $candidate['targetId'] . '), centroid distance ' . $candidate['distance']);
			$merged[] = [
				'clusterId' => $candidate['clusterId'],
				'size' => $moved,
				'targetId' => $candidate['targetId'],
				'targetTitle' => $candidate['targetTitle'],
				'distance' => $candidate['distance'],
			];
		}
		return $merged;
	}

	/**
	 * @return list<float>|null
	 * @throws \OCP\DB\Exception
	 */
	private function getCentroid(FaceCluster $cluster): ?array {
		$sample = $this->faceDetections->findClusterSample($cluster->getId(), self::SAMPLE_SIZE);
		if (count($sample) === 0) {
			return null;
		}
		return FaceClusterAnalyzer::calculateCentroidOfDetections($sample);
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
