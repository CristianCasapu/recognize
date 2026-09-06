<?php

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use \OCA\Recognize\Vendor\Rubix\ML\Datasets\Labeled;
use \OCA\Recognize\Vendor\Rubix\ML\Kernels\Distance\Euclidean;
use OCA\Recognize\Clustering\HDBSCAN;
use OCA\Recognize\Db\FaceCluster;
use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;

final class FaceClusterAnalyzer {
	public const MIN_DATASET_SIZE = 120;
	public const MIN_DETECTION_SIZE = 0.03;
	public const MIN_CLUSTER_SEPARATION = 0.35;
	public const MAX_CLUSTER_EDGE_LENGTH = 0.5;
	public const DIMENSIONS = 128;
	public const MAX_OVERLAP_NEW_CLUSTER = 0.1;
	public const MIN_OVERLAP_EXISTING_CLUSTER = 0.5;
	public const REFERENCE_SAMPLE_BUDGET_SHARE = 0.5;
	public const MIN_REFERENCE_SAMPLE_SIZE = 2;

	private FaceDetectionMapper $faceDetections;
	private FaceClusterMapper $faceClusters;
	private Logger $logger;
	private int $minDatasetSize = self::MIN_DATASET_SIZE;
	private SettingsService $settingsService;
	private FaceBackend $backend;

	public function __construct(FaceDetectionMapper $faceDetections, FaceClusterMapper $faceClusters, Logger $logger, SettingsService $settingsService, FaceBackend $backend) {
		$this->faceDetections = $faceDetections;
		$this->faceClusters = $faceClusters;
		$this->logger = $logger;
		$this->settingsService = $settingsService;
		$this->backend = $backend;
	}

	public function setMinDatasetSize(int $minSize) : void {
		$this->minDatasetSize = $minSize;
	}

	/**
	 * A batch size tuned for 128-d face-api vectors, scaled down for higher-dimensional embeddings
	 * so that the O(n²·d) HDBSCAN pass keeps roughly the same running time.
	 */
	public function scaleBatchSize(int $batchSize): int {
		$dimensions = $this->backend->getParams()['dimensions'];
		if ($batchSize <= 0 || $dimensions <= self::DIMENSIONS) {
			return $batchSize;
		}
		return max(500, (int)round($batchSize * self::DIMENSIONS / $dimensions));
	}

	/**
	 * Faces smaller than this fraction of the image are excluded from clustering,
	 * configurable via faces.minDetectionSize (MIN_DETECTION_SIZE when unset or invalid).
	 */
	public function getMinDetectionSize(): float {
		return $this->faceDetections->getMinDetectionSize();
	}

	/**
	 * @throws \OCP\DB\Exception
	 * @throws \JsonException
	 */
	public function calculateClusters(string $userId, int $batchSize = 0): void {
		$this->logger->debug('ClusterDebug: Retrieving face detections for user ' . $userId);

		if ($batchSize === 0) {
			ini_set('memory_limit', '-1');
		}


		$minDetectionSize = $this->getMinDetectionSize();
		$sampledDetections = [];
		$existingClusters = $this->faceClusters->findByUserId($userId);
		/** @var array<int,int> $maxVotesByCluster */
		$maxVotesByCluster = [];
		$referenceSampleSize = $this->getReferenceSampleSize(count($existingClusters), $batchSize);
		foreach ($existingClusters as $existingCluster) {
			$sampled = $this->faceDetections->findClusterSample($existingCluster->getId(), $referenceSampleSize);
			$sampledDetections = array_merge($sampledDetections, $sampled);
			$maxVotesByCluster[$existingCluster->getId()] = count($sampled);
		}

		// Every existing cluster must be represented, otherwise its faces would be clustered
		// into a duplicate cluster. If that alone exceeds the batch budget, we cannot honour it.
		if ($batchSize > 0 && count($sampledDetections) > $batchSize) {
			$this->logger->warning('ClusterDebug: The batch size of ' . $batchSize . ' detections is too small for the ' . count($existingClusters) . ' existing clusters of user ' . $userId . '; loading ' . count($sampledDetections) . ' reference detections instead. Consider raising the PHP memory limit.');
		}

		if ($batchSize > 0) {
			$rejectedDetections = $this->faceDetections->sampleRejectedDetectionsByUserId($userId, $this->getRejectSampleSize($batchSize), $minDetectionSize, $minDetectionSize);
			// Guarantee forward progress even when samples and rejects have eaten the whole
			// budget, but keep the floor relative so a small batch size stays a small batch.
			$freshDetectionFloor = min(500, (int)round($batchSize * (1.0 - self::REFERENCE_SAMPLE_BUDGET_SHARE)));
			$requestedFreshDetectionCount = max($batchSize - count($rejectedDetections) - count($sampledDetections), $freshDetectionFloor);
			$freshDetections = $this->faceDetections->findUnclusteredByUserId($userId, $requestedFreshDetectionCount, $minDetectionSize, $minDetectionSize);
		} else {
			$freshDetections = $this->faceDetections->findUnclusteredByUserId($userId, 0, $minDetectionSize, $minDetectionSize);
			$rejectedDetections = $this->faceDetections->sampleRejectedDetectionsByUserId($userId, $this->getRejectSampleSize(count($freshDetections)), $minDetectionSize, $minDetectionSize);
		}


		$params = $this->backend->getParams();

		// With a discriminative embedding (InsightFace) most new faces belong to a person that already
		// has a cluster: assign those directly by centroid distance instead of running them through
		// HDBSCAN, which cannot grow existing clusters reliably and is O(n²).
		if ($params['assignThreshold'] > 0 && count($existingClusters) > 0) {
			$assigned = $this->assignToExistingClusters($existingClusters, array_merge($freshDetections, $rejectedDetections), $params['assignThreshold'], $params['assignMargin']);
			if ($assigned > 0) {
				$this->logger->debug('ClusterDebug: Assigned ' . $assigned . ' detections directly to existing clusters');
				$freshDetections = array_values(array_filter($freshDetections, static fn (FaceDetection $d) => $d->getClusterId() === null));
				$rejectedDetections = array_values(array_filter($rejectedDetections, static fn (FaceDetection $d) => $d->getClusterId() === -1));
			}
		}

		$unclusteredDetections = array_merge($freshDetections, $rejectedDetections);
		$detections = array_merge($unclusteredDetections, $sampledDetections);

		if (count($detections) < $this->minDatasetSize || count($freshDetections) === 0) {
			$this->logger->debug('ClusterDebug: Not enough face detections found');
			return;
		}

		$this->logger->debug('ClusterDebug: Found ' . count($freshDetections) . " fresh detections. Adding " . count($rejectedDetections). " old detections and " . count($sampledDetections). " sampled detections from already existing clusters. Calculating clusters on " . count($detections) . " detections.");


		$dataset = new Labeled(array_map(static function (FaceDetection $detection): array {
			return $detection->getVector();
		}, $detections), array_combine(array_keys($detections), array_keys($detections)), false);

		$dataset->features();

		$n = count($detections);
		$hdbscan = new HDBSCAN($dataset, $this->getMinClusterSize($n), $this->getMinSampleSize($n));

		$numberOfClusteredDetections = 0;
		$clusters = $hdbscan->predict($params['clusterSeparation'], $params['clusterEdgeLength']);

		foreach ($clusters as $flatCluster) {
			/** @var int[] $detectionKeys */
			$detectionKeys = array_keys($flatCluster->getClusterVertices());

			$clusterDetections = array_filter($detections, function ($key) use ($detectionKeys) {
				return isset($detectionKeys[$key]);
			}, ARRAY_FILTER_USE_KEY);
			$clusterCentroid = self::calculateCentroidOfDetections($clusterDetections);
			$votes = [];

			// Let already clustered detections vote which
			// clusterId these newly clustered detections get
			foreach ($detectionKeys as $detectionKey) {
				if ($detectionKey < count($unclusteredDetections)) {
					continue;
				}

				$vote = $detections[$detectionKey]->getClusterId();

				if ($vote === null) {
					$vote = -1;
				}

				$votes[] = $vote;
			}

			$oldClusterId = -1;
			if (empty($votes)) {
				$overlap = 0.0;
			} else {
				$votes = array_count_values($votes);
				$oldClusterId = array_search(max($votes), $votes);
				$overlap = max($votes) / $maxVotesByCluster[$oldClusterId];
			}

			// If more than X% of already clustered detections are for this, we keep it
			if ($overlap > self::MIN_OVERLAP_EXISTING_CLUSTER) {
				$clusterId = $oldClusterId;
				$cluster = $this->faceClusters->find($clusterId);
			} elseif ($overlap < self::MAX_OVERLAP_NEW_CLUSTER) {
				// otherwise we create a new cluster

				$cluster = new FaceCluster();
				$cluster->setTitle('');
				$cluster->setUserId($userId);
				$this->faceClusters->insert($cluster);
			} else {
				// this is a shit cluster. Don't add to it.
				continue;
			}

			foreach ($detectionKeys as $detectionKey) {
				if ($detectionKey >= count($unclusteredDetections)) {
					// This is a sampled, already clustered detection, ignore.
					continue;
				}

				// If threshold is larger than 0 and $clusterCentroid is not the null vector
				if ($unclusteredDetections[$detectionKey]->getThreshold() > 0.0 && count(array_filter($clusterCentroid, fn ($el) => $el !== 0.0)) > 0) {
					// If a threshold is set for this detection and its vector is farther away from the centroid
					// than the threshold, skip assigning this detection to the cluster
					$distanceValue = self::distance($clusterCentroid, $unclusteredDetections[$detectionKey]->getVector());
					if ($distanceValue >= $unclusteredDetections[$detectionKey]->getThreshold()) {
						continue;
					}
				}

				$this->faceDetections->assocWithCluster($unclusteredDetections[$detectionKey], $cluster);
				$numberOfClusteredDetections += 1;
			}
		}

		$this->logger->debug('ClusterDebug: Clustering complete. Total num of clustered detections: ' . $numberOfClusteredDetections);

		$duplicates = $this->enforceOnePersonPerPhoto($userId);
		if ($duplicates > 0) {
			$this->logger->debug('ClusterDebug: Rejected ' . $duplicates . ' faces that would put the same person twice into one photo');
		}

		foreach ($unclusteredDetections as $detection) {
			if ($detection->getClusterId() === null) {
				// This detection was run through clustering but wasn't assigned to any cluster
				$detection->setClusterId(-1);
				$this->faceDetections->update($detection);
			}
		}

		$this->settingsService->setSetting('clusterFaces.status', 'true');
		$this->settingsService->setSetting('clusterFaces.lastRun', (string)time());
	}

	/**
	 * Assign unclustered detections to the nearest existing cluster when it is unambiguous.
	 *
	 * @param list<FaceCluster> $clusters
	 * @param list<FaceDetection> $detections
	 * @return int number of assigned detections
	 * @throws \OCP\DB\Exception
	 */
	private function assignToExistingClusters(array $clusters, array $detections, float $threshold, float $margin): int {
		$centroids = [];
		foreach ($clusters as $cluster) {
			$sample = $this->faceDetections->findByClusterIdLimited($cluster->getId(), FaceClusterMerger::SAMPLE_SIZE);
			if (count($sample) === 0) {
				continue;
			}
			$centroids[$cluster->getId()] = ['cluster' => $cluster, 'centroid' => self::calculateCentroidOfDetections($sample)];
		}
		if (count($centroids) === 0) {
			return 0;
		}
		$assigned = 0;
		/** @var array<int, array<int, true>> $clustersInFile file id => cluster ids that already have a face in that photo */
		$clustersInFile = [];
		foreach ($detections as $detection) {
			$fileId = $detection->getFileId();
			if (!isset($clustersInFile[$fileId])) {
				$clustersInFile[$fileId] = [];
				foreach ($this->faceDetections->findByFileIdAndUser($fileId, $detection->getUserId()) as $sibling) {
					if ($sibling->getClusterId() !== null && $sibling->getClusterId() > 0) {
						$clustersInFile[$fileId][$sibling->getClusterId()] = true;
					}
				}
			}
			$best = null;
			$bestDistance = INF;
			$secondDistance = INF;
			foreach ($centroids as $clusterId => $entry) {
				if (isset($clustersInFile[$fileId][$clusterId])) {
					// this person is already in the photo: another face cannot be them as well
					continue;
				}
				$distance = self::distance($detection->getVector(), $entry['centroid']);
				if ($distance < $bestDistance) {
					$secondDistance = $bestDistance;
					$bestDistance = $distance;
					$best = $entry['cluster'];
				} elseif ($distance < $secondDistance) {
					$secondDistance = $distance;
				}
			}
			if ($best === null || $bestDistance >= $threshold || $secondDistance - $bestDistance < $margin) {
				continue;
			}
			if ($detection->getThreshold() > 0.0 && $bestDistance >= $detection->getThreshold()) {
				// the user moved this face away from a cluster; respect that
				continue;
			}
			$this->faceDetections->assocWithCluster($detection, $best);
			$clustersInFile[$fileId][$best->getId()] = true;
			$assigned++;
		}
		return $assigned;
	}

	/**
	 * A person cannot appear twice in the same photo: when a cluster ends up with several faces
	 * in one file, keep the face closest to the cluster centroid and reject the others.
	 *
	 * @return int number of rejected detections
	 * @throws \OCP\DB\Exception
	 */
	public function enforceOnePersonPerPhoto(string $userId): int {
		$rejected = 0;
		$centroids = [];
		foreach ($this->faceDetections->findDuplicateFacesPerFile($userId) as $pair) {
			if (!isset($centroids[$pair['clusterId']])) {
				$centroids[$pair['clusterId']] = self::calculateCentroidOfDetections($this->faceDetections->findByClusterIdLimited($pair['clusterId'], FaceClusterMerger::SAMPLE_SIZE));
			}
			$faces = array_values(array_filter($this->faceDetections->findByFileIdAndUser($pair['fileId'], $userId), static fn (FaceDetection $d) => $d->getClusterId() === $pair['clusterId']));
			usort($faces, fn (FaceDetection $a, FaceDetection $b) => self::distance($a->getVector(), $centroids[$pair['clusterId']]) <=> self::distance($b->getVector(), $centroids[$pair['clusterId']]));
			foreach (array_slice($faces, 1) as $face) {
				$face->setClusterId(-1);
				$this->faceDetections->update($face);
				$rejected++;
			}
		}
		return $rejected;
	}

	/**
	 * @param FaceDetection[] $detections
	 * @return list<float>
	 */
	public static function calculateCentroidOfDetections(array $detections): array {
		// init zero vector with the dimensionality of the embeddings (128 for face-api, 512 for InsightFace)
		$dimensions = count($detections) > 0 ? count(reset($detections)->getVector()) : self::DIMENSIONS;
		/** @var list<float> $sum */
		$sum = [];
		for ($i = 0; $i < $dimensions; $i++) {
			$sum[] = 0.0;
		}

		if (count($detections) === 0) {
			return $sum;
		}

		foreach ($detections as $detection) {
			$sum = array_map(static function (float $el, float $el2): float {
				return $el + $el2;
			}, $detection->getVector(), $sum);
		}

		$centroid = array_map(static function (float $el) use ($detections): float {
			return $el / (float) count($detections);
		}, $sum);

		return $centroid;
	}

	/**
	 * @param array<FaceDetection> $detections
	 * @return array<int,FaceDetection[]>
	 */
	private function findFilesWithDuplicateFaces(array $detections): array {
		$files = [];
		foreach ($detections as $detection) {
			if (!isset($files[$detection->getFileId()])) {
				$files[$detection->getFileId()] = [];
			}
			$files[$detection->getFileId()][] = $detection;
		}

		/** @var array<int,FaceDetection[]> $filesWithDuplicateFaces */
		$filesWithDuplicateFaces = array_filter($files, static function ($detections) {
			return count($detections) > 1;
		});

		return $filesWithDuplicateFaces;
	}

	private static ?Euclidean $distance;

	/**
	 * @param list<int|float> $v1
	 * @param list<int|float> $v2
	 * @return float
	 */
	private static function distance(array $v1, array $v2): float {
		if (!isset(self::$distance)) {
			self::$distance = new Euclidean();
		}
		return self::$distance->compute($v1, $v2);
	}

	/**
	 * Hypothesis is that photos per identity scale with ~ n^(1/5) in the total number of photos
	 * @param int $batchSize
	 * @return int
	 */
	private function getMinClusterSize(int $batchSize) : int {
		return (int)round(max(2.0, min(5.0, $batchSize ** (1.0 / 4.7))));
	}

	/**
	 * We use 4.6 here to have this slightly smaller than MinClusterSize but still scale similarly
	 * @param int $batchSize
	 * @return int
	 */
	private function getMinSampleSize(int $batchSize) : int {
		return (int)round(max(2, min(4, $batchSize ** (1.0 / 5.6))));
	}

	/**
	 * Grows to ~5000 detections for ~200-800 clusters (detections per cluster drop exponentially)
	 * and then grows linearly with 5 detections per cluster.
	 *
	 * Capped so that all reference samples together stay within their share of the batch
	 * budget: without that cap the ~5000+ sampled detections dwarf a batch size derived
	 * from a low memory limit, and the budget would bound nothing.
	 * @param int $numberClusters
	 * @param int $batchSize 0 for an unbounded run
	 * @return int
	 */
	private function getReferenceSampleSize(int $numberClusters, int $batchSize = 0) : int {
		$sampleSize = (int)round(75.0 * 2.0 ** (-0.007 * $numberClusters) + 5.0);

		if ($batchSize <= 0 || $numberClusters === 0) {
			return $sampleSize;
		}

		$sizePerClusterBudget = (int)floor($batchSize * self::REFERENCE_SAMPLE_BUDGET_SHARE / $numberClusters);
		return max(self::MIN_REFERENCE_SAMPLE_SIZE, min($sampleSize, $sizePerClusterBudget));
	}

	private function getRejectSampleSize(int $batchSize): int {
		return (int) min(($batchSize / 4.0), 12.0 * $batchSize ** (0.55)); // I love maths. Slap me.
	}
}
