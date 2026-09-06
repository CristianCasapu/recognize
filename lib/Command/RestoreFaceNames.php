<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Service\FaceNameSnapshot;
use OCA\Recognize\Service\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RestoreFaceNames extends Command {
	public function __construct(
		private Logger $logger,
		private FaceNameSnapshot $snapshot,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:restore-face-names')
			->setDescription('Give person names from a snapshot (written by recognize:switch-face-backend) to the matching new face clusters')
			->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Snapshot file (default: the pending snapshot)')
			->addOption('done', null, InputOption::VALUE_NONE, 'Stop applying the pending snapshot after future clustering runs');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->logger->setCliOutput($output);
		$path = $input->getOption('file') ?? $this->snapshot->getPendingPath();
		if ($path === null) {
			$output->writeln('No pending snapshot.');
			return 0;
		}
		try {
			$named = $this->snapshot->restore((string)$path);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('Named ' . count($named) . ' cluster(s) in this run from ' . $path);
		if ($input->getOption('done')) {
			$this->snapshot->clearPending();
			$output->writeln('Snapshot is no longer pending.');
		}
		return 0;
	}
}
