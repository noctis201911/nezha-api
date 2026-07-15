const test = require('node:test');
const assert = require('node:assert/strict');

const createBridge = require('../../public/assets/admin/nezha-app-bridge.js');

function createEnvironment(fetchImpl) {
  const timers = [];
  const pluginCalls = {
    markedRegistered: [],
  };
  const plugin = {
    getToken: async () => ({ token: 'merchant-token-0000000000000001' }),
    markTokenRegistered: async ({ token }) => {
      pluginCalls.markedRegistered.push(token);
    },
    markViewing: async () => {},
    stopAlarm: async () => {},
  };
  const documentListeners = {};
  const windowListeners = {};
  const document = {
    readyState: 'complete',
    visibilityState: 'visible',
    querySelector: () => ({ getAttribute: () => 'csrf-test-token' }),
    getElementById: () => null,
    addEventListener: (name, listener) => {
      documentListeners[name] = listener;
    },
  };
  const env = {
    navigator: { userAgent: 'Mozilla/5.0 NezhaMerchantApp' },
    document,
    location: { pathname: '/restaurant-panel/order/list/pending' },
    Capacitor: { Plugins: { NezhaAlarm: plugin } },
    URLSearchParams,
    fetch: fetchImpl,
    setTimeout: (callback, delay) => {
      timers.push({ callback, delay });
      return timers.length;
    },
    clearTimeout: () => {},
    setInterval: () => 1,
    clearInterval: () => {},
    addEventListener: (name, listener) => {
      windowListeners[name] = listener;
    },
  };

  return { env, plugin, timers, pluginCalls, documentListeners, windowListeners };
}

test('registers the current token and marks it handled only after an HTTP success', async () => {
  const requests = [];
  const harness = createEnvironment(async (url, options) => {
    requests.push({ url, options });
    return { ok: true, status: 200 };
  });
  const bridge = createBridge(harness.env, { autoStart: false });

  assert.equal(await bridge.registerToken(), true);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/restaurant-panel/nezha-alarm-token/register');
  assert.match(requests[0].options.body, /token=merchant-token-0000000000000001/);
  assert.equal(requests[0].options.credentials, 'same-origin');
  assert.deepEqual(harness.pluginCalls.markedRegistered, ['merchant-token-0000000000000001']);
  assert.equal(harness.timers.length, 0);
});

test('retries a failed registration with bounded exponential delays', async () => {
  let attempts = 0;
  const harness = createEnvironment(async () => {
    attempts += 1;
    return attempts < 5
      ? { ok: false, status: 503 }
      : { ok: true, status: 200 };
  });
  const bridge = createBridge(harness.env, {
    autoStart: false,
    retryBaseMs: 1000,
    maxAttempts: 5,
  });

  assert.equal(await bridge.registerToken(), false);
  const observedDelays = [];
  while (harness.timers.length > 0) {
    const timer = harness.timers.shift();
    observedDelays.push(timer.delay);
    await timer.callback();
  }

  assert.equal(attempts, 5);
  assert.deepEqual(observedDelays, [1000, 2000, 4000, 8000]);
  assert.deepEqual(harness.pluginCalls.markedRegistered, ['merchant-token-0000000000000001']);
});

test('is inert outside the merchant App container', async () => {
  let fetchCount = 0;
  const harness = createEnvironment(async () => {
    fetchCount += 1;
    return { ok: true, status: 200 };
  });
  harness.env.navigator.userAgent = 'Mozilla/5.0 Chrome';

  const bridge = createBridge(harness.env, { autoStart: false });

  assert.equal(bridge.active, false);
  assert.equal(await bridge.registerToken(), false);
  assert.equal(fetchCount, 0);
});

test('re-registers the refreshed token when it changes during an in-flight request', async () => {
  let currentToken = 'merchant-token-before-refresh';
  let resolveFirstRequest;
  const registeredBodies = [];
  const harness = createEnvironment(async (_url, options) => {
    registeredBodies.push(options.body);
    if (registeredBodies.length === 1) {
      return new Promise((resolve) => {
        resolveFirstRequest = resolve;
      });
    }
    return { ok: true, status: 200 };
  });
  harness.plugin.getToken = async () => ({ token: currentToken });
  const bridge = createBridge(harness.env, { autoStart: false });

  bridge.init();
  await new Promise((resolve) => setImmediate(resolve));
  currentToken = 'merchant-token-after-refresh';
  harness.windowListeners['nezha-fcm-token-changed']();
  resolveFirstRequest({ ok: true, status: 200 });
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));

  assert.equal(registeredBodies.length, 2);
  assert.match(registeredBodies[0], /merchant-token-before-refresh/);
  assert.match(registeredBodies[1], /merchant-token-after-refresh/);
  assert.deepEqual(harness.pluginCalls.markedRegistered, [
    'merchant-token-before-refresh',
    'merchant-token-after-refresh',
  ]);
});
