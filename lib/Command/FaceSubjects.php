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

/**
 * Tell the people a photo was taken of from the people who happen to be in it.
 */
final class FaceSubjects extends Command {
	public function __construct(private FaceQualityService $quality) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('recognize:face-subjects')
			->setDescription('Weigh the faces of every photo against one another: the ones in focus and in front are the people the picture is about, the small or blurred ones behind them are the surroundings. Nothing is read from disk (only what the quality pass already measured), so this is fast.')
			->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many files (0 = all)', '0')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'Weigh every photo again, not only the ones never weighed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = (int)$input->getOption('limit');
		$all = (bool)$input->getOption('all');

		if (!$all) {
			$missing = $this->quality->countMissingSubject();
			$output->writeln('Faces never weighed: ' . $missing);
			if ($missing === 0) {
				$output->writeln('Nothing to do (use --all to weigh every photo again).');

				return 0;
			}
		}

		$batch = 500;
		$files = 0;
		$faces = 0;
		$after = 0;
		$start = time();
		while (true) {
			$size = $limit > 0 ? min($batch, $limit - $files) : $batch;
			if ($size <= 0) {
				break;
			}
			$result = $this->quality->backfillSubjects($size, $all, $after);
			$files += $result['files'];
			$faces += $result['faces'];
			$after = $result['lastFileId'];
			$elapsed = max(1, time() - $start);
			$output->writeln(sprintf('  %d files, %d faces (%.1f files/s)%s', $files, $faces, $files / $elapsed, $all ? '' : ', ' . $result['remaining'] . ' faces left'));
			if ($result['files'] === 0 || (!$all && $result['remaining'] === 0)) {
				break;
			}
		}
		$output->writeln(sprintf('Done. The line between a subject and the surroundings is %.2f (faces.subjectThreshold).', $this->quality->subjectThreshold()));

		return 0;
	}
}
