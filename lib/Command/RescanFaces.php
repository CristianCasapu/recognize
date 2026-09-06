<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Classifiers\Images\ClusteringFaceClassifier;
use OCA\Recognize\Db\QueueFile;
use OCA\Recognize\Exception\Exception;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\StorageService;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-run face detection on all images but keep everything that is already known:
 * only faces that do not overlap an existing detection are added. Useful after
 * enabling tiling / a larger preview to pick up small faces without losing the
 * clusters and person names that users already assigned.
 */
final class RescanFaces extends Command {
	public function __construct(
		private StorageService $storageService,
		private Logger $logger,
		private ClusteringFaceClassifier $faces,
		private IUserMountCache $userMountCache,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:rescan-faces')
			->setDescription('Detect faces in all images again and add the newly found ones, keeping existing detections, clusters and names (run recognize:cluster-faces afterwards)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->logger->setCliOutput($output);
		$this->faces->setAdditive(true);
		$this->faces->setMaxExecutionTime(0);

		foreach ($this->storageService->getMounts() as $mount) {
			$this->logger->info('Processing storage ' . $mount['storage_id'] . ' with root ID ' . $mount['override_root']);

			$mounts = array_values(array_filter($this->userMountCache->getMountsForStorageId($mount['storage_id']), function (ICachedMountInfo $mountInfo) use ($mount) {
				return $mountInfo->getRootId() === $mount['root_id'];
			}));
			if (count($mounts) > 0) {
				\OC_Util::setupFS($mounts[0]->getUser()->getUID());
			}

			$lastFileId = 0;
			do {
				$i = 0;
				$queue = [];
				foreach ($this->storageService->getFilesInMount($mount['storage_id'], $mount['override_root'], [ClusteringFaceClassifier::MODEL_NAME], $lastFileId) as $file) {
					$i++;
					$lastFileId = $file['fileid'];
					if (!$file['image']) {
						continue;
					}
					$queueFile = new QueueFile();
					$queueFile->setStorageId($mount['storage_id']);
					$queueFile->setRootId($mount['root_id']);
					$queueFile->setFileId($file['fileid']);
					$queueFile->setUpdate(false);
					$queue[] = $queueFile;
				}
				if (count($queue) === 0) {
					continue;
				}
				try {
					$this->faces->classify($queue);
				} catch (Exception $e) {
					$this->logger->warning($e->getMessage(), ['exception' => $e]);
				} catch (\RuntimeException $e) {
					$this->logger->info('Temporary error while running faces classifier', ['exception' => $e]);
				} catch (\ErrorException $e) {
					$this->logger->info('Error while running faces classifier', ['exception' => $e]);
				}
			} while ($i > 0);
			\OC_Util::tearDownFS();
		}
		$output->writeln('<info>Done. Run "occ recognize:cluster-faces" to cluster the newly found faces.</info>');
		return 0;
	}
}
