<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Service\FaceBackend;
use OCA\Recognize\Service\FaceBackendSwitcher;
use OCA\Recognize\Service\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SwitchFaceBackend extends Command {
	public function __construct(
		private Logger $logger,
		private FaceBackendSwitcher $switcher,
		private FaceBackend $backend,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:switch-face-backend')
			->setDescription('Switch the face detection/embedding backend (' . implode(' | ', array_keys(FaceBackend::PARAMS)) . '). Saves the person names, removes all face detections and clusters (their embeddings are incompatible) and schedules a new face crawl; names are restored automatically after clustering.')
			->addArgument('backend', InputArgument::REQUIRED, implode(' or ', array_keys(FaceBackend::PARAMS)))
			->addOption('no-crawl', null, InputOption::VALUE_NONE, 'Do not schedule the background crawl (run "occ recognize:classify" yourself)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->logger->setCliOutput($output);
		$backend = (string)$input->getArgument('backend');
		$output->writeln('Current backend: ' . $this->backend->getName());
		try {
			$result = $this->switcher->switchTo($backend, !$input->getOption('no-crawl'));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		if ($result['snapshot'] !== null) {
			$output->writeln('Saved ' . $result['snapshot']['titles'] . ' person names (' . $result['snapshot']['faces'] . ' faces) to ' . $result['snapshot']['path']);
		}
		$output->writeln('<info>Face backend is now "' . $backend . '". All face detections and clusters were removed.</info>');
		$output->writeln($input->getOption('no-crawl')
			? 'Run "occ recognize:classify" (or "occ recognize:recrawl") and then "occ recognize:cluster-faces"; the names are restored after clustering.'
			: 'A background crawl has been scheduled; names are restored automatically after the first clustering run (or run "occ recognize:classify" and "occ recognize:cluster-faces" now).');
		return 0;
	}
}
