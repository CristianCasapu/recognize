<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Service\ForkUpdater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SelfUpdate extends Command {
	public function __construct(
		private ForkUpdater $updater,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:self-update')
			->setDescription('Check for / install updates of the forked apps (' . implode(', ', array_keys(ForkUpdater::REPOS)) . ') from their GitHub releases')
			->addArgument('app', InputArgument::OPTIONAL, 'App to update (omit to only list available updates)')
			->addOption('check', 'c', InputOption::VALUE_NONE, 'Only check, do not install');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apps = $this->updater->check(true);
		$table = new Table($output);
		$table->setHeaders(['app', 'installed', 'latest release', 'published', 'update?', 'note']);
		foreach ($apps as $app => $info) {
			$table->addRow([$app, $info['installedTag'], $info['latestTag'] ?? '-', $info['published'] ?? '-', $info['available'] ? 'yes' : 'no', $info['error'] ?? ($info['git'] ? 'git checkout (update with git)' : '')]);
		}
		$table->render();

		$app = $input->getArgument('app');
		if ($app === null || $input->getOption('check')) {
			return 0;
		}
		try {
			$this->updater->update((string)$app, static fn (string $line) => $output->writeln($line));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		return 0;
	}
}
