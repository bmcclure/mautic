<?php

namespace MauticPlugin\MauticNetSuiteBundle\Integration\NetSuite;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class ProgressUpdater {
    /** @var ConsoleOutput */
    private $output;
    /** @var ProgressBar */
    private $progress;

    public function isActive() {
        return defined('IN_MAUTIC_CONSOLE');
    }

    public function __construct($totalCount, $message = null)
    {
        if ($this->isActive()) {
            $this->output = new ConsoleOutput();

            if (!empty($message)) {
                $this->message($message);
            }

            $this->reset($totalCount);
        }
    }

    public function reset($totalCount) {
        if ($this->isActive()) {
            $this->progress = new ProgressBar($this->output, $totalCount);
        }
    }

    public function message($message) {
        if ($this->isActive()) {
            $this->output->writeln($message);
        }
    }

    public function advance($step = 1) {
        if ($this->isActive()) {
            $this->progress->advance($step);
        }
    }

    public function finish($message = '') {
        if ($this->isActive()) {
            $this->progress->finish();
            $this->output->writeln($message);
        }
    }
}
