<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\BackgroundJobs\SchedulerJob;
use OCA\Recognize\Classifiers\Images\ClipClassifier;
use OCA\Recognize\Service\ClipModel;
use OCA\Recognize\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallClip extends Command {
	public function __construct(
		private ClipModel $clipModel,
		private SettingsService $settingsService,
		private IJobList $jobList,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:install-clip')
			->setDescription('Download the CLIP model for natural-language photo search, enable it and schedule the indexing of all photos')
			->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model pack (' . implode(', ', array_keys(ClipModel::KNOWN_MODELS)) . ')')
			->addOption('no-crawl', null, InputOption::VALUE_NONE, 'Do not schedule the background indexing (run "occ recognize:classify" yourself)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('model') !== null) {
			$this->settingsService->setSetting('clip.model', (string)$input->getOption('model'));
		}
		try {
			$this->clipModel->download(static fn (string $line) => $output->writeln($line));
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$this->settingsService->setSetting('clip.enabled', 'true');
		if (!$input->getOption('no-crawl')) {
			$this->jobList->add(SchedulerJob::class, ['models' => [ClipClassifier::MODEL_NAME]]);
			$output->writeln('<info>Natural-language search enabled; photo indexing scheduled (background jobs).</info>');
		} else {
			$output->writeln('<info>Natural-language search enabled. Index the photos with "occ recognize:classify".</info>');
		}
		return 0;
	}
}
