<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Cli\Console\Traits\Cli;

/**
 * SocketInstallCommand
 * 
 * Interactive installer to publish WebSocket configuration to the host application.
 */
#[CliCommand('socket:install', 'Install the MonkeysLegion WebSocket configuration')]
class SocketInstallCommand extends Command
{
    use Cli;

    protected function handle(): int
    {
        $this->cliLine()
            ->add("🐒 MonkeysLegion Sockets Installer", "bright_white", "bold")
            ->print();

        $this->cliLine()
            ->muted("This will publish the default configuration to your config directory.")
            ->print();

        // 1. Ask for format preference
        $format = $this->ask('Which configuration format do you prefer? [mlc/php] (mlc)');
        $format = empty($format) ? 'mlc' : \strtolower($format);

        if (!in_array($format, ['mlc', 'php'])) {
            $this->cliLine()
                ->error("Invalid format [$format]. Please choose 'mlc' or 'php'.")
                ->printError();
            return self::FAILURE;
        }

        // 2. Identify source and destination
        $source = __DIR__ . "/../../../config/sockets.{$format}";
        $dest = "config/sockets.{$format}";

        // 3. Publish using framework helper (handles base_path natively)
        $this->cliLine()
            ->info("Publishing configuration...")
            ->print();

        if ($this->publish($source, $dest)) {
            $this->cliLine()
                ->success("Configuration available at ")
                ->add($dest, "bright_yellow")
                ->print();
        }

        // 4. Publish JS Assets
        $publishJs = $this->ask('Would you like to publish the JavaScript client assets to public/js? (Y/n)');
        if (empty($publishJs) || \strtolower($publishJs) === 'y') {
            $jsSource = __DIR__ . '/../../../client/src/monkeys-sockets.js';
            $jsDest = 'public/js/vendor/monkeys-sockets.js';

            // Ensure destination directory exists
            $jsDestPath = \base_path($jsDest);
            $jsDestDir = \dirname($jsDestPath);
            if (!\is_dir($jsDestDir)) {
                @\mkdir($jsDestDir, 0755, true);
            }

            if ($this->publish($jsSource, $jsDest)) {
                $this->cliLine()
                    ->success("JS Client available at ")
                    ->add($jsDest, "bright_yellow")
                    ->print();
            }
        }

        $this->cliLine()
            ->space()
            ->add("🐒 Sockets are ready to rock!", "bright_cyan", "bold")
            ->print();

        return self::SUCCESS;
    }
}
