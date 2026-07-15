(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory;
  } else {
    factory(root);
  }
})(typeof window !== 'undefined' ? window : this, function createNezhaAppBridge(env, options) {
  options = options || {};

  function inactiveBridge() {
    return {
      active: false,
      init: function () {},
      registerToken: function () { return Promise.resolve(false); }
    };
  }

  if (!env || !env.navigator || env.navigator.userAgent.indexOf('NezhaMerchantApp') === -1) {
    return inactiveBridge();
  }

  var REGISTER_URL = '/restaurant-panel/nezha-alarm-token/register';
  var ORDER_LIST_HINT = '/restaurant-panel/order/list';
  var HEARTBEAT_MS = 5000;
  var retryBaseMs = Number(options.retryBaseMs || 1000);
  var maxAttempts = Number(options.maxAttempts || 5);
  var heartbeatTimer = null;
  var retryTimer = null;
  var registrationPromise = null;
  var rerunRequested = false;
  var failedAttempts = 0;
  var initialized = false;

  function getPlugin() {
    return (env.Capacitor && env.Capacitor.Plugins && env.Capacitor.Plugins.NezhaAlarm) || null;
  }

  function csrfToken() {
    var meta = env.document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function isOnOrderList() {
    return env.location.pathname.indexOf(ORDER_LIST_HINT) !== -1;
  }

  function clearRetry() {
    if (retryTimer !== null) {
      env.clearTimeout(retryTimer);
      retryTimer = null;
    }
  }

  function scheduleRetry() {
    clearRetry();
    if (env.document.visibilityState !== 'visible' || failedAttempts >= maxAttempts) {
      return;
    }
    var delay = Math.min(30000, retryBaseMs * Math.pow(2, Math.max(0, failedAttempts - 1)));
    retryTimer = env.setTimeout(function () {
      retryTimer = null;
      return registerToken();
    }, delay);
  }

  function registerToken() {
    var plugin = getPlugin();
    if (!plugin || !plugin.getToken || env.document.visibilityState !== 'visible') {
      return Promise.resolve(false);
    }
    if (registrationPromise) {
      return registrationPromise;
    }

    registrationPromise = Promise.resolve()
      .then(function () { return plugin.getToken(); })
      .then(function (result) {
        var token = result && result.token;
        if (!token) {
          throw new Error('empty_token');
        }
        var body = new env.URLSearchParams();
        body.set('token', token);
        body.set('platform', 'android');
        return env.fetch(REGISTER_URL, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          credentials: 'same-origin',
          body: body.toString()
        }).then(function (response) {
          if (!response || !response.ok) {
            throw new Error('registration_http_' + (response ? response.status : 'unknown'));
          }
          return token;
        });
      })
      .then(function (token) {
        failedAttempts = 0;
        clearRetry();
        if (plugin.markTokenRegistered) {
          return Promise.resolve(plugin.markTokenRegistered({ token: token }))
            .catch(function () {})
            .then(function () { return true; });
        }
        return true;
      })
      .catch(function () {
        failedAttempts += 1;
        scheduleRetry();
        return false;
      })
      .then(function (result) {
        registrationPromise = null;
        if (rerunRequested && env.document.visibilityState === 'visible') {
          rerunRequested = false;
          return registerToken();
        }
        rerunRequested = false;
        return result;
      }, function () {
        registrationPromise = null;
        if (rerunRequested && env.document.visibilityState === 'visible') {
          rerunRequested = false;
          return registerToken();
        }
        rerunRequested = false;
        return false;
      });

    return registrationPromise;
  }

  function beat() {
    var plugin = getPlugin();
    if (!plugin || !plugin.markViewing) return;
    var viewing = isOnOrderList() && env.document.visibilityState === 'visible';
    plugin.markViewing({ viewing: viewing }).catch(function () {});
    if (viewing && plugin.stopAlarm) {
      plugin.stopAlarm().catch(function () {});
    }
  }

  function startHeartbeat() {
    if (heartbeatTimer !== null) return;
    beat();
    heartbeatTimer = env.setInterval(beat, HEARTBEAT_MS);
  }

  function resetAndRegister() {
    failedAttempts = 0;
    clearRetry();
    if (registrationPromise) {
      rerunRequested = true;
      return registrationPromise;
    }
    return registerToken();
  }

  function bindLifecycleEvents() {
    env.document.addEventListener('visibilitychange', function () {
      if (env.document.visibilityState === 'visible') {
        resetAndRegister();
        beat();
      } else {
        clearRetry();
        var plugin = getPlugin();
        if (plugin && plugin.markViewing) {
          plugin.markViewing({ viewing: false }).catch(function () {});
        }
      }
    });
    if (env.addEventListener) {
      env.addEventListener('nezha-fcm-token-changed', resetAndRegister);
    }
  }

  function bindSetupLink() {
    if (!env.document.getElementById) return;
    var link = env.document.getElementById('nezhaNotificationSetup');
    if (!link || link.getAttribute('data-nezha-bound') === '1') return;
    link.setAttribute('data-nezha-bound', '1');
    if (link.classList) link.classList.remove('d-none');
    link.addEventListener('click', function (event) {
      if (event && event.preventDefault) event.preventDefault();
      var plugin = getPlugin();
      if (plugin && plugin.openSetup) {
        plugin.openSetup().catch(function () {});
      }
    });
  }

  function init() {
    if (initialized) return;
    initialized = true;
    bindLifecycleEvents();
    bindSetupLink();

    if (getPlugin()) {
      resetAndRegister();
      startHeartbeat();
      return;
    }

    var tries = 0;
    var pluginTimer = env.setInterval(function () {
      tries += 1;
      if (getPlugin()) {
        env.clearInterval(pluginTimer);
        resetAndRegister();
        startHeartbeat();
      } else if (tries > 40) {
        env.clearInterval(pluginTimer);
      }
    }, 250);
  }

  var bridge = {
    active: true,
    init: init,
    registerToken: registerToken
  };

  if (options.autoStart !== false) {
    if (env.document.readyState === 'loading') {
      env.document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  return bridge;
});
