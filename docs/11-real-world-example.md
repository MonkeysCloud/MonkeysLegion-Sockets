# Real-World Scenario: "MonkeysCollab"

To demonstrate the full power of MonkeysLegion Sockets, let's build the backend and integration for an imaginary project called **MonkeysCollab** — a real-time team collaboration platform featuring live chat, presence tracking (who is online), and secure document editing.

This guide will walk you through setting up the complete workflow, from zero to an interactive socket integration.

---

## 1. Installation & Configuration

First, install the sockets package via Composer:

```bash
composer require monkeyscloud/monkeyslegion-sockets
```

Next, publish the configuration and client-side assets:

```bash
php ml socket:install
# 1. Choose 'mlc' format
# 2. Say 'Y' to publishing JavaScript assets
```

Configure your `config/sockets.mlc` to use the Swoole driver for high performance, and Redis for distributed broadcasting between your web application and the socket server:

```mlc
sockets {
    driver "swoole",
    registry "redis",
    broadcast "redis",
    formatter "json",
    host "0.0.0.0",
    port 8080,
    
    security {
        # Only allow connections from our app
        allowed_origins [
            "https://monkeyscollab.com",
            "http://localhost:3000"
        ],
    }
}
```

---

## 2. Implementing Security & Authorization

In MonkeysCollab, users can chat in "Teams" and track who is currently viewing a "Document". We need to ensure that only authenticated users can connect, and only members can join specific team channels.

### A. The Handshake Authenticator (JWT)

We want to authenticate the user *before* the WebSocket connection is even fully established. Let's create a JWT Authenticator:

```php
namespace App\Sockets\Security;

use MonkeysLegion\Sockets\Contracts\AuthenticatorInterface;
use Psr\Http\Message\ServerRequestInterface;

class CollabJwtAuthenticator implements AuthenticatorInterface
{
    public function authenticate(ServerRequestInterface $request): bool
    {
        // For browsers, we might pass the token in the query string: ws://...?token=xxxx
        $query = $request->getQueryParams();
        $token = $query['token'] ?? '';
        
        if (!$this->isValidJwt($token)) {
            return false;
        }
        
        // Stash user info in a global context or pass it along.
        // The driver will attach request metadata to the ConnectionInterface.
        return true;
    }
    
    private function isValidJwt(string $token): bool {
        // Validation logic here...
        return !empty($token);
    }
}
```

*Note: In a true containerized setup, the `SocketServiceProvider` can be extended or overridden to inject this into the `HandshakeNegotiator`.*

### B. The Channel Authorizer

Once connected, a user asks to join a private or presence channel. We validate those requests here.

```php
namespace App\Sockets\Security;

use MonkeysLegion\Sockets\Contracts\ChannelAuthorizerInterface;
use MonkeysLegion\Sockets\Contracts\ConnectionInterface;
use App\Repositories\TeamRepository;

class TeamAuthorizer implements ChannelAuthorizerInterface
{
    public function __construct(
        private TeamRepository $teams
    ) {}

    public function authorize(ConnectionInterface $connection, string $channel, array $parameters = []): bool
    {
        $userId = $connection->getMetadata()['user_id'] ?? null;
        if (!$userId) return false;

        // E.g., user wants to join "team-42"
        if (\str_starts_with($channel, 'team-')) {
            $teamId = (int) \substr($channel, 5);
            return $this->teams->isMember($userId, $teamId);
        }
        
        // E.g., user wants to join "doc-100"
        if (\str_starts_with($channel, 'doc-')) {
            $docId = (int) \substr($channel, 4);
            return $this->teams->canViewDocument($userId, $docId);
        }

        return false;
    }
}
```

### C. Wiring the Pipeline

In your `AppServiceProvider` or a custom Socket bootstrapper, assemble the pipeline and tell the server about it:

```php
use MonkeysLegion\Sockets\Service\AuthorizerPipeline;

// Inside your provider's register() method:
$container->set(AuthorizerPipeline::class, function() use ($container) {
    $pipeline = new AuthorizerPipeline();
    
    // Add IP Blocking (priority 100 - runs first)
    $pipeline->addAuthorizer($container->resolve(IpBlockerAuthorizer::class), 100);
    
    // Add Team/Document Auth (priority 50 - runs second)
    $pipeline->addAuthorizer($container->resolve(TeamAuthorizer::class), 50);
    
    return $pipeline;
});
```

---

## 3. The Server Entrypoint

By default, `php ml socket:serve` boots up a blank server that accepts connections but does nothing with incoming messages. 

Let's hook into the driver to handle incoming logic. Create a Bootstrapper that registers listeners on the driver before it starts listening:

```php
namespace App\Sockets;

use MonkeysLegion\Sockets\Contracts\DriverInterface;
use MonkeysLegion\Sockets\Server\WebSocketServer;

class SocketEventBootstrapper
{
    public function __construct(
        private DriverInterface $driver,
        private WebSocketServer $server
    ) {}
    
    public function boot(): void
    {
        $this->driver->on('message', function ($connection, $message) {
            $payload = json_decode($message->getPayload(), true);
            $action = $payload['action'] ?? null;

            match ($action) {
                // Presence Channel: Track users actively viewing a document
                'view_document' => $this->handleViewDocument($connection, $payload),
                
                // Typical Private Channel: Chatting in a team
                'join_team' => $this->handleJoinTeam($connection, $payload),
                
                // Active communication via sockets
                'send_chat' => $this->handleSendChat($connection, $payload),
                
                default => null, // Ignore unknown actions
            };
        });
    }

    private function handleViewDocument($connection, array $payload): void
    {
        $docId = $payload['doc_id'];
        $members = $this->server->joinPresence($connection, "doc-{$docId}");
        
        if ($members !== false) {
            // Tell the user who else is here right now
            $connection->send(json_encode([
                'action' => 'document_viewers',
                'data' => $members
            ]));
        } else {
            $connection->send(json_encode(['error' => 'Unauthorized access to document']));
        }
    }

    private function handleJoinTeam($connection, array $payload): void
    {
        $teamId = $payload['team_id'];
        if ($this->server->joinPrivate($connection, "team-{$teamId}")) {
            $connection->send(json_encode(['action' => 'team_joined', 'team_id' => $teamId]));
        } else {
            $connection->send(json_encode(['error' => 'Cannot join team']));
        }
    }
    
    private function handleSendChat($connection, array $payload): void
    {
        $teamId = $payload['team_id'];
        $text = $payload['text'];
        
        // Broadcast to everyone in the team channel
        $this->server->to("private:team-{$teamId}")->emit('new_chat_message', [
            'from_id' => $connection->getId(),
            'text' => htmlspecialchars($text)
        ]);
    }
}
```

*In a robust app, you would resolve `SocketEventBootstrapper` and call `boot()` right before `php ml socket:serve start` actually calls `$driver->listen()`.*

---

## 4. Triggering Events from HTTP Controllers (Broadcasting)

Sockets are fantastic for receiving data, but often events happen via standard HTTP REST requests (e.g., a file upload finishes, or a webhook fires). You can **broadcast** to the WebSocket server from your HTTP application natively using the `BroadcasterInterface`.

```php
namespace App\Http\Controllers;

use MonkeysLegion\Sockets\Contracts\BroadcasterInterface;
use MonkeysLegion\Http\Response;

class DocumentController
{
    public function __construct(
        private BroadcasterInterface $broadcaster
    ) {}

    public function markAsResolved(int $docId): Response
    {
        // ... DB update logic ...

        // Emit an event to everyone currently viewing the document (Presence channel)
        $this->broadcaster
            ->privateChannel("presence:doc-{$docId}")
            ->emit('document_resolved', [
                'doc_id' => $docId,
                'resolved_by' => 'User 42'
            ]);

        return new Response(200, "Document Resolved");
    }
}
```

---

## 5. The Client-Side Implementation (JavaScript)

Include the `monkeys-sockets.js` file published during installation in your HTML, or bundle it.

```html
<script src="/js/vendor/monkeys-sockets.js"></script>
```

Here's how your frontend interacts with the sophisticated backend we just built:

```javascript
// 1. Initialize the connection
const socket = new MonkeysSocket('wss://monkeyscollab.com/ws?token=YOUR_JWT_TOKEN');

socket.connect();

// 2. Listen for global connection events
socket.on('connect', () => {
    console.log('Connected to MonkeysCollab!');
    
    // Join the team chat (Private)
    socket.send(JSON.stringify({ 
        action: 'join_team', 
        team_id: 12 
    }));
    
    // Start viewing a document (Presence)
    socket.send(JSON.stringify({ 
        action: 'view_document', 
        doc_id: 100 
    }));
});

// 3. Listen for application events (routed by the Formatter)

// Incoming chat messages
socket.on('new_chat_message', (data) => {
    console.log(`User ${data.from_id} says: ${data.text}`);
});

// Presence Events (Built-in to RoomManager)
socket.on('presence:joined', (data) => {
    console.log(`${data.member.id} just opened the document!`);
});

socket.on('presence:left', (data) => {
    console.log(`User ${data.member_id} closed the document.`);
});

// Initial snapshot of who is here
socket.on('document_viewers', (members) => {
    console.log('People currently here:', members);
});

// Event from the HTTP Controller broadcast
socket.on('document_resolved', (data) => {
    alert(`Document ${data.doc_id} was just resolved by ${data.resolved_by}!`);
});
```

---

## Summary of the Full Flow

1. **Client (JS)** connects using `monkeys-sockets.js`.
2. **HandshakeNegotiator** intercepts the HTTP Upgrade request, validates the JWT, and enforces rate limits.
3. **Driver** (`Swoole` or `Stream`) upgrades the connection and passes incoming frames to the `FrameProcessor`.
4. **WebSocketServer** intercepts custom `action` commands mapped in the bootstrapper.
5. **AuthorizerPipeline** checks permissions when the client attempts to join `team-12` or `doc-100`.
6. **RoomManager** updates the `ConnectionRegistry` and automatically fires `presence:joined` to other users viewing the document.
7. **HTTP Application** finishes a task and uses `BroadcasterInterface` (via Redis) to push a message.
8. **RedisSubscriber** running in the socket worker loop picks up the broadcast and dispatches it seamlessly to the connected WebSockets.

You are now ready to build production-scale real-time tracking, chat, and collaborative platforms with MonkeysLegion Sockets!
