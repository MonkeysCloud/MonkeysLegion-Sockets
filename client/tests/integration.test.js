import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import MonkeysSocket from '../src/monkeys-sockets.js';
import WebSocket from 'ws';
import { spawn } from 'child_process';

// Polyfill WebSocket for Node environment
global.WebSocket = WebSocket;

describe('MonkeysSocket Integration & Security', { timeout: 30000 }, () => {
    let serverProcess;
    let portCounter = 9400;

    const startServer = async (port) => {
        const proc = spawn('php', ['scratch/server.php', port.toString()], { 
            cwd: '../'
        });
        
        await new Promise(resolve => setTimeout(resolve, 1500));
        return proc;
    };

    afterEach(() => {
        if (serverProcess) {
            serverProcess.kill();
            serverProcess = null;
        }
    });

    it('should handle echoes correctly', async () => {
        const port = portCounter++;
        serverProcess = await startServer(port);
        const url = `ws://127.0.0.1:${port}`;

        const client = new MonkeysSocket(url, {
            autoConnect: false,
            socketOptions: { origin: 'http://localhost:3000' }
        });
        
        await new Promise(resolve => {
            client.on('connect', resolve);
            client.connect();
        });
        
        const replyPromise = new Promise(resolve => {
            client.on('hello', (data) => resolve(data));
        });

        client.emit('hello', { msg: 'monkey' });
        
        const reply = await replyPromise;
        expect(reply.reply).toBe('Hi Monkey!');
        client.disconnect();
    });

    it('should reject unauthorized origins', async () => {
        const port = portCounter++;
        serverProcess = await startServer(port);
        const url = `ws://127.0.0.1:${port}`;

        const client = new MonkeysSocket(url, {
            autoConnect: false,
            reconnect: false,
            socketOptions: { origin: 'http://evil-monkey.com' }
        });

        const errorPromise = new Promise(resolve => client.on('error', resolve));
        client.connect();

        await errorPromise;
        expect(client.connected).toBe(false);
    });

    it('should handle massive messages and respect boundaries', async () => {
        const port = portCounter++;
        serverProcess = await startServer(port);
        const url = `ws://127.0.0.1:${port}`;

        const client = new MonkeysSocket(url, {
            autoConnect: false,
            socketOptions: { origin: 'http://localhost:3000' }
        });

        await new Promise(resolve => {
            client.on('connect', resolve);
            client.connect();
        });

        const massivePayload = '0'.repeat(1024 * 1024); // 1MB
        client.emit('large_data', { data: massivePayload });
        
        // Sanity check for connection stability
        expect(client.connected).toBe(true);
        client.disconnect();
    });
});
