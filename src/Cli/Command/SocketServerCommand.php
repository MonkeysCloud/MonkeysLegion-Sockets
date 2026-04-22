<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Mlc\Config;

/**
 * SocketServerCommand
 * 
 * Production-ready console command to manage the MonkeysLegion WebSocket cluster.
 * 
 * Signature: socket:serve {action=start} [--host=] [--port=]
 */
#[CliCommand('socket:serve', 'Start the MonkeysLegion WebSocket Server cluster')]
class SocketServerCommand extends Command
{
    use Cli;

    public function __construct(
        private readonly DriverInterface $driver,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $action = $this->argument(0) ?? 'start';

        if ($action !== 'start') {
            $this->cliLine()
                ->error("Action [$action] not supported yet.")
                ->space()
                ->muted("Currently only [start] is implemented.")
                ->printError();
                
            return self::FAILURE;
        }

        $host = $this->option('host');
        $port = $this->option('port');

        // Derive final bind settings from injected driver's config
        $selectedDriver = $this->config->get('sockets.driver', 'stream');
        
        $finalHost = $host ?? $this->config->get("sockets.host", "0.0.0.0");
        $finalPort = (int) ($port ?? $this->config->get("sockets.port", 8080));

        $this->cliLine()
            ->add("🚀 Starting MonkeysLegion WebSocket Server...", "bright_white", "bold")
            ->print();

        $this->cliLine()
            ->add("📡 Driver: ", "white")
            ->add(\get_class($this->driver), "cyan")
            ->print();

        $this->cliLine()
            ->add("🔗 Bind:   ", "white")
            ->add("$finalHost:$finalPort", "bright_yellow")
            ->print();

        $this->cliLine()
            ->add("🛠️ Mode:   ", "white")
            ->add("Production", "bright_green")
            ->print();

        $this->cliLine()
            ->muted(str_repeat('-', 50))
            ->print();

        // 1. Setup Signal Handling for Graceful Shutdown
        if (\extension_loaded('pcntl')) {
            \pcntl_async_signals(true);
            $shutdown = function () use ($finalHost, $finalPort) {
                $this->cliLine()
                    ->space()
                    ->add("🛑 Shutting down MonkeysLegion gracefully...", "bright_red", "bold")
                    ->print();
                
                $this->driver->stop();
                exit(0);
            };

            \pcntl_signal(SIGINT, $shutdown);
            \pcntl_signal(SIGTERM, $shutdown);
        }

        try {
            $this->driver->listen($finalHost, $finalPort);
        } catch (\Throwable $e) {
            $this->cliLine()
                ->error("Failed to start server: ")
                ->add($e->getMessage(), "white")
                ->printError();
                
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
