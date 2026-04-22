/**
 * 🐒 MonkeysLegion Sockets Client
 * Zero-dependency, high-performance WebSocket client.
 */
(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.MonkeysSocket = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Internal Event Emitter
     */
    class EventEmitter {
        constructor() {
            this.events = {};
        }

        /**
         * Subscribe to an event
         */
        on(event, callback) {
            if (!this.events[event]) this.events[event] = [];
            this.events[event].push(callback);
            return () => this.off(event, callback);
        }

        /**
         * Unsubscribe from an event
         */
        off(event, callback) {
            if (!this.events[event]) return;
            this.events[event] = this.events[event].filter(cb => cb !== callback);
        }

        /**
         * Low-level: Dispatch event to local listeners
         */
        _dispatch(event, ...args) {
            if (this.events[event]) {
                this.events[event].forEach(cb => cb(...args));
            }
            // Also notify wildcard listeners if implemented
            if (this.events['*']) {
                this.events['*'].forEach(cb => cb(event, ...args));
            }
        }
    }

    /**
     * MonkeysSocket Client
     */
    class MonkeysSocket extends EventEmitter {
        constructor(url, options = {}) {
            super();
            this.url = url;
            this.options = {
                autoConnect: true,
                reconnect: true,
                reconnectAttempts: Infinity,
                reconnectDelay: 1000,
                maxReconnectDelay: 30000,
                randomizationFactor: 0.5,
                timeout: 20000,
                ...options
            };

            this.socket = null;
            this.reconnectCount = 0;
            this.connected = false;
            this.queue = [];

            if (this.options.autoConnect) {
                this.connect();
            }
        }

        /**
         * Establish connection to the server
         */
        connect() {
            if (this.socket && this.socket.readyState === 1) return;

            try {
                // Pass protocols if defined, and options for Node.js environments (like headers/origin)
                const protocols = this.options.protocols || undefined;
                
                this.socket = new WebSocket(this.url, protocols, this.options.socketOptions);
                this.socket.binaryType = 'arraybuffer';

                this.socket.onopen = (e) => this._onOpen(e);
                this.socket.onmessage = (e) => this._onMessage(e);
                this.socket.onerror = (e) => this._onError(e);
                this.socket.onclose = (e) => this._onClose(e);
            } catch (err) {
                this._dispatch('error', err);
                this._handleReconnect();
            }
        }

        /**
         * Send an event to the server
         * @param {string} event 
         * @param {any} payload 
         */
        emit(event, payload) {
            const data = JSON.stringify({ event, payload });

            if (this.connected && this.socket.readyState === 1) {
                this.socket.send(data);
            } else {
                this.queue.push(data);
            }
        }

        _onOpen(event) {
            this.connected = true;
            this.reconnectCount = 0;
            this._dispatch('connect', event);
            this._flushQueue();
        }

        _onMessage(event) {
            try {
                let rawData = event.data;

                // Handle binary data types (Node Buffer or Browser ArrayBuffer)
                if (typeof rawData !== 'string') {
                    if (typeof TextDecoder !== 'undefined') {
                        rawData = new TextDecoder().decode(rawData);
                    } else if (typeof Buffer !== 'undefined' && Buffer.isBuffer(rawData)) {
                        rawData = rawData.toString('utf8');
                    } else if (rawData instanceof ArrayBuffer) {
                        rawData = String.fromCharCode.apply(null, new Uint8Array(rawData));
                    }
                }

                const data = JSON.parse(rawData);
                
                // 1. Dispatch global 'message' event
                this._dispatch('message', data);
                
                // 2. Dispatch specific event if present
                if (data.event) {
                    this._dispatch(data.event, data.payload);
                }
            } catch (err) {
                this._dispatch('error', new Error('Failed to parse message: ' + err.message));
            }
        }

        _onError(event) {
            this._dispatch('error', event);
        }

        _onClose(event) {
            this.connected = false;
            this._dispatch('disconnect', event);
            
            if (event.code !== 1000 && event.code !== 1001) {
                this._handleReconnect();
            }
        }

        _handleReconnect() {
            if (!this.options.reconnect || this.reconnectCount >= this.options.reconnectAttempts) {
                return;
            }

            const delay = this._getReconnectDelay();
            this.reconnectCount++;

            setTimeout(() => {
                this._dispatch('reconnecting', this.reconnectCount);
                this.connect();
            }, delay);
        }

        _getReconnectDelay() {
            let delay = this.options.reconnectDelay * Math.pow(2, this.reconnectCount);
            delay = Math.min(delay, this.options.maxReconnectDelay);

            if (this.options.randomizationFactor) {
                const jitter = (Math.random() * 2 - 1) * this.options.randomizationFactor * delay;
                delay += jitter;
            }

            return delay;
        }

        _flushQueue() {
            while (this.queue.length > 0 && this.connected) {
                this.socket.send(this.queue.shift());
            }
        }

        disconnect() {
            if (this.socket) {
                this.socket.close(1000, 'Handled by user');
            }
        }
    }

    return MonkeysSocket;
}));
