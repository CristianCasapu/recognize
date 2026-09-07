<?php

declare(strict_types=1);
namespace OCA\Recognize\Command;

use OCA\Recognize\Db\FaceDetectionMapper;
use OCA\Recognize\Service\FaceTracker;
use OCA\Recognize\Service\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TrackFaces extends Command {
	public function __construct(
		private Logger $logger,
		private FaceDetectionMapper $detectionMapper,
		private FaceTracker $tracker,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('recognize:track-faces')
			->setDescription('Assign unassigned faces (including small ones) from neighbouring frames of a burst: same folder, ≤ ' . FaceTracker::MAX_TIME_DIFF . ' s apart, overlapping box of a known person')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only process this user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->logger->setCliOutput($output);
		$users = $input->getOption('user') !== null ? [(string)$input->getOption('user')] : $this->detectionMapper->findUserIds();
		$total = 0;
		foreach ($users as $userId) {
			$n = $this->tracker->track($userId, static function (int $pass, int $assigned) use ($output, $userId): void {
				$output->writeln("  {$userId}: pass {$pass}: {$assigned} faces assigned");
			});
			$output->writeln("<info>{$userId}: {$n} faces assigned from neighbouring frames</info>");
			$total += $n;
		}
		$output->writeln("Total: {$total}");
		return 0;
	}
}
