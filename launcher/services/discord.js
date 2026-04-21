'use strict';

// Lightweight Discord Rich Presence IPC client.
//
// We don't pull in the `discord-rpc` npm package — Discord's local IPC protocol
// is simple enough to implement directly and it keeps our dependency surface
// small. If Discord isn't running, or the socket isn't reachable, every method
// here fails silently so the launcher keeps working normally.
//
// Protocol summary (from https://discord.com/developers/docs/topics/rpc):
//   Frame = 4B op (LE u32) + 4B len (LE u32) + UTF-8 JSON payload (len bytes)
//   Ops   = 0 HANDSHAKE, 1 FRAME, 2 CLOSE, 3 PING, 4 PONG
//   Sockets:
//     Windows  \\?\pipe\discord-ipc-<0..9>
//     POSIX    $XDG_RUNTIME_DIR/discord-ipc-<0..9>
//              $TMPDIR/discord-ipc-<0..9>  (fallback)
//              /tmp/discord-ipc-<0..9>      (last resort)

const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

const OP_HANDSHAKE = 0;
const OP_FRAME = 1;
// const OP_CLOSE = 2;
// const OP_PING = 3;
// const OP_PONG = 4;

function candidatePaths() {
  if (process.platform === 'win32') {
    return [0, 1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) => `\\\\?\\pipe\\discord-ipc-${n}`);
  }
  const roots = [];
  if (process.env.XDG_RUNTIME_DIR) roots.push(process.env.XDG_RUNTIME_DIR);
  if (process.env.TMPDIR) roots.push(process.env.TMPDIR);
  roots.push(os.tmpdir());
  roots.push('/tmp');
  // Deduplicate while preserving order.
  const seen = new Set();
  const uniqueRoots = [];
  for (const r of roots) {
    if (r && !seen.has(r)) {
      seen.add(r);
      uniqueRoots.push(r);
    }
  }
  const out = [];
  for (const r of uniqueRoots) {
    for (let n = 0; n < 10; n++) {
      out.push(path.join(r, `discord-ipc-${n}`));
    }
  }
  return out;
}

function tryConnect(socketPath) {
  return new Promise((resolve) => {
    let settled = false;
    const sock = net.createConnection(socketPath);
    const done = (ok) => {
      if (settled) return;
      settled = true;
      if (!ok) {
        try { sock.destroy(); } catch { /* ignore */ }
        resolve(null);
      } else {
        resolve(sock);
      }
    };
    sock.once('connect', () => done(true));
    sock.once('error', () => done(false));
    // Safety timeout: Discord sockets connect in <50ms. If it doesn't, give up.
    setTimeout(() => done(false), 300);
  });
}

async function connectAny() {
  for (const p of candidatePaths()) {
    const sock = await tryConnect(p);
    if (sock) return sock;
  }
  return null;
}

function encodeFrame(op, payloadObj) {
  const json = Buffer.from(JSON.stringify(payloadObj), 'utf8');
  const header = Buffer.alloc(8);
  header.writeUInt32LE(op, 0);
  header.writeUInt32LE(json.length, 4);
  return Buffer.concat([header, json]);
}

function createRpc() {
  /** @type {net.Socket | null} */
  let sock = null;
  let connectInFlight = null;
  let clientId = '';
  let closed = false;
  /** @type {any | null} */
  let lastActivity = null;

  async function ensureConnected() {
    if (sock) return sock;
    if (connectInFlight) return connectInFlight;
    connectInFlight = (async () => {
      const s = await connectAny();
      if (!s) return null;
      s.once('close', () => {
        if (sock === s) sock = null;
      });
      s.on('error', () => {
        // Swallow — we never want Discord to crash the launcher.
        if (sock === s) {
          try { s.destroy(); } catch { /* ignore */ }
          sock = null;
        }
      });
      // Discord sends data back (READY, DISPATCH, etc.). We don't need to
      // interpret it for SET_ACTIVITY, just drain the socket so it doesn't
      // back up.
      s.on('data', () => {});
      sock = s;
      return s;
    })();
    const result = await connectInFlight;
    connectInFlight = null;
    return result;
  }

  async function sendFrame(op, payload) {
    const s = await ensureConnected();
    if (!s) return false;
    try {
      s.write(encodeFrame(op, payload));
      return true;
    } catch {
      return false;
    }
  }

  async function start(id) {
    if (closed) return false;
    const safeId = String(id || '').trim();
    if (!safeId || !/^\d{10,}$/.test(safeId)) {
      // Discord application IDs are 18+ digit snowflakes. Anything else =
      // treat as "RPC disabled" (classic mode when XYNO_DISCORD_APP_ID env
      // isn't configured yet).
      return false;
    }
    clientId = safeId;
    return await sendFrame(OP_HANDSHAKE, { v: 1, client_id: safeId });
  }

  /**
   * Send a SET_ACTIVITY command.
   * activity = { details, state, startTimestamp?, largeImageKey?, smallImageKey?, buttons? }
   */
  async function setActivity(activity) {
    if (closed || !clientId) return false;
    lastActivity = activity;
    const a = activity && typeof activity === 'object' ? activity : {};
    const payload = {
      cmd: 'SET_ACTIVITY',
      args: {
        pid: process.pid,
        activity: {
          details: typeof a.details === 'string' ? a.details.slice(0, 128) : undefined,
          state: typeof a.state === 'string' ? a.state.slice(0, 128) : undefined,
          timestamps: a.startTimestamp ? { start: Math.floor(a.startTimestamp / 1000) } : undefined,
          assets:
            a.largeImageKey || a.smallImageKey
              ? {
                  large_image: a.largeImageKey,
                  large_text: a.largeImageText,
                  small_image: a.smallImageKey,
                  small_text: a.smallImageText,
                }
              : undefined,
          buttons: Array.isArray(a.buttons)
            ? a.buttons
                .filter((b) => b && typeof b.label === 'string' && typeof b.url === 'string')
                .slice(0, 2)
                .map((b) => ({ label: b.label.slice(0, 32), url: b.url.slice(0, 512) }))
            : undefined,
        },
      },
      nonce: crypto.randomUUID(),
    };
    return await sendFrame(OP_FRAME, payload);
  }

  async function clearActivity() {
    if (closed || !clientId) return false;
    lastActivity = null;
    return await sendFrame(OP_FRAME, {
      cmd: 'SET_ACTIVITY',
      args: { pid: process.pid, activity: null },
      nonce: crypto.randomUUID(),
    });
  }

  function disconnect() {
    closed = true;
    if (sock) {
      try { sock.destroy(); } catch { /* ignore */ }
      sock = null;
    }
  }

  return { start, setActivity, clearActivity, disconnect, getLastActivity: () => lastActivity };
}

module.exports = { createRpc };
