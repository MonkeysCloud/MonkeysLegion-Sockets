import { describe, it, expect, vi, beforeEach } from 'vitest';
import MonkeysSocket from '../src/monkeys-sockets.js';

describe('MonkeysSocket Core', () => {
    let mockWebSocket;

    beforeEach(() => {
        // Simple WebSocket Mock
        mockWebSocket = {
            send: vi.fn(),
            close: vi.fn(),
            readyState: 0,
            onopen: null,
            onmessage: null,
            onerror: null,
            onclose: null
        };

        vi.stubGlobal('WebSocket', vi.fn(() => mockWebSocket));
    });

    it('should initialize with correct default options', () => {
        const client = new MonkeysSocket('ws://localhost:9000', { autoConnect: false });
        expect(client.url).toBe('ws://localhost:9000');
        expect(client.options.reconnect).toBe(true);
        expect(client.connected).toBe(false);
    });

    it('should transition to connected state on open', () => {
        const client = new MonkeysSocket('ws://localhost:9000');
        
        // Simulate open
        mockWebSocket.readyState = 1;
        mockWebSocket.onopen({ type: 'open' });

        expect(client.connected).toBe(true);
    });

    it('should queue messages when disconnected and flush when connected', () => {
        const client = new MonkeysSocket('ws://localhost:9000', { autoConnect: false });
        
        // Emit while disconnected
        client.emit('test-event', { foo: 'bar' });
        expect(client.queue.length).toBe(1);
        expect(mockWebSocket.send).not.toHaveBeenCalled();

        // Connect
        client.connect();
        mockWebSocket.readyState = 1;
        mockWebSocket.onopen({ type: 'open' });

        expect(client.queue.length).toBe(0);
        expect(mockWebSocket.send).toHaveBeenCalledWith(JSON.stringify({
            event: 'test-event',
            payload: { foo: 'bar' }
        }));
    });

    it('should trigger custom events from server messages', () => {
        const client = new MonkeysSocket('ws://localhost:9000');
        const spy = vi.fn();
        
        client.on('server-event', spy);

        // Simulate incoming grouped message
        mockWebSocket.onmessage({
            data: JSON.stringify({
                event: 'server-event',
                payload: { hello: 'world' }
            })
        });

        expect(spy).toHaveBeenCalledWith({ hello: 'world' });
    });

    it('should handle reconnection on unexpected close', async () => {
        vi.useFakeTimers();
        
        const client = new MonkeysSocket('ws://localhost:9000', {
            reconnectDelay: 100,
            randomizationFactor: 0 // Disable jitter for deterministic test
        });

        // Trigger close
        mockWebSocket.onclose({ code: 1006, reason: 'Abnormal Closure' });
        expect(client.connected).toBe(false);

        // Advance time to trigger reconnect
        vi.runAllTimers();

        // Should have attempted to connect again
        expect(vi.mocked(WebSocket).mock.calls.length).toBe(2);
        
        vi.useRealTimers();
    });
});
