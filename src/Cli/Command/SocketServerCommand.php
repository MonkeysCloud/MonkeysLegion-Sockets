<?php

declare(strict_types=1);

namespace MonkeysLegion\Sockets\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Cli\Console\Traits\Cli;
use MonkeysLegion\Sockets\Broadcast\BroadcastBridge;
use MonkeysLegion\Sockets\Broadcast\Subscriber\ReactSubscriberWiring;
use MonkeysLegion\Sockets\Broadcast\Subscriber\StreamSubscriberWiring;
use MonkeysLegion\Sockets\Broadcast\Subscriber\SwooleSubscriberWiring;
use MonkeysLegion\Sockets\Contracts\ConnectionRegistryInterface;
use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Contracts\RedisClientInterface;
use MonkeysLegion\Sockets\Contracts\SocketServerBootstrapInterface;
use MonkeysLegion\Sockets\Driver\ReactSocketDriver;
use MonkeysLegion\Sockets\Driver\StreamSocketDriver;
use MonkeysLegion\Sockets\Driver\SwooleDriver;
use MonkeysLegion\Sockets\Serialization\JsonMessageSerializer;
use MonkeysLegion\Sockets\Server\WebSocketServer;
use MonkeysLegion\Mlc\Config;
use Psr\Container\ContainerInterface;

/**
 * SocketServerCommand
 *
 * Thin orchestrator: resolves bind settings, wires the broadcast subscriber
 * for the active driver, calls any application bootstrap hook, then starts
 * the event loop. All subscriber wiring logic lives in dedicated classes
 * under MonkeysLegion\Sockets\Broadcast\Subscriber\.
 *
 * Signature: socket:serve {action=start} [--host=] [--port=]
 */
#[CliCommand('socket:serve', 'Start the MonkeysLegion WebSocket Server cluster')]
class SocketServerCommand extends Command
{
    use Cli;

    public function __construct(
        private readonly DriverInterface $driver,
        private readonly Config $config,
        private readonly ConnectionRegistryInterface $registry,
        private readonly ?RedisClientInterface $redis = null,
        private readonly ?WebSocketServer $webSocketServer = null,
        private readonly ?ContainerInterface $container = null
    ) {
        parent::__construct();
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    protected function handle(): int
    {
        $action = $this->argument(0) ?? 'start';

        if ($action !== 'start') {
            $this->cliLine()
                ->error("Action [$action] not supported yet.")
                ->space()
                ->muted('Currently only [start] is implemented.')
                ->printError();

            return self::FAILURE;
        }

        [$finalHost, $finalPort] = $this->resolveBindAddress();
        $this->printBanner($finalHost, $finalPort);
        $this->installSignalHandlers();

        $bridge = new BroadcastBridge($this->registry, new JsonMessageSerializer());
        $this->wireSubscriber($bridge);
        $this->runBootstrapHook();

        try {
            $this->driver->listen($finalHost, $finalPort);
        } catch (\Throwable $e) {
            $this->cliLine()
                ->error('Failed to start server: ')
                ->add($e->getMessage(), 'white')
                ->printError();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{string, int} */
    private function resolveBindAddress(): array
    {
        $host       = $this->option('host');
        $port       = $this->option('port');
        $configHost = $this->config->get('sockets.host', '0.0.0.0');
        $configPort = $this->config->get('sockets.port', 8080);

        $finalHost = \is_string($host) ? $host : (\is_string($configHost) ? $configHost : '0.0.0.0');
        $portVal   = \is_string($port) || \is_int($port) ? (int) $port : null;
        $finalPort = $portVal ?? (\is_int($configPort) ? $configPort : 8080);

        return [$finalHost, $finalPort];
    }

    private function printBanner(string $host, int $port): void
    {
        $this->cliLine()->add('🚀 Starting MonkeysLegion WebSocket Server...', 'bright_white', 'bold')->print();
        $this->cliLine()->add('📡 Driver: ', 'white')->add(\get_class($this->driver), 'cyan')->print();
        $this->cliLine()->add('🔗 Bind:   ', 'white')->add("$host:$port", 'bright_yellow')->print();
        $this->cliLine()->add('🛠️ Mode:   ', 'white')->add('Production', 'bright_green')->print();
        $this->cliLine()->muted(\str_repeat('-', 50))->print();
    }

    private function installSignalHandlers(): void
    {
        if ($this->driver instanceof SwooleDriver || !\extension_loaded('pcntl')) {
            return;
        }

        \pcntl_async_signals(true);
        $shutdown = function (): void {
            $this->cliLine()->space()->add('🛑 Shutting down the server gracefully...', 'bright_red', 'bold')->print();
            $this->driver->stop();
            exit(0);
        };

        \pcntl_signal(SIGINT, $shutdown);
        \pcntl_signal(SIGTERM, $shutdown);
    }

    private function wireSubscriber(BroadcastBridge $bridge): void
    {
        match (true) {
            $this->driver instanceof SwooleDriver =>
                (new SwooleSubscriberWiring($this->driver, $this->config, $this->redis))->wire($bridge),

            $this->driver instanceof ReactSocketDriver =>
                (new ReactSubscriberWiring($this->driver, $this->config, $this->redis))->wire($bridge),

            $this->driver instanceof StreamSocketDriver =>
                (new StreamSubscriberWiring($this->driver, $this->config, $this->redis))->wire($bridge),

            default => null,
        };
    }

    private function runBootstrapHook(): void
    {
        if (!$this->webSocketServer || !$this->container) {
            return;
        }
        if (!$this->container->has(SocketServerBootstrapInterface::class)) {
            return;
        }

        $bootstrap = $this->container->get(SocketServerBootstrapInterface::class);
        if ($bootstrap instanceof SocketServerBootstrapInterface) {
            $bootstrap->boot($this->driver, $this->webSocketServer);
        }
    }
}
