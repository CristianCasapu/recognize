<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Service\InsightfaceInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallInsightface extends Command {
	public function __construct(
		private InsightfaceInstaller $installer,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:install-insightface')
			->setDescription('Install the InsightFace face backend (Python virtualenv with onnxruntime + insightface and the model pack) without root')
			->addOption('cpu', null, InputOption::VALUE_NONE, 'Install the CPU-only onnxruntime (no NVIDIA GPU)')
			->addOption('dir', 'd', InputOption::VALUE_REQUIRED, 'Installation directory (default: <data>/appdata_*/recognize/insightface)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$this->installer->install(!$input->getOption('cpu'), $input->getOption('dir'), static fn (string $line) => $output->writeln($line));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$check = $this->installer->check(true);
		$output->writeln($check['ok']
			? '<info>InsightFace is ready (' . ($check['gpu'] ? 'GPU' : 'CPU') . '). Switch to it with "occ recognize:switch-face-backend insightface".</info>'
			: '<error>Installation finished but the check failed: ' . $check['output'] . '</error>');
		return $check['ok'] ? 0 : 1;
	}
}
