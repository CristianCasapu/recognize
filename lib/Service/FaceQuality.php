<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Service;

use OCA\Recognize\Db\FaceDetection;
use OCA\Recognize\Db\FaceDetectionMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * How prominent a face is in its photo, as one number 0..1 ("quality").
 *
 * Memories sorts a person's photos by it ("best photos first"): a large, sharp, well lit face
 * looking at the camera near the middle of the frame comes first; a small face far away,
 * blurred, in the dark or turned sideways comes last.
 *
 * Components (each 0..1) and their weights:
 *   size        0.40  side of the face box relative to the image (0.35 of the image = 1)
 *   sharpness   0.20  Laplacian variance of the face crop (src/face_quality.py)
 *   brightness  0.12  mean luminance of the face crop, ideal between 0.35 and 0.8
 *   pose        0.13  cos(yaw)·cos(pitch) from the detector's head pose
 *   centrality  0.10  distance of the face centre from the image centre
 *   detector    0.05  detection confidence
 * Components that were never measured (old detections) are left out and the weights re-normalised.
 */
final class FaceQuality {
	public const WEIGHTS = ['size' => 0.40, 'sharpness' => 0.20, 'brightness' => 0.12, 'pose' => 0.13, 'centrality' => 0.10, 'detector' => 0.05];
	private const PREVIEW = 1024;

	/**
	 * A face this big (side, relative to the image) is in front whatever else is in the picture.
	 * It is also the yardstick when a photo has only small faces: then even the biggest of them
	 * is not a subject, which is what makes a lone person far away count as surroundings.
	 */
	public const SUBJECT_SIZE = 0.12;

	/** The same for sharpness: in a picture where nothing is sharp, nobody is the subject. */
	public const SUBJECT_SHARPNESS = 0.45;

	public function __construct(
		private FaceDetectionMapper $detections,
		private IRootFolder $rootFolder,
		private IPreview $previews,
		private ITempManager $tempManager,
		private SettingsService $settings,
		private FaceBackend $backend,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The composite quality from whatever was measured for this face.
	 */
	public function composite(float $width, float $height, float $x, float $y, ?float $score, ?float $yaw, ?float $pitch, ?float $sharpness, ?float $brightness): float {
		$parts = [];
		$parts['size'] = min(1.0, sqrt(max(0.0, $width * $height)) / 0.35);
		$cx = ($x + $width / 2 - 0.5) / 0.5;
		$cy = ($y + $height / 2 - 0.5) / 0.5;
		$parts['centrality'] = max(0.0, 1.0 - sqrt($cx * $cx + $cy * $cy) / M_SQRT2);
		if ($sharpness !== null) {
			$parts['sharpness'] = max(0.0, min(1.0, $sharpness));
		}
		if ($brightness !== null) {
			$parts['brightness'] = self::brightnessScore($brightness);
		}
		if ($yaw !== null && $pitch !== null) {
			$parts['pose'] = max(0.0, cos(deg2rad(min(90.0, abs($yaw)))) * cos(deg2rad(min(90.0, abs($pitch)))));
		}
		if ($score !== null) {
			$parts['detector'] = max(0.0, min(1.0, ($score - 0.5) / 0.45));
		}
		$sum = 0.0;
		$weight = 0.0;
		foreach ($parts as $name => $value) {
			$sum += self::WEIGHTS[$name] * $value;
			$weight += self::WEIGHTS[$name];
		}
		return $weight > 0 ? round($sum / $weight, 4) : 0.0;
	}

	/** dark faces score low, a slightly overexposed face is still fine */
	private static function brightnessScore(float $mean): float {
		if ($mean <= 0.12) {
			return 0.0;
		}
		if ($mean < 0.35) {
			return ($mean - 0.12) / 0.23;
		}
		if ($mean <= 0.8) {
			return 1.0;
		}
		return max(0.5, 1.0 - ($mean - 0.8) / 0.4);
	}

	/**
	 * Is this face what the picture is about, or is it part of the surroundings?
	 *
	 * A photograph is normally taken of the people in front of the lens: they are big in the
	 * frame and they are where the lens was focused. Everybody else — further back, smaller,
	 * softer because they are outside the depth of field — is scenery.
	 *
	 * Both things are measured against the other faces of the same photo, because sharpness
	 * and size mean nothing on their own (a phone photo and a photo from a big lens do not
	 * compare), with an absolute floor so that a picture in which every face is small or every
	 * face is blurred has no subject at all:
	 *
	 *   foreground = size  / max(biggest face,  SUBJECT_SIZE)
	 *   focus      = sharp / max(sharpest face, SUBJECT_SHARPNESS)
	 *   subject    = sqrt(foreground · focus)     both have to hold; one alone is not enough
	 *
	 * @param list<FaceDetection> $detections every face of ONE photo
	 *
	 * @return array<int, float> detection id => subject, and the value is set on the entities
	 */
	public function subjects(array $detections): array {
		if (count($detections) === 0) {
			return [];
		}

		$sizes = [];
		$sharpnesses = [];
		foreach ($detections as $detection) {
			$sizes[] = sqrt(max(0.0, (float)$detection->getWidth() * (float)$detection->getHeight()));
			if ($detection->getSharpness() !== null) {
				$sharpnesses[] = max(0.0, min(1.0, (float)$detection->getSharpness()));
			}
		}
		$sizeRef = max(self::SUBJECT_SIZE, max($sizes));
		// no face of this photo was ever measured: judge by size alone rather than guess
		$sharpRef = count($sharpnesses) > 0 ? max(self::SUBJECT_SHARPNESS, max($sharpnesses)) : null;

		$out = [];
		foreach ($detections as $i => $detection) {
			$foreground = min(1.0, $sizes[$i] / $sizeRef);
			$focus = 1.0;
			if ($sharpRef !== null) {
				$sharpness = $detection->getSharpness();
				// a face of this photo that was never measured keeps the benefit of the doubt
				$focus = $sharpness === null ? 1.0 : min(1.0, max(0.0, (float)$sharpness) / $sharpRef);
			}
			$subject = round(sqrt($foreground * $focus), 4);
			$detection->setSubject($subject);
			$out[(int)$detection->getId()] = $subject;
		}

		return $out;
	}

	/** The line between a person the picture is about and the surroundings (faces.subjectThreshold). */
	public function subjectThreshold(): float {
		$value = (float)$this->settings->getSetting('faces.subjectThreshold');

		return $value > 0.0 && $value <= 1.0 ? $value : 0.55;
	}

	/**
	 * Weigh the faces of one photo against one another again, from what is already stored
	 * (no picture is read). Used after new faces were found in a file and by the command.
	 *
	 * @return int how many faces were weighed
	 */
	public function rescoreSubjects(int $fileId): int {
		$detections = $this->detections->findByFileId($fileId);
		if (count($detections) === 0) {
			return 0;
		}
		$this->subjects($detections);
		foreach ($detections as $detection) {
			$this->detections->update($detection);
		}

		return count($detections);
	}

	public function countMissingSubject(): int {
		return $this->detections->countMissingSubject();
	}

	/**
	 * Weigh the faces of files that were never weighed (or of every file, with $all).
	 *
	 * @param callable(int $files, int $faces):void|null $progress
	 *
	 * @return array{files:int, faces:int, remaining:int, lastFileId:int}
	 */
	public function backfillSubjects(int $fileLimit = 500, bool $all = false, int $afterFileId = 0, ?callable $progress = null): array {
		$fileIds = $all
			? $this->detections->findFileIdsWithFaces($fileLimit, $afterFileId)
			: $this->detections->findFileIdsMissingSubject($fileLimit);
		$files = 0;
		$faces = 0;
		$last = $afterFileId;
		foreach ($fileIds as $fileId) {
			$faces += $this->rescoreSubjects($fileId);
			$last = $fileId;
			$files++;
			if ($progress !== null) {
				$progress($files, $faces);
			}
		}

		return ['files' => $files, 'faces' => $faces, 'remaining' => $this->countMissingSubject(), 'lastFileId' => $last];
	}

	/** Fill every quality-related column of a detection that the classifier just produced */
	public function applyDetectorResult(FaceDetection $detection, array $face): void {
		$score = isset($face['score']) ? (float)$face['score'] : null;
		$yaw = isset($face['angle']['yaw']) ? (float)$face['angle']['yaw'] : null;
		$pitch = isset($face['angle']['pitch']) ? (float)$face['angle']['pitch'] : null;
		$sharpness = isset($face['sharpness']) ? (float)$face['sharpness'] : null;
		$brightness = isset($face['brightness']) ? (float)$face['brightness'] : null;
		$detection->setScore($score);
		$detection->setYaw($yaw);
		$detection->setPitch($pitch);
		$detection->setSharpness($sharpness);
		$detection->setBrightness($brightness);
		$detection->setQuality($this->composite(
			(float)$detection->getWidth(), (float)$detection->getHeight(), (float)$detection->getX(), (float)$detection->getY(),
			$score, $yaw, $pitch, $sharpness, $brightness,
		));
	}

	public function countMissing(): int {
		return $this->detections->countMissingQuality();
	}

	/**
	 * Score the detections of up to $fileLimit files that have no quality yet.
	 *
	 * @param callable(int $files, int $faces):void|null $progress called after every file
	 * @return array{files:int, faces:int, remaining:int}
	 */
	public function backfill(int $fileLimit = 100, ?callable $progress = null): array {
		$files = 0;
		$faces = 0;
		$fileIds = $this->detections->findFileIdsMissingQuality($fileLimit);
		if (count($fileIds) === 0) {
			return ['files' => 0, 'faces' => 0, 'remaining' => $this->countMissing()];
		}

		// one Python process for the whole batch: starting the interpreter costs more than the measuring
		$jobs = [];
		$byFile = [];
		foreach ($fileIds as $fileId) {
			$detections = array_values(array_filter(
				$this->detections->findByFileId($fileId),
				static fn (FaceDetection $d) => $d->getQuality() === null,
			));
			$byFile[$fileId] = $detections;
			$path = null;
			try {
				$path = $this->imagePath($fileId);
			} catch (\Throwable $e) {
				$this->logger->debug('Face quality: cannot read file ' . $fileId . ': ' . $e->getMessage());
			}
			$jobs[$fileId] = [
				'path' => $path ?? '',
				'boxes' => array_map(static fn (FaceDetection $d) => [$d->getX(), $d->getY(), $d->getWidth(), $d->getHeight()], $detections),
			];
		}
		$results = [];
		try {
			$results = $this->measureBatch(array_values($jobs));
		} catch (\Throwable $e) {
			$this->logger->warning('Face quality: measuring failed, scoring by size and position only: ' . $e->getMessage());
		}
		$this->tempManager->clean();

		$i = 0;
		foreach ($byFile as $fileId => $detections) {
			$metrics = $results[$i++] ?? null;
			foreach ($detections as $j => $detection) {
				$m = is_array($metrics) ? ($metrics[$j] ?? null) : null;
				if (is_array($m)) {
					$detection->setSharpness((float)$m['sharpness']);
					$detection->setBrightness((float)$m['brightness']);
				}
				$this->storeComposite($detection);
				$faces++;
			}
			// subject or surroundings: every face of the photo, not only the ones just measured
			$this->rescoreSubjects((int)$fileId);
			$files++;
			if ($progress !== null) {
				$progress($files, $faces);
			}
		}
		return ['files' => $files, 'faces' => $faces, 'remaining' => $this->countMissing()];
	}

	private function storeComposite(FaceDetection $detection): void {
		$detection->setQuality($this->composite(
			(float)$detection->getWidth(), (float)$detection->getHeight(), (float)$detection->getX(), (float)$detection->getY(),
			$detection->getScore(), $detection->getYaw(), $detection->getPitch(), $detection->getSharpness(), $detection->getBrightness(),
		));
		$this->detections->update($detection);
	}

	/**
	 * @param list<array{path:string, boxes:list<array{0:float,1:float,2:float,3:float}>}> $jobs
	 * @return list<list<array{sharpness:float,brightness:float}|null>|null>
	 */
	private function measureBatch(array $jobs): array {
		$jobFile = $this->tempManager->getTemporaryFile('.json');
		if ($jobFile === false) {
			throw new \RuntimeException('cannot create a temporary file');
		}
		file_put_contents($jobFile, json_encode($jobs));
		$out = $this->runScript(['--batch', $jobFile]);
		return is_array($out) ? $out : [];
	}

	/** @return mixed decoded JSON output of src/face_quality.py */
	private function runScript(array $args): mixed {
		$cmd = array_merge([$this->backend->getPythonBinary(), dirname(__DIR__, 2) . '/src/face_quality.py'], $args);
		$env = array_merge($this->settings->getClassifierEnvironment(), ['TMPDIR' => (string)$this->tempManager->getTempBaseDir()]);
		$process = new Process($cmd, dirname(__DIR__, 2), $env);
		$process->setTimeout(600);
		$process->run();
		if (!$process->isSuccessful()) {
			$lines = array_slice(array_filter(explode("\n", trim($process->getErrorOutput()))), -2);
			throw new \RuntimeException(implode(' | ', $lines));
		}
		return json_decode(trim($process->getOutput()), true);
	}

	/**
	 * Measure sharpness and brightness of every unscored face of one file and store the quality.
	 * When the image cannot be read the faces still get a quality from size and position.
	 *
	 * @return int number of faces scored
	 */
	public function scoreFile(int $fileId): int {
		$detections = array_values(array_filter(
			$this->detections->findByFileId($fileId),
			static fn (FaceDetection $d) => $d->getQuality() === null,
		));
		if (count($detections) === 0) {
			return 0;
		}

		$metrics = [];
		try {
			$path = $this->imagePath($fileId);
			if ($path !== null) {
				$metrics = $this->measure($path, $detections);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Face quality: could not measure file ' . $fileId . ': ' . $e->getMessage());
		} finally {
			$this->tempManager->clean();
		}

		foreach ($detections as $i => $detection) {
			$m = $metrics[$i] ?? null;
			if (is_array($m)) {
				$detection->setSharpness((float)$m['sharpness']);
				$detection->setBrightness((float)$m['brightness']);
			}
			$this->storeComposite($detection);
		}
		$this->rescoreSubjects($fileId);

		return count($detections);
	}

	/**
	 * @param list<FaceDetection> $detections
	 * @return list<array{sharpness:float,brightness:float}|null>
	 */
	private function measure(string $path, array $detections): array {
		$args = [$path];
		foreach ($detections as $d) {
			$args[] = implode(',', [$d->getX(), $d->getY(), $d->getWidth(), $d->getHeight()]);
		}
		$out = $this->runScript($args);
		return is_array($out) ? $out : [];
	}

	/** A readable copy of the picture: the 1024px preview when possible, else the original */
	private function imagePath(int $fileId): ?string {
		$node = $this->rootFolder->getFirstNodeById($fileId);
		if (!$node instanceof File) {
			return null;
		}
		if ($this->previews->isAvailable($node)) {
			try {
				$preview = $this->previews->getPreview($node, self::PREVIEW, self::PREVIEW);
				$tmp = $this->tempManager->getTemporaryFile('.jpg');
				if ($tmp !== false && file_put_contents($tmp, $preview->getContent()) !== false) {
					return $tmp;
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Face quality: preview of ' . $fileId . ' failed, using the original: ' . $e->getMessage());
			}
		}
		$path = $node->getStorage()->getLocalFile($node->getInternalPath());
		return is_string($path) && $path !== '' ? $path : null;
	}
}
