<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Service\FaceQuality as FaceQualityService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class FaceQuality extends Command {
	public function __construct(private FaceQualityService $quality) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('recognize:face-quality')
			->setDescription('Score how prominent every detected face is in its photo (size, sharpness, brightness, pose); Memories sorts a person\'s photos by it. Only faces without a score are processed.')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many files (0 = all)', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = (int)$input->getOption('limit');
		$missing = $this->quality->countMissing();
		$output->writeln('Faces without a prominence score: ' . $missing);
		if ($missing === 0) {
			return 0;
		}
		$batch = 100;
		$done = 0;
		$faces = 0;
		$start = time();
		while (true) {
			$size = $limit > 0 ? min($batch, $limit - $done) : $batch;
			if ($size <= 0) {
				break;
			}
			$result = $this->quality->backfill($size);
			$done += $result['files'];
			$faces += $result['faces'];
			$elapsed = max(1, time() - $start);
			$output->writeln(sprintf('  %d files, %d faces scored (%.1f files/s), %d faces left', $done, $faces, $done / $elapsed, $result['remaining']));
			if ($result['files'] === 0 || $result['remaining'] === 0) {
				break;
			}
		}
		$output->writeln('Done.');
		return 0;
	}
}
