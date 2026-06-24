(function () {
  // 仅在「哪吒商家版 App」内激活: 普通浏览器无此 UA / 无 Capacitor → 整段不动作, 零副作用。
  if (typeof navigator === 'undefined' || navigator.userAgent.indexOf('NezhaMerchantApp') === -1) {
    return;
  }

  var REGISTER_URL = '/restaurant-panel/nezha-alarm-token/register';
  var ORDER_LIST_HINT = '/restaurant-panel/order/list';
  var HEARTBEAT_MS = 5000;
  var heartbeatTimer = null;

  function getPlugin() {
    return (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.NezhaAlarm) || null;
  }

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  }

  function isOnOrderList() {
    return location.pathname.indexOf(ORDER_LIST_HINT) !== -1;
  }

  // 上报本机 FCM token → 后端绑定本店(多设备表)。换账号/刷新会覆盖归属。
  function registerToken() {
    var p = getPlugin();
    if (!p || !p.getToken) return;
    p.getToken().then(function (res) {
      var token = res && res.token;
      if (!token) return;
      var body = new URLSearchParams();
      body.set('token', token);
      body.set('platform', 'android');
      fetch(REGISTER_URL, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        credentials: 'same-origin',
        body: body.toString()
      }).catch(function () {});
    }).catch(function () {});
  }

  // 在场感知: 在订单列表页且页面可见 → 上报「正在看」(报警静默); 否则「没在看」。
  function beat() {
    var p = getPlugin();
    if (!p || !p.markViewing) return;
    var viewing = isOnOrderList() && document.visibilityState === 'visible';
    p.markViewing({ viewing: viewing }).catch(function () {});
    // 商家已经在看订单列表 → 顺手停掉正在响的报警(已知晓, 别继续吵)。
    if (viewing && p.stopAlarm) {
      p.stopAlarm().catch(function () {});
    }
  }

  function startHeartbeat() {
    if (heartbeatTimer) return;
    beat();
    heartbeatTimer = setInterval(beat, HEARTBEAT_MS);
  }

  function init() {
    // 等 Capacitor 把原生桥注入到页面
    var tries = 0;
    var t = setInterval(function () {
      tries++;
      if (getPlugin()) {
        clearInterval(t);
        registerToken();
        startHeartbeat();
      } else if (tries > 40) {
        clearInterval(t);
      }
    }, 250);

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
        registerToken();
        beat();
      } else {
        var p = getPlugin();
        if (p && p.markViewing) {
          p.markViewing({ viewing: false }).catch(function () {});
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
